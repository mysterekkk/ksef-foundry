import { redact } from './sensitive.js';

/** Secret-free, JSON-serialisable error snapshot. */
export type Diagnostics = Record<string, unknown>;

/** Base class of every error thrown by the SDK. */
export class KsefException extends Error {
  /** Machine friendly category (e.g. `network`, `validation`, `rate_limit`). */
  readonly category: string;

  constructor(message: string, options?: { cause?: unknown; category?: string }) {
    super(message, options?.cause === undefined ? undefined : { cause: options.cause });
    this.name = new.target.name;
    this.category = options?.category ?? 'ksef';
    Object.setPrototypeOf(this, new.target.prototype);
  }

  /** Secret-free diagnostic data (never contains tokens, keys or payloads). */
  diagnostics(): Diagnostics {
    return { name: this.name, category: this.category, message: this.message };
  }
}

/** Invalid SDK configuration (bad environment, URL, option). */
export class ConfigurationException extends KsefException {
  constructor(message: string, options?: { cause?: unknown }) {
    super(message, { ...options, category: 'configuration' });
  }
}

/** Connect/DNS/TLS/reset failure. Retryable for idempotent requests only. */
export class NetworkException extends KsefException {
  readonly retryable = true;
  constructor(message: string, options?: { cause?: unknown; category?: string }) {
    super(message, { category: 'network', ...options });
  }
  override diagnostics(): Diagnostics {
    return { ...super.diagnostics(), retryable: this.retryable };
  }
}

/** Per-request timeout or overall deadline exceeded. */
export class TimeoutException extends NetworkException {
  constructor(message: string, options?: { cause?: unknown }) {
    super(message, { ...options, category: 'timeout' });
  }
}

/** The caller aborted the operation through an `AbortSignal`. Never retried. */
export class AbortException extends KsefException {
  constructor(message = 'Operation aborted by caller', options?: { cause?: unknown }) {
    super(message, { ...options, category: 'aborted' });
  }
}

/** Parsed problem body (`application/problem+json` or legacy `ExceptionResponse`). */
export interface ApiProblem {
  readonly title?: string;
  readonly detail?: string;
  readonly status?: number;
  readonly instance?: string;
  readonly reasonCode?: string;
  readonly errors: readonly { code: number; description: string; details: readonly string[] }[];
  readonly security?: unknown;
  readonly raw?: unknown;
}

export interface ApiExceptionInit {
  statusCode: number;
  message: string;
  ksefCodes?: readonly number[];
  details?: readonly string[];
  traceId?: string | undefined;
  problem?: ApiProblem | undefined;
  retryAfterSeconds?: number | undefined;
  method?: string;
  path?: string;
  /** KSeF authentication operation status (GET /auth/{ref}) that caused this error. */
  authStatusCode?: number | undefined;
  cause?: unknown;
}

/** Any non-2xx API answer. */
export class ApiException extends KsefException {
  readonly statusCode: number;
  readonly ksefCodes: readonly number[];
  readonly details: readonly string[];
  readonly traceId: string | undefined;
  readonly problem: ApiProblem | undefined;
  readonly retryAfterSeconds: number | undefined;
  readonly method: string | undefined;
  readonly path: string | undefined;
  readonly authStatusCode: number | undefined;

  constructor(init: ApiExceptionInit, category = 'api') {
    super(init.message, { cause: init.cause, category });
    this.statusCode = init.statusCode;
    this.ksefCodes = init.ksefCodes ?? [];
    this.details = init.details ?? [];
    this.traceId = init.traceId;
    this.problem = init.problem;
    this.retryAfterSeconds = init.retryAfterSeconds;
    this.method = init.method;
    this.path = init.path;
    this.authStatusCode = init.authStatusCode;
  }

  /** First KSeF error code (`errors[].code` or legacy `exceptionCode`). */
  get ksefCode(): number | undefined {
    return this.ksefCodes[0];
  }

  /** Whether an idempotent request may be retried. */
  get retryable(): boolean {
    return this.statusCode === 429 || this.statusCode === 502 || this.statusCode === 503 || this.statusCode === 504;
  }

  override diagnostics(): Diagnostics {
    return {
      ...super.diagnostics(),
      statusCode: this.statusCode,
      ksefCode: this.ksefCode,
      ksefCodes: [...this.ksefCodes],
      details: [...this.details],
      traceId: this.traceId,
      retryable: this.retryable,
      retryAfterSeconds: this.retryAfterSeconds,
      method: this.method,
      path: this.path,
      authStatusCode: this.authStatusCode,
      problem: this.problem === undefined ? undefined : redact({ ...this.problem, raw: undefined }),
    };
  }
}

/** HTTP 400. */
export class ValidationException extends ApiException {
  constructor(init: ApiExceptionInit) {
    super(init, 'validation');
  }
}

/** HTTP 401 (also used for auth-status failures 425/450/460/470/480/550). */
export class AuthenticationException extends ApiException {
  constructor(init: ApiExceptionInit) {
    super(init, 'authentication');
  }
}

