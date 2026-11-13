import { ApiContext, DEFAULT_MAX_PAGES } from './api-context.js';
import { Crypto } from './crypto.js';
import { AbortException, PaginationException, ApiException, ConfigurationException, UnknownOutcomeException } from './errors.js';
import { ksefSeg, refSeg } from './refs.js';
import type { FormCode, InvoiceSubjectType } from './identifiers.js';
import {
  InvoiceSessionService,
  type AwaitOptions,
  type OnlineSession,
  type SendOptions,
} from './sessions.js';
import type { InvoiceDateType, InvoiceMetadata, InvoiceMetadataPage, SessionInvoice } from './types.js';

/** A downloaded invoice. */
export interface DownloadedInvoice {
  readonly ksefNumber: string;
  /** Exact bytes returned by KSeF. */
  readonly bytes: Uint8Array;
  /** UTF-8 decoded XML. */
  readonly xml: string;
  /** Value of the `x-ms-meta-hash` header, when present. */
  readonly hashHeader: string | undefined;
  /** `base64(sha256(bytes))`. */
  readonly computedHash: string;
  /** `true` when the header is present and equals the computed hash. */
  readonly hashMatches: boolean | undefined;
  /** `X-System-Warning` header, when present. */
  readonly systemWarning: string | undefined;
}

/** KSeF error 21165: invoice processed (status 200) but not yet available for download. */
export const ERROR_CODE_INVOICE_NOT_YET_AVAILABLE = 21165;

export interface DownloadOptions {
  readonly signal?: AbortSignal;
  /** How long to keep retrying "not yet available" answers (21165 / bare 406). Default 15000 ms. */
  readonly availabilityTimeoutMs?: number;
  /** Delay between attempts. Default 1000 ms. */
  readonly pollIntervalMs?: number;
}

/**
 * `GET /invoices/ksef/{ksefNumber}`.
 * Right after acceptance KSeF may answer HTTP 400 code 21165 ("processed but not yet available") or, once observed, a bare
 * HTTP 406. Both are retried (GET is idempotent) until `availabilityTimeoutMs`; any other error is rethrown at once.
 */
export class InvoiceService {
  readonly #ctx: ApiContext;
  constructor(ctx: ApiContext) {
    this.#ctx = ctx;
  }

  async getByKsefNumber(ksefNumber: string, signalOrOptions?: AbortSignal | DownloadOptions): Promise<DownloadedInvoice> {
    const options: DownloadOptions = signalOrOptions instanceof AbortSignal ? { signal: signalOrOptions } : (signalOrOptions ?? {});
    const interval = options.pollIntervalMs ?? 1000;
    const deadline = Date.now() + (options.availabilityTimeoutMs ?? 15_000);
    for (;;) {
      try {
        return await this.#fetch(ksefNumber, options.signal);
      } catch (e) {
        const notYet =
          e instanceof ApiException && (e.ksefCodes.includes(ERROR_CODE_INVOICE_NOT_YET_AVAILABLE) || e.statusCode === 406);
        if (!notYet || Date.now() + interval >= deadline) throw e;
        await this.#ctx.http.pause(interval, options.signal);
      }
    }
  }

  async #fetch(ksefNumber: string, signal: AbortSignal | undefined): Promise<DownloadedInvoice> {
    const res = await this.#ctx.call<Uint8Array>({
      method: 'GET',
      path: `/invoices/ksef/${ksefSeg(ksefNumber)}`,
      responseType: 'bytes',
      signal,
    });
    const hashHeader = res.headers.get('x-ms-meta-hash') ?? undefined;
    const computedHash = Crypto.sha256Base64(res.data);
    return {
      ksefNumber,
      bytes: res.data,
      xml: new TextDecoder('utf-8').decode(res.data),
      hashHeader,
      computedHash,
      hashMatches: hashHeader === undefined ? undefined : hashHeader === computedHash,
      systemWarning: res.headers.get('x-system-warning') ?? undefined,
    };
  }
}

