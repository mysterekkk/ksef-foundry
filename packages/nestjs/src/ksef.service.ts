import {
  type AuthSessionService,
  type DownloadedInvoice,
  type InvoiceMetadata,
  type InvoiceMetadataPage,
  type InvoiceQueryFilters,
  type InvoiceSessionService,
  type KsefClient,
  type KsefTokenService,
  type LimitsService,
  type MetadataQueryOptions,
  type SessionInvoice,
  type SubmissionResult,
  type SubmitOptions,
  type ValidationResult,
  XmlValidator,
} from '@luroweb/ksef-foundry';
import type { KsefRuntime } from './runtime.js';

/** Which UPO to download. */
export type UpoRequest =
  | { readonly sessionReferenceNumber: string; readonly invoiceReferenceNumber: string }
  | { readonly sessionReferenceNumber: string; readonly ksefNumber: string }
  | { readonly sessionReferenceNumber: string; readonly upoReferenceNumber: string };

/**
 * Thin, typed facade over one `KsefClient`. Every helper links the caller's `AbortSignal` with the
 * module's shutdown signal, so in-flight work is cancelled (without retries) when the app stops.
 */
export class KsefService {
  readonly #runtime: KsefRuntime;
  #validator: XmlValidator | undefined;

  constructor(runtime: KsefRuntime) {
    this.#runtime = runtime;
  }

  /** Registration name (`default` unless `forRoot({ name })` was used). */
  get name(): string {
    return this.#runtime.name;
  }

  /** The single long-lived SDK client. */
  get client(): KsefClient {
    return this.#runtime.client;
  }

  /** Aborts on application shutdown. */
  get shutdownSignal(): AbortSignal {
    return this.#runtime.shutdownSignal;
  }

  get tokens(): KsefTokenService {
    return this.client.tokens;
  }
  get limits(): LimitsService {
    return this.client.limits;
  }
  get sessions(): InvoiceSessionService {
    return this.client.sessions;
  }
  get authSessions(): AuthSessionService {
    return this.client.authSessions;
  }

  #signal(caller?: AbortSignal): AbortSignal {
    return caller === undefined ? this.shutdownSignal : AbortSignal.any([caller, this.shutdownSignal]);
  }

  /**
   * Validates FA(3) XML locally with the SDK's bundled official XSD and KSeF technical rules
   * (no network). Returns the SDK ValidationResult; it does not throw for an invalid document.
   * Pass a string, or raw bytes when the encoding must be checked.
   */
  validateInvoice(xml: string | Uint8Array): Promise<ValidationResult> {
    this.#validator ??= new XmlValidator();
    return this.#validator.validateFa3(xml);
  }

  /**
   * open session -> send -> wait for status 200 -> close. Never resends automatically; an ambiguous
   * outcome surfaces as `UnknownOutcomeException`.
   */
  submitInvoice(xml: string | Uint8Array, options: SubmitOptions = {}): Promise<SubmissionResult> {
    const validator = this.#runtime.invoiceValidator;
    return this.client.sender.submit(xml, {
      ...options,
      ...(options.validate === undefined && validator !== undefined ? { validate: validator } : {}),
      signal: this.#signal(options.signal),
    });
  }

  /** One page of invoice metadata (`POST /invoices/query/metadata`). */
  queryInvoices(filters: InvoiceQueryFilters, options: MetadataQueryOptions = {}): Promise<InvoiceMetadataPage> {
    return this.client.invoiceQuery.queryMetadata(filters, { ...options, signal: this.#signal(options.signal) });
  }

  /** Lazily iterates all pages of a metadata query. */
  iterateInvoices(filters: InvoiceQueryFilters, options: Omit<MetadataQueryOptions, 'pageOffset'> = {}): AsyncGenerator<InvoiceMetadata, void, undefined> {
    return this.client.invoiceQuery.iterateMetadata(filters, { ...options, signal: this.#signal(options.signal) });
  }

  /** `GET /invoices/ksef/{ksefNumber}`. */
  downloadInvoice(ksefNumber: string, signal?: AbortSignal): Promise<DownloadedInvoice> {
    return this.client.invoices.getByKsefNumber(ksefNumber, this.#signal(signal));
  }

  /** Status of one invoice of a session. */
  getInvoiceStatus(sessionReferenceNumber: string, invoiceReferenceNumber: string, signal?: AbortSignal): Promise<SessionInvoice> {
    return this.client.sessions.getInvoiceStatus(sessionReferenceNumber, invoiceReferenceNumber, this.#signal(signal));
  }

  /** UPO XML (by invoice reference, KSeF number or session UPO reference). */
  getUpo(request: UpoRequest, signal?: AbortSignal): Promise<string> {
    const s = this.#signal(signal);
    if ('invoiceReferenceNumber' in request) {
      return this.client.upo.getInvoiceUpo(request.sessionReferenceNumber, request.invoiceReferenceNumber, s);
    }
    if ('ksefNumber' in request) {
      return this.client.upo.getInvoiceUpoByKsefNumber(request.sessionReferenceNumber, request.ksefNumber, s);
    }
    return this.client.upo.getSessionUpo(request.sessionReferenceNumber, request.upoReferenceNumber, s);
  }
}