/** HTTP 403 (missing permissions etc.). */
export class AuthorizationException extends ApiException {
  readonly reasonCode: string | undefined;
  readonly security: unknown;
  constructor(init: ApiExceptionInit) {
    super(init, 'authorization');
    this.reasonCode = init.problem?.reasonCode;
    this.security = init.problem?.security;
  }
  override diagnostics(): Diagnostics {
    return { ...super.diagnostics(), reasonCode: this.reasonCode, security: redact(this.security) };
  }
}

/** HTTP 404. */
export class NotFoundException extends ApiException {
  constructor(init: ApiExceptionInit) {
    super(init, 'not_found');
  }
}

/** HTTP 410. */
export class GoneException extends ApiException {
  constructor(init: ApiExceptionInit) {
    super(init, 'gone');
  }
}

/** HTTP 429. */
export class RateLimitException extends ApiException {
  constructor(init: ApiExceptionInit) {
    super(init, 'rate_limit');
  }
}

/** HTTP 5xx. */
export class ServerException extends ApiException {
  constructor(init: ApiExceptionInit) {
    super(init, 'server');
  }
}

/** Session state problem (expired session, 21184 "temporarily unavailable", ...). */
export class SessionException extends KsefException {
  readonly statusCode: number | undefined;
  readonly ksefCodes: readonly number[];
  readonly sessionReferenceNumber: string | undefined;
  constructor(
    message: string,
    options?: { cause?: unknown; statusCode?: number; ksefCodes?: readonly number[]; sessionReferenceNumber?: string },
  ) {
    super(message, { cause: options?.cause, category: 'session' });
    this.statusCode = options?.statusCode;
    this.ksefCodes = options?.ksefCodes ?? [];
    this.sessionReferenceNumber = options?.sessionReferenceNumber;
  }
  override diagnostics(): Diagnostics {
    return {
      ...super.diagnostics(),
      statusCode: this.statusCode,
      ksefCodes: [...this.ksefCodes],
      sessionReferenceNumber: this.sessionReferenceNumber,
    };
  }
}

/** Final invoice status >= 400. */
export class InvoiceRejectedException extends KsefException {
  readonly statusCode: number;
  readonly statusDescription: string;
  readonly statusDetails: readonly string[];
  /** Extra key/value data of the status (e.g. `originalKsefNumber`, `originalSessionReferenceNumber` for 440 duplicates). */
  readonly statusExtensions: Readonly<Record<string, unknown>>;
  readonly invoiceReferenceNumber: string | undefined;
  readonly sessionReferenceNumber: string | undefined;
  constructor(init: {
    statusCode: number;
    description: string;
    details?: readonly string[];
    extensions?: Readonly<Record<string, unknown>> | null;
    invoiceReferenceNumber?: string;
    sessionReferenceNumber?: string;
  }) {
    super(`Invoice rejected by KSeF: ${String(init.statusCode)} ${init.description}`, { category: 'invoice_rejected' });
    this.statusCode = init.statusCode;
    this.statusDescription = init.description;
    this.statusDetails = init.details ?? [];
    this.statusExtensions = init.extensions ?? {};
    this.invoiceReferenceNumber = init.invoiceReferenceNumber;
    this.sessionReferenceNumber = init.sessionReferenceNumber;
  }
  override diagnostics(): Diagnostics {
    return {
      ...super.diagnostics(),
      statusCode: this.statusCode,
      statusDescription: this.statusDescription,
      statusDetails: [...this.statusDetails],
      statusExtensions: { ...this.statusExtensions },
      invoiceReferenceNumber: this.invoiceReferenceNumber,
      sessionReferenceNumber: this.sessionReferenceNumber,
    };
  }
}

/**
 * Ambiguous outcome of an operation that must not be auto-retried (timeout/reset/5xx on send).
 * Reconcile (list session invoices, compare `invoiceHash`) before resending.
 */
export class UnknownOutcomeException extends KsefException {
  readonly invoiceHash: string | undefined;
  readonly sessionReferenceNumber: string | undefined;
  readonly operation: string;
  constructor(
    message: string,
    init: { operation: string; invoiceHash?: string; sessionReferenceNumber?: string; cause?: unknown },
  ) {
    super(message, { cause: init.cause, category: 'unknown_outcome' });
    this.operation = init.operation;
    this.invoiceHash = init.invoiceHash;
    this.sessionReferenceNumber = init.sessionReferenceNumber;
  }
  override diagnostics(): Diagnostics {
    return {
      ...super.diagnostics(),
      operation: this.operation,
      invoiceHash: this.invoiceHash,
      sessionReferenceNumber: this.sessionReferenceNumber,
      causeName: this.cause instanceof Error ? this.cause.name : undefined,
    };
  }
}

/** A paginated listing did not terminate (repeated continuation token, no progress, or page limit exceeded). */
export class PaginationException extends KsefException {
  readonly pages: number;
  constructor(message: string, pages: number) {
    super(message, { category: 'pagination' });
    this.pages = pages;
  }
  override diagnostics(): Diagnostics {
    return { ...super.diagnostics(), pages: this.pages };
  }
}

/** Encryption / key validation failure. */
export class EncryptionException extends KsefException {
  constructor(message: string, options?: { cause?: unknown }) {
    super(message, { ...options, category: 'encryption' });
  }
}