/** Metadata query filters (OpenAPI `InvoiceQueryFilters`) with `Date` friendly ranges. */
export interface InvoiceQueryFilters {
  readonly subjectType: InvoiceSubjectType;
  readonly dateRange: {
    readonly dateType: InvoiceDateType;
    readonly from: Date | string;
    /** Default: now (UTC). */
    readonly to?: Date | string;
    readonly restrictToPermanentStorageHwmDate?: boolean;
  };
  readonly ksefNumber?: string;
  readonly invoiceNumber?: string;
  readonly amount?: { readonly type: 'Brutto' | 'Netto' | 'Vat'; readonly from?: number; readonly to?: number };
  readonly sellerNip?: string;
  readonly buyerIdentifier?: { readonly type: 'Nip' | 'VatUe' | 'Other' | 'None'; readonly value?: string };
  readonly currencyCodes?: readonly string[];
  readonly invoicingMode?: 'Online' | 'Offline';
  readonly isSelfInvoicing?: boolean;
  readonly formType?: 'FA' | 'PEF' | 'FA_RR';
  readonly invoiceTypes?: readonly string[];
  readonly hasAttachment?: boolean;
}

export interface MetadataQueryOptions {
  /** 10..250. */
  readonly pageSize?: number;
  readonly pageOffset?: number;
  readonly sortOrder?: 'Asc' | 'Desc';
  readonly signal?: AbortSignal;
  /** Page ceiling of `iterateMetadata` (default 10000). */
  readonly maxPages?: number;
}

/** Maximum span of a metadata date range (API 2.7.1+). */
export const MAX_RANGE_DAYS = 100;
const DAY_MS = 86_400_000;

/** Formats an instant as UTC ISO-8601 with explicit offset: `2026-01-03T13:45:00.000+00:00`. */
export function toUtcIso(d: Date | string): string {
  const date = typeof d === 'string' ? new Date(d) : d;
  if (Number.isNaN(date.getTime())) throw new ConfigurationException(`Invalid date: ${String(d)}`);
  return date.toISOString().replace('Z', '+00:00');
}

function toDate(d: Date | string): Date {
  const date = typeof d === 'string' ? new Date(d) : d;
  if (Number.isNaN(date.getTime())) throw new ConfigurationException(`Invalid date: ${String(d)}`);
  return date;
}

/** `POST /invoices/query/metadata` and a lazy iterator. */
export class InvoiceQueryService {
  readonly #ctx: ApiContext;
  constructor(ctx: ApiContext) {
    this.#ctx = ctx;
  }

