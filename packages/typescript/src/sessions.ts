import { inspect } from 'node:util';
import { ApiContext, paginate } from './api-context.js';
import { Crypto, type SessionKeyMaterial } from './crypto.js';
import {
  ConfigurationException,
  InvoiceRejectedException,
  SessionException,
  TimeoutException,
  ValidationException,
} from './errors.js';
import { FormCodes, type FormCode } from './identifiers.js';
import { XmlValidator } from './invoice/xml-validator.js';
import { isReferenceNumber, refSeg } from './refs.js';
import type { PublicKeyProvider } from './public-keys.js';
import type {
  CommonSessionStatus,
  SessionInvoice,
  SessionInvoicesPage,
  SessionKind,
  SessionListItem,
  SessionListPage,
  SessionStatus,
} from './types.js';

/** KSeF error: session temporarily unavailable (open a new one). */
export const ERROR_CODE_SESSION_UNAVAILABLE = 21184;

/** Open online (interactive) session. Key material stays in memory and is never serialised. */
export class OnlineSession {
  readonly referenceNumber: string;
  readonly validUntil: Date;
  readonly formCode: FormCode;
  readonly #material: SessionKeyMaterial;

  constructor(init: { referenceNumber: string; validUntil: Date; formCode: FormCode; material: SessionKeyMaterial }) {
    this.referenceNumber = init.referenceNumber;
    this.validUntil = init.validUntil;
    this.formCode = init.formCode;
    this.#material = init.material;
  }

  /** AES-256-CBC encrypts and describes an invoice for `POST .../invoices`. */
  encryptInvoice(bytes: Uint8Array): ReturnType<typeof Crypto.encryptInvoice> {
    return Crypto.encryptInvoice(bytes, this.#material);
  }

  get isExpired(): boolean {
    return Date.now() >= this.validUntil.getTime();
  }

  toJSON(): Record<string, unknown> {
    return { referenceNumber: this.referenceNumber, validUntil: this.validUntil.toISOString(), formCode: this.formCode };
  }

  [inspect.custom](): string {
    return `OnlineSession { referenceNumber: '${this.referenceNumber}', key: [REDACTED] }`;
  }
}

export interface SendOptions {
  /**
   * Pre-flight validation before anything is encrypted or sent.
   * - `undefined` / `true` (default): validate against the bundled FA(3) XSD (and KSeF technical rules) with
   *   {@link XmlValidator}; failure throws `InvoiceValidationException` (an `XmlValidationException`).
   *   For form codes other than FA(3) the default is to skip (no bundled schema); `true` then throws a
   *   `ConfigurationException`.
   * - `false`: no validation (KSeF still validates server-side).
   * - function: custom validator; throw to abort. The XML text is passed.
   */
  readonly validate?: boolean | ((xml: string) => void | Promise<void>);
  readonly offlineMode?: boolean;
  readonly hashOfCorrectedInvoice?: string;
  readonly signal?: AbortSignal;
}

export interface SentInvoice {
  readonly referenceNumber: string;
  readonly invoiceHash: string;
  readonly invoiceSize: number;
  readonly sessionReferenceNumber: string;
}

export interface AwaitOptions {
  /** Overall deadline. Default 120 s. */
  readonly timeoutMs?: number;
  /** First poll delay (grows x1.5 up to 5 s). Default 1000 ms. */
  readonly pollIntervalMs?: number;
  readonly signal?: AbortSignal;
}

/** Rejects DOCTYPE / processing instructions (KSeF rejects them too). XML declaration is fine. */
export function assertSafeInvoiceXml(xml: string): void {
  if (/<!DOCTYPE/i.test(xml) || /<!ENTITY/i.test(xml)) {
    throw new ConfigurationException('Invoice XML must not contain a DOCTYPE or entity declarations');
  }
  const pi = /<\?(?!xml\s)[^>]*\?>/i.exec(xml);
  if (pi !== null) throw new ConfigurationException('Invoice XML must not contain processing instructions');
}

function continuation(token: string | undefined): Record<string, string> {
  return token !== undefined ? { 'x-continuation-token': token } : {};
}

/** Online session lifecycle: open, send, close, status, failed invoices, reconcile, await. */
export class InvoiceSessionService {
  readonly #ctx: ApiContext;
  readonly #keys: PublicKeyProvider;
  #validator: XmlValidator | undefined;