/** Bad/expired certificate, wrong key, wrong passphrase. */
export class CertificateException extends KsefException {
  constructor(message: string, options?: { cause?: unknown }) {
    super(message, { ...options, category: 'certificate' });
  }
}

export interface XmlValidationIssue {
  readonly line: number;
  readonly column: number;
  readonly message: string;
  /** `fatal` is used by the FA(3) validator for unusable documents. */
  readonly level: 'error' | 'warning' | 'fatal';
  /** Stable rule id (e.g. `xsd`, `doctype`, `utf8`); set by the FA(3) validator. */
  readonly code?: string;
}

/** XSD validation failure. */
export class XmlValidationException extends KsefException {
  readonly errors: readonly XmlValidationIssue[];
  constructor(message: string, errors: readonly XmlValidationIssue[] = []) {
    super(message, { category: 'xml_validation' });
    this.errors = errors;
  }
  override diagnostics(): Diagnostics {
    return { ...super.diagnostics(), errors: this.errors.map((e) => ({ ...e })) };
  }
}

function asRecord(v: unknown): Record<string, unknown> | undefined {
  return v !== null && typeof v === 'object' && !Array.isArray(v) ? (v as Record<string, unknown>) : undefined;
}
function str(v: unknown): string | undefined {
  return typeof v === 'string' ? v : undefined;
}
function strList(v: unknown): string[] {
  return Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string') : [];
}

/** Parse a problem+json / legacy ExceptionResponse / 429 legacy body. Tolerates garbage. */
export function parseProblem(body: unknown, status: number): { problem: ApiProblem; message: string; traceId?: string } {
  const root = asRecord(body);
  const errors: { code: number; description: string; details: string[] }[] = [];
  let title: string | undefined;
  let detail: string | undefined;
  let traceId: string | undefined;
  let instance: string | undefined;
  let reasonCode: string | undefined;
  let security: unknown;

  if (root !== undefined) {
    title = str(root['title']);
    detail = str(root['detail']);
    traceId = str(root['traceId']);
    instance = str(root['instance']);
    reasonCode = str(root['reasonCode']);
    security = root['security'];
    if (Array.isArray(root['errors'])) {
      for (const e of root['errors']) {
        const r = asRecord(e);
        if (r !== undefined && typeof r['code'] === 'number') {
          errors.push({ code: r['code'], description: str(r['description']) ?? '', details: strList(r['details']) });
        }
      }
    }
    const legacy = asRecord(root['exception']);
    if (legacy !== undefined) {
      traceId ??= str(legacy['referenceNumber']);
      if (Array.isArray(legacy['exceptionDetailList'])) {
        for (const e of legacy['exceptionDetailList']) {
          const r = asRecord(e);
          if (r !== undefined && typeof r['exceptionCode'] === 'number') {
            errors.push({
              code: r['exceptionCode'],
              description: str(r['exceptionDescription']) ?? '',
              details: strList(r['details']),
            });
          }
        }
      }
    }
    const st = asRecord(root['status']);
    if (st !== undefined) {
      // legacy 429 body: { status: { code, description, details[] } }
      const d = strList(st['details']);
      detail ??= d.join(' ') || str(st['description']);
    }
  }
  const first = errors[0];
  const message =
    first !== undefined
      ? `${first.description || title || `HTTP ${String(status)}`}${first.details.length > 0 ? `: ${first.details.join('; ')}` : ''} (KSeF ${String(first.code)})`
      : detail ?? title ?? `HTTP ${String(status)}`;
  const problem: ApiProblem = {
    ...(title !== undefined ? { title } : {}),
    ...(detail !== undefined ? { detail } : {}),
    status,
    ...(instance !== undefined ? { instance } : {}),
    ...(reasonCode !== undefined ? { reasonCode } : {}),
    errors,
    ...(security !== undefined ? { security } : {}),
  };
  return { problem, message, ...(traceId !== undefined ? { traceId } : {}) };
}

/** Maps an HTTP status + parsed body to the right exception class. */
export function createApiException(args: {
  statusCode: number;
  body: unknown;
  retryAfterSeconds?: number | undefined;
  method?: string;
  path?: string;
}): ApiException {
  const { problem, message, traceId } = parseProblem(args.body, args.statusCode);
  const init: ApiExceptionInit = {
    statusCode: args.statusCode,
    message,
    ksefCodes: problem.errors.map((e) => e.code),
    details: problem.errors.flatMap((e) => e.details),
    traceId,
    problem,
    retryAfterSeconds: args.retryAfterSeconds,
    ...(args.method !== undefined ? { method: args.method } : {}),
    ...(args.path !== undefined ? { path: args.path } : {}),
  };
  const s = args.statusCode;
  if (s === 400) return new ValidationException(init);
  if (s === 401) return new AuthenticationException(init);
  if (s === 403) return new AuthorizationException(init);
  if (s === 404) return new NotFoundException(init);
  if (s === 410) return new GoneException(init);
  if (s === 429) return new RateLimitException(init);
  if (s >= 500) return new ServerException(init);
  return new ApiException(init);
}