  #body(filters: InvoiceQueryFilters, from: Date, to: Date | undefined): Record<string, unknown> {
    const { dateRange, ...rest } = filters;
    return {
      ...rest,
      ...(rest.currencyCodes !== undefined ? { currencyCodes: [...rest.currencyCodes] } : {}),
      dateRange: {
        dateType: dateRange.dateType,
        from: toUtcIso(from),
        ...(to !== undefined ? { to: toUtcIso(to) } : {}),
        ...(dateRange.restrictToPermanentStorageHwmDate !== undefined
          ? { restrictToPermanentStorageHwmDate: dateRange.restrictToPermanentStorageHwmDate }
          : {}),
      },
    };
  }

  /** One page. Throws {@link ConfigurationException} when the range exceeds 100 days. */
  async queryMetadata(filters: InvoiceQueryFilters, options: MetadataQueryOptions = {}): Promise<InvoiceMetadataPage> {
    const from = toDate(filters.dateRange.from);
    const to = filters.dateRange.to === undefined ? undefined : toDate(filters.dateRange.to);
    const end = to ?? new Date();
    if (end.getTime() - from.getTime() > MAX_RANGE_DAYS * DAY_MS) {
      throw new ConfigurationException(`Date range exceeds ${String(MAX_RANGE_DAYS)} days; use iterateMetadata() which splits ranges`);
    }
    return (
      await this.#ctx.call<InvoiceMetadataPage>({
        method: 'POST',
        path: '/invoices/query/metadata',
        query: { pageSize: options.pageSize, pageOffset: options.pageOffset, sortOrder: options.sortOrder },
        json: this.#body(filters, from, to),
        idempotent: true, // read-only query
        signal: options.signal,
      })
    ).data;
  }

  /**
   * Lazily yields every matching invoice. Ranges longer than 100 days are split into windows;
   * `hasMore`/`isTruncated` are handled as documented (offset paging, window narrowing at 10 000 rows).
   */
  async *iterateMetadata(
    filters: InvoiceQueryFilters,
    options: Omit<MetadataQueryOptions, 'pageOffset'> = {},
  ): AsyncGenerator<InvoiceMetadata, void, undefined> {
    const asc = (options.sortOrder ?? 'Asc') === 'Asc';
    const globalFrom = toDate(filters.dateRange.from);
    const globalTo = filters.dateRange.to === undefined ? new Date() : toDate(filters.dateRange.to);
    const windows: { from: Date; to: Date }[] = [];
    for (let s = globalFrom.getTime(); s < globalTo.getTime() || windows.length === 0; ) {
      const e = Math.min(s + MAX_RANGE_DAYS * DAY_MS, globalTo.getTime());
      windows.push({ from: new Date(s), to: new Date(e) });
      if (e >= globalTo.getTime()) break;
      s = e;
    }
    if (!asc) windows.reverse();
    const seen = new Set<string>();
    const maxPages = options.maxPages ?? DEFAULT_MAX_PAGES;
    let pages = 0;
    for (const w of windows) {
      let from = w.from;
      let to = w.to;
      let offset = 0;
      for (;;) {
        if (pages >= maxPages) throw new PaginationException(`Metadata pagination exceeded ${String(maxPages)} pages`, pages);
        const page = await this.queryMetadata(
          { ...filters, dateRange: { ...filters.dateRange, from, to } },
          { ...options, pageOffset: offset },
        );
        pages += 1;
        let last: InvoiceMetadata | undefined;
        let fresh = 0;
        for (const inv of page.invoices) {
          last = inv;
          if (seen.has(inv.ksefNumber)) continue;
          seen.add(inv.ksefNumber);
          fresh += 1;
          yield inv;
        }
        if (!page.hasMore) break;
        if (fresh === 0 && !page.isTruncated) {
          throw new PaginationException('Server reports hasMore but returned no new invoices (pagination does not progress)', pages);
        }
        if (!page.isTruncated) {
          offset += 1;
          continue;
        }
        if (last === undefined) break;
        const pivot = new Date(dateOf(last, filters.dateRange.dateType));
        if ((asc && pivot.getTime() <= from.getTime() && fresh === 0) || (!asc && pivot.getTime() >= to.getTime() && fresh === 0)) {
          throw new PaginationException('Truncated result did not narrow the date range (pagination does not progress)', pages);
        }
        if (asc) from = pivot;
        else to = pivot;
        offset = 0;
      }
    }
  }
}

function dateOf(inv: InvoiceMetadata, t: InvoiceDateType): string {
  switch (t) {
    case 'Issue':
      return `${inv.issueDate}T00:00:00+00:00`;
    case 'Invoicing':
      return inv.invoicingDate;
    case 'PermanentStorage':
      return inv.permanentStorageDate;
  }
}

/** UPO (official confirmation of receipt) downloads. Returns the signed UPO XML as text. */
export class UpoService {
  readonly #ctx: ApiContext;
  constructor(ctx: ApiContext) {
    this.#ctx = ctx;
  }

  #get(path: string, signal?: AbortSignal): Promise<string> {
    return this.#ctx.call<string>({ method: 'GET', path, responseType: 'text', signal }).then((r) => r.data);
  }

  /** `GET /sessions/{ref}/invoices/{invoiceRef}/upo`. */
  async getInvoiceUpo(sessionReferenceNumber: string, invoiceReferenceNumber: string, signal?: AbortSignal): Promise<string> {
    return await this.#get(
      `/sessions/${refSeg(sessionReferenceNumber)}/invoices/${refSeg(invoiceReferenceNumber)}/upo`,
      signal,
    );
  }

  /** `GET /sessions/{ref}/invoices/ksef/{ksefNumber}/upo`. */
  async getInvoiceUpoByKsefNumber(sessionReferenceNumber: string, ksefNumber: string, signal?: AbortSignal): Promise<string> {
    return await this.#get(
      `/sessions/${refSeg(sessionReferenceNumber)}/invoices/ksef/${ksefSeg(ksefNumber)}/upo`,
      signal,
    );
  }

  /** `GET /sessions/{ref}/upo/{upoRef}` (one page of the session UPO). */
  async getSessionUpo(sessionReferenceNumber: string, upoReferenceNumber: string, signal?: AbortSignal): Promise<string> {
    return await this.#get(
      `/sessions/${refSeg(sessionReferenceNumber)}/upo/${refSeg(upoReferenceNumber)}`,
      signal,
    );
  }
}