  constructor(ctx: ApiContext, keys: PublicKeyProvider, validator?: XmlValidator) {
    this.#ctx = ctx;
    this.#keys = keys;
    this.#validator = validator;
  }

  /** `POST /sessions/online`. Never auto-retried after an ambiguous failure (429 is retried). */
  async openOnline(options: { formCode?: FormCode; signal?: AbortSignal } = {}): Promise<OnlineSession> {
    const formCode = options.formCode ?? FormCodes.FA3;
    const material = Crypto.generateSessionKey();
    const res = await this.#keys.withKey(
      'SymmetricKeyEncryption',
      async (pk) =>
        (
          await this.#ctx.call<{ referenceNumber: string; validUntil: string }>({
            method: 'POST',
            path: '/sessions/online',
            json: {
              formCode,
              encryption: {
                encryptedSymmetricKey: Buffer.from(Crypto.rsaOaepSha256Encrypt(pk.publicKey, material.key)).toString('base64'),
                initializationVector: Buffer.from(material.iv).toString('base64'),
                publicKeyId: pk.publicKeyId,
              },
            },
            idempotent: false,
            unknownOutcome: { operation: 'openOnlineSession' },
            validateResponse: (d) =>
              isRecord(d) && isReferenceNumber(d['referenceNumber']) && typeof d['validUntil'] === 'string' && !Number.isNaN(Date.parse(d['validUntil'])),
            signal: options.signal,
          })
        ).data,
      options.signal,
    );
    return new OnlineSession({
      referenceNumber: res.referenceNumber,
      validUntil: new Date(res.validUntil),
      formCode,
      material,
    });
  }

  /**
   * `POST /sessions/online/{ref}/invoices`. HTTP 202 means "received", NOT "accepted".
   * An ambiguous failure throws {@link UnknownOutcomeException} (with `invoiceHash`); reconcile before resending.
   */
  async sendInvoice(session: OnlineSession, xml: string | Uint8Array, options: SendOptions = {}): Promise<SentInvoice> {
    await this.validateInvoice(xml, {
      formCode: session.formCode,
      ...(options.validate !== undefined ? { validate: options.validate } : {}),
    });
    const bytes = typeof xml === 'string' ? new TextEncoder().encode(xml) : xml;
    const body = session.encryptInvoice(bytes);
    try {
      const res = await this.#ctx.call<{ referenceNumber: string }>({
        method: 'POST',
        path: `/sessions/online/${refSeg(session.referenceNumber)}/invoices`,
        json: {
          ...body,
          ...(options.offlineMode !== undefined ? { offlineMode: options.offlineMode } : {}),
          ...(options.hashOfCorrectedInvoice !== undefined ? { hashOfCorrectedInvoice: options.hashOfCorrectedInvoice } : {}),
        },
        idempotent: false,
        unknownOutcome: {
          operation: 'sendInvoice',
          invoiceHash: body.invoiceHash,
          sessionReferenceNumber: session.referenceNumber,
        },
        validateResponse: (d) => isRecord(d) && isReferenceNumber(d['referenceNumber']),
        signal: options.signal,
      });
      return {
        referenceNumber: res.data.referenceNumber,
        invoiceHash: body.invoiceHash,
        invoiceSize: body.invoiceSize,
        sessionReferenceNumber: session.referenceNumber,
      };
    } catch (e) {
      if (e instanceof ValidationException && e.ksefCodes.includes(ERROR_CODE_SESSION_UNAVAILABLE)) {
        throw new SessionException('Session temporarily unavailable (21184); open a new session', {
          cause: e,
          statusCode: e.statusCode,
          ksefCodes: e.ksefCodes,
          sessionReferenceNumber: session.referenceNumber,
        });
      }
      throw e;
    }
  }

  /**
   * Pre-flight checks without any network call: non-empty, UTF-8, no DOCTYPE/PI, then the validation selected by
   * `validate` (default: bundled FA(3) XSD for FA(3) form codes). Throws `InvoiceValidationException` or
   * `ConfigurationException`. `sender.submit` calls this BEFORE opening a session so invalid XML costs no requests.
   */
  async validateInvoice(
    xml: string | Uint8Array,
    options: { formCode?: FormCode; validate?: SendOptions['validate'] } = {},
  ): Promise<void> {
    if (xml.length === 0) throw new ConfigurationException('Invoice XML is empty');
    const text = typeof xml === 'string' ? xml : new TextDecoder('utf-8', { fatal: true }).decode(xml);
    assertSafeInvoiceXml(text);
    const mode = options.validate;
    if (mode === false) return;
    if (typeof mode === 'function') {
      await mode(text);
      return;
    }
    const formCode = options.formCode ?? FormCodes.FA3;
    const isFa3 = formCode.systemCode === FormCodes.FA3.systemCode && formCode.schemaVersion === FormCodes.FA3.schemaVersion;
    if (!isFa3) {
      if (mode === true) throw new ConfigurationException('No bundled XSD for this form code; pass a custom validator or validate: false');
      return;
    }
    this.#validator ??= new XmlValidator();
    await this.#validator.assertValid(xml); // exact input (bytes keep BOM/encoding checks)
  }

  /** Abortable pause honouring the client-level signal. */
  pause(ms: number, signal?: AbortSignal): Promise<void> {
    return this.#ctx.http.pause(ms, signal);
  }

  /** `POST /sessions/online/{ref}/close` (starts generation of the session UPO). */
  async close(session: OnlineSession | string, signal?: AbortSignal): Promise<void> {
    const ref = typeof session === 'string' ? session : session.referenceNumber;
    await this.#ctx.call({
      method: 'POST',
      path: `/sessions/online/${refSeg(ref)}/close`,
      responseType: 'void',
      idempotent: false,
      unknownOutcome: { operation: 'closeOnlineSession', sessionReferenceNumber: ref },
      signal,
    });
  }

  /** `GET /sessions/{ref}`. */
  async getStatus(sessionReferenceNumber: string, signal?: AbortSignal): Promise<SessionStatus> {
    return (
      await this.#ctx.call<SessionStatus>({
        method: 'GET',
        path: `/sessions/${refSeg(sessionReferenceNumber)}`,
        signal,
      })
    ).data;
  }

  /** `GET /sessions/{ref}/invoices/{invoiceRef}`. */
  async getInvoiceStatus(sessionReferenceNumber: string, invoiceReferenceNumber: string, signal?: AbortSignal): Promise<SessionInvoice> {
    return (
      await this.#ctx.call<SessionInvoice>({
        method: 'GET',
        path: `/sessions/${refSeg(sessionReferenceNumber)}/invoices/${refSeg(invoiceReferenceNumber)}`,
        signal,
      })
    ).data;
  }

  /** `GET /sessions/{ref}/invoices` (one page). */
  async listSessionInvoices(
    sessionReferenceNumber: string,
    options: { pageSize?: number; continuationToken?: string; signal?: AbortSignal } = {},
  ): Promise<SessionInvoicesPage> {
    return (
      await this.#ctx.call<SessionInvoicesPage>({
        method: 'GET',
        path: `/sessions/${refSeg(sessionReferenceNumber)}/invoices`,
        query: { pageSize: options.pageSize },
        headers: continuation(options.continuationToken),
        signal: options.signal,
      })
    ).data;
  }

  /** Lazily iterates all invoices of a session. */
  iterateSessionInvoices(
    sessionReferenceNumber: string,
    options: { pageSize?: number; signal?: AbortSignal; maxPages?: number } = {},
  ): AsyncGenerator<SessionInvoice, void, undefined> {
    return paginate(
      (ct) => this.listSessionInvoices(sessionReferenceNumber, { ...options, ...(ct !== undefined ? { continuationToken: ct } : {}) }),
      (p) => p.invoices, options.maxPages !== undefined ? { maxPages: options.maxPages } : {},
    );
  }

  /** `GET /sessions/{ref}/invoices/failed` (one page). */
  async listFailedInvoices(
    sessionReferenceNumber: string,
    options: { pageSize?: number; continuationToken?: string; signal?: AbortSignal } = {},
  ): Promise<SessionInvoicesPage> {
    return (
      await this.#ctx.call<SessionInvoicesPage>({
        method: 'GET',
        path: `/sessions/${refSeg(sessionReferenceNumber)}/invoices/failed`,
        query: { pageSize: options.pageSize },
        headers: continuation(options.continuationToken),
        signal: options.signal,
      })
    ).data;
  }

  iterateFailedInvoices(
    sessionReferenceNumber: string,
    options: { pageSize?: number; signal?: AbortSignal; maxPages?: number } = {},
  ): AsyncGenerator<SessionInvoice, void, undefined> {
    return paginate(
      (ct) => this.listFailedInvoices(sessionReferenceNumber, { ...options, ...(ct !== undefined ? { continuationToken: ct } : {}) }),
      (p) => p.invoices, options.maxPages !== undefined ? { maxPages: options.maxPages } : {},
    );
  }

  /** `GET /sessions` (one page); `sessionType` is required by the API. */
  async listSessions(options: {
    sessionType: SessionKind;
    statuses?: readonly CommonSessionStatus[];
    referenceNumber?: string;
    dateCreatedFrom?: Date;
    dateCreatedTo?: Date;
    pageSize?: number;
    continuationToken?: string;
    signal?: AbortSignal;
  }): Promise<SessionListPage> {
    return (
      await this.#ctx.call<SessionListPage>({
        method: 'GET',
        path: '/sessions',
        query: {
          sessionType: options.sessionType,
          statuses: options.statuses === undefined ? undefined : [...options.statuses],
          referenceNumber: options.referenceNumber,
          dateCreatedFrom: options.dateCreatedFrom?.toISOString(),
          dateCreatedTo: options.dateCreatedTo?.toISOString(),
          pageSize: options.pageSize,
        },
        headers: continuation(options.continuationToken),
        signal: options.signal,
      })
    ).data;
  }

  iterateSessions(
    options: Omit<Parameters<InvoiceSessionService['listSessions']>[0], 'continuationToken'> & { maxPages?: number },
  ): AsyncGenerator<SessionListItem, void, undefined> {
    return paginate(
      (ct) => this.listSessions({ ...options, ...(ct !== undefined ? { continuationToken: ct } : {}) }),
      (p) => p.sessions, options.maxPages !== undefined ? { maxPages: options.maxPages } : {},
    );
  }

  /**
   * Looks for an invoice with this `invoiceHash` in the session. Use after an {@link UnknownOutcomeException}:
   * resend only when this returns `undefined` (and the reconcile window has passed).
   */
  async reconcile(session: OnlineSession | string, invoiceHash: string, signal?: AbortSignal): Promise<SessionInvoice | undefined> {
    const ref = typeof session === 'string' ? session : session.referenceNumber;
    for await (const inv of this.iterateSessionInvoices(ref, { pageSize: 100, ...(signal !== undefined ? { signal } : {}) })) {
      if (inv.invoiceHash === invoiceHash) return inv;
    }
    return undefined;
  }

  /**
   * Polls the invoice until a terminal status. 200 = accepted (returns, has `ksefNumber`);
   * >= 400 = {@link InvoiceRejectedException}. 100/150 keep polling.
   */
  async awaitInvoice(sessionReferenceNumber: string, invoiceReferenceNumber: string, options: AwaitOptions = {}): Promise<SessionInvoice> {
    const deadline = Date.now() + (options.timeoutMs ?? 120_000);
    let delay = options.pollIntervalMs ?? 1000;
    for (;;) {
      const inv = await this.getInvoiceStatus(sessionReferenceNumber, invoiceReferenceNumber, options.signal);
      const code = inv.status.code;
      if (code === 200) return inv;
      if (code >= 400) {
        throw new InvoiceRejectedException({
          statusCode: code,
          description: inv.status.description,
          details: inv.status.details ?? [],
          extensions: inv.status.extensions ?? null,
          invoiceReferenceNumber,
          sessionReferenceNumber,
        });
      }
      if (Date.now() + delay > deadline) {
        throw new TimeoutException(`Invoice ${invoiceReferenceNumber} still in status ${String(code)} after the deadline`);
      }
      await this.#ctx.http.pause(delay, options.signal);
      delay = Math.min(Math.round(delay * 1.5), 5000);
    }
  }
}

function isRecord(v: unknown): v is Record<string, unknown> {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}