export interface SubmitOptions {
  readonly formCode?: FormCode;
  readonly validate?: SendOptions['validate'];
  readonly signal?: AbortSignal;
  readonly await?: Omit<AwaitOptions, 'signal'>;
  /** Close the session after the invoice reached a terminal state. Default true. */
  readonly closeSession?: boolean;
  /** After an ambiguous send: how many times to look for the invoice before giving up. Default 3. */
  readonly reconcileAttempts?: number;
  /** Delay between reconcile attempts. Default 3000 ms. */
  readonly reconcileDelayMs?: number;
}

export interface SubmissionResult {
  readonly ksefNumber: string;
  readonly invoiceReferenceNumber: string;
  readonly sessionReferenceNumber: string;
  readonly invoiceHash: string;
  /** `true` when KSeF already exposes an invoice UPO download link. */
  readonly upoAvailable: boolean;
  readonly invoice: SessionInvoice;
}

/** High-level "send one invoice and wait for acceptance". Never resends automatically. */
export class InvoiceSender {
  readonly #sessions: InvoiceSessionService;
  constructor(sessions: InvoiceSessionService) {
    this.#sessions = sessions;
  }

  /**
   * open session -> send -> await status 200 -> close session.
   * Throws {@link InvoiceRejectedException} on a final status >= 400 and
   * {@link UnknownOutcomeException} when the send outcome stays unknown after reconciliation
   * (the invoice was NOT resent: decide yourself).
   */
  async submit(xml: string | Uint8Array, options: SubmitOptions = {}): Promise<SubmissionResult> {
    // Validate BEFORE opening a session: invalid XML must not cost an open+close (rate-limit budget).
    await this.#sessions.validateInvoice(xml, {
      ...(options.formCode !== undefined ? { formCode: options.formCode } : {}),
      ...(options.validate !== undefined ? { validate: options.validate } : {}),
    });
    const session: OnlineSession = await this.#sessions.openOnline({
      ...(options.formCode !== undefined ? { formCode: options.formCode } : {}),
      ...(options.signal !== undefined ? { signal: options.signal } : {}),
    });
    try {
      let invoiceRef: string;
      let invoiceHash: string;
      try {
        const sent = await this.#sessions.sendInvoice(session, xml, {
          validate: false, // already validated above
          ...(options.signal !== undefined ? { signal: options.signal } : {}),
        });
        invoiceRef = sent.referenceNumber;
        invoiceHash = sent.invoiceHash;
      } catch (e) {
        if (!(e instanceof UnknownOutcomeException) || e.invoiceHash === undefined) throw e;
        const attempts = options.reconcileAttempts ?? 3;
        let found: SessionInvoice | undefined;
        try {
          for (let i = 0; i < attempts && found === undefined; i++) {
            await this.#sessions.pause(options.reconcileDelayMs ?? 3000, options.signal);
            found = await this.#sessions.reconcile(session, e.invoiceHash, options.signal);
          }
        } catch (reconcileError) {
          // Aborted while reconciling: the outcome is still unknown. Never replace the UnknownOutcomeException
          // (invoiceHash + session reference) by a bare AbortException; the caller reconciles later with a fresh signal.
          if (reconcileError instanceof AbortException) throw e;
          throw reconcileError;
        }
        if (found === undefined) throw e;
        invoiceRef = found.referenceNumber;
        invoiceHash = e.invoiceHash;
      }
      const invoice = await this.#sessions.awaitInvoice(session.referenceNumber, invoiceRef, {
        ...options.await,
        ...(options.signal !== undefined ? { signal: options.signal } : {}),
      });
      const ksefNumber = invoice.ksefNumber;
      if (ksefNumber === undefined || ksefNumber === null) {
        throw new ConfigurationException('KSeF reported status 200 without a ksefNumber');
      }
      return {
        ksefNumber,
        invoiceReferenceNumber: invoiceRef,
        sessionReferenceNumber: session.referenceNumber,
        invoiceHash,
        upoAvailable: invoice.upoDownloadUrl !== undefined && invoice.upoDownloadUrl !== null,
        invoice,
      };
    } finally {
      if (options.closeSession !== false) {
        await this.#sessions.close(session).catch(() => undefined);
      }
    }
  }
}
