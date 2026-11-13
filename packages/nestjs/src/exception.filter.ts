import { Catch, Inject, Logger, Optional, type ArgumentsHost, type ExceptionFilter } from '@nestjs/common';
import { HttpAdapterHost } from '@nestjs/core';
import {
  ERROR_CODE_SESSION_UNAVAILABLE,
  AbortException,
  ApiException,
  AuthenticationException,
  AuthorizationException,
  GoneException,
  InvoiceBuilderException,
  InvoiceRejectedException,
  KsefException,
  NetworkException,
  NotFoundException,
  RateLimitException,
  SessionException,
  TimeoutException,
  UnknownOutcomeException,
  ValidationException,
  XmlValidationException,
} from '@luroweb/ksef-foundry';

/** Options of {@link KsefExceptionFilter}. */
export interface KsefExceptionFilterOptions {
  /**
   * Forward upstream KSeF `details`, `reasonCode`, `security` (required permissions), KSeF status/error codes
   * and `retryable` to the API client. Default `false`: they can contain taxpayer identifiers, so the
   * default body only carries `code`, `category`, `traceId` and `retryAfterSeconds`.
   */
  readonly exposeUpstreamDetails?: boolean;
}

/** Injection token for {@link KsefExceptionFilterOptions} (optional). */
export const KSEF_EXCEPTION_FILTER_OPTIONS = Symbol.for('@luroweb/nestjs-ksef-foundry:EXCEPTION_FILTER_OPTIONS');

/**
 * Whitelisted, secret-free KSeF details of an error response. Minimal by default
 * (`category`, `traceId`, `retryAfterSeconds`); the rest only with `exposeUpstreamDetails`.
 */
export interface KsefErrorDetails {
  category?: string;
  statusCode?: number;
  ksefCode?: number;
  ksefCodes?: readonly number[];
  traceId?: string;
  details?: readonly string[];
  reasonCode?: string;
  security?: unknown;
  retryAfterSeconds?: number;
  retryable?: boolean;
}

/** JSON body written by {@link KsefExceptionFilter}. Contains only whitelisted, secret-free fields. */
export interface KsefErrorBody {
  readonly statusCode: number;
  readonly error: string;
  /** Stable machine code, e.g. `KSEF_RATE_LIMITED`. */
  readonly code: string;
  readonly message: string;
  readonly ksef?: KsefErrorDetails;
  /** Present for ambiguous send outcomes: reconcile before resending. */
  readonly reconcile?: { readonly invoiceHash?: string; readonly sessionReferenceNumber?: string; readonly hint: string };
  /** Builder model problem description (SDK-authored text about the caller's own model). */
  readonly detail?: string;
  readonly validationErrors?: readonly { line: number; column: number; message: string; level: string; code?: string }[];
}

interface Mapped {
  readonly status: number;
  readonly error: string;
  readonly code: string;
  readonly message: string;
  readonly headers?: Record<string, string>;
  readonly extra?: Partial<KsefErrorBody>;
}

function num(v: unknown): number | undefined {
  return typeof v === 'number' ? v : undefined;
}
function str(v: unknown): string | undefined {
  return typeof v === 'string' ? v : undefined;
}
function list<T>(v: unknown, guard: (x: unknown) => x is T): T[] | undefined {
  return Array.isArray(v) ? (v as unknown[]).filter(guard) : undefined;
}
const isNum = (x: unknown): x is number => typeof x === 'number';
const isStr = (x: unknown): x is string => typeof x === 'string';

/** Only these `diagnostics()` fields ever leave the process. */
function safeKsef(e: KsefException, expose: boolean): KsefErrorDetails {
  const d = e.diagnostics();
  const out: KsefErrorDetails = { category: e.category };
  const trace = str(d['traceId']);
  if (trace !== undefined) out.traceId = trace;
  const ra = num(d['retryAfterSeconds']);
  if (ra !== undefined) out.retryAfterSeconds = ra;
  if (!expose) return out;
  const status = num(d['statusCode']);
  if (status !== undefined) out.statusCode = status;
  const code = num(d['ksefCode']);
  if (code !== undefined) out.ksefCode = code;
  const codes = list(d['ksefCodes'], isNum);
  if (codes !== undefined && codes.length > 0) out.ksefCodes = codes;
  const details = list(d['details'], isStr);
  if (details !== undefined && details.length > 0) out.details = details;
  const reason = str(d['reasonCode']);
  if (reason !== undefined) out.reasonCode = reason;
  if (d['security'] !== undefined) out.security = d['security'];
  if (typeof d['retryable'] === 'boolean') out.retryable = d['retryable'];
  return out;
}

function map(e: KsefException): Mapped {
  if (e instanceof RateLimitException) {
    const ra = e.retryAfterSeconds;
    return {
      status: 429,
      error: 'Too Many Requests',
      code: 'KSEF_RATE_LIMITED',
      message: 'KSeF rate limit exceeded; retry later.',
      ...(ra !== undefined ? { headers: { 'Retry-After': String(ra) } } : {}),
    };
  }
  if (e instanceof AuthorizationException) {
    return { status: 403, error: 'Forbidden', code: 'KSEF_FORBIDDEN', message: 'KSeF denied the operation (missing permissions).' };
  }
  if (e instanceof AuthenticationException) {
    return { status: 502, error: 'Bad Gateway', code: 'KSEF_AUTHENTICATION_FAILED', message: 'Authentication against KSeF failed.' };
  }
  if (e instanceof ValidationException) {
    return { status: 400, error: 'Bad Request', code: 'KSEF_VALIDATION_FAILED', message: 'KSeF rejected the request as invalid.' };
  }
  if (e instanceof InvoiceBuilderException) {
    return { status: 422, error: 'Unprocessable Entity', code: 'KSEF_INVOICE_MODEL_INVALID', message: 'The invoice model is invalid.', extra: { detail: e.message } };
  }
  if (e instanceof XmlValidationException) {
    return {
      status: 422,
      error: 'Unprocessable Entity',
      code: 'KSEF_XML_INVALID',
      message: 'Invoice XML failed schema validation.',
      extra: { validationErrors: e.errors.map((x) => ({ line: x.line, column: x.column, message: x.message, level: x.level, ...(x.code !== undefined ? { code: x.code } : {}) })) },
    };
  }
  if (e instanceof InvoiceRejectedException) {
    return { status: 422, error: 'Unprocessable Entity', code: 'KSEF_INVOICE_REJECTED', message: 'KSeF rejected the invoice.' };
  }
  if (e instanceof UnknownOutcomeException) {
    return {
      status: 409,
      error: 'Conflict',
      code: 'KSEF_UNKNOWN_OUTCOME',
      message: 'The outcome of the operation is unknown.',
      extra: {
        reconcile: {
          ...(e.invoiceHash !== undefined ? { invoiceHash: e.invoiceHash } : {}),
          ...(e.sessionReferenceNumber !== undefined ? { sessionReferenceNumber: e.sessionReferenceNumber } : {}),
          hint: 'Do not resend yet: list the session invoices and compare invoiceHash; resend only if it is absent.',
        },
      },
    };
  }
  if (e instanceof TimeoutException) {
    return { status: 504, error: 'Gateway Timeout', code: 'KSEF_TIMEOUT', message: 'KSeF did not answer in time.' };
  }
  if (e instanceof NetworkException) {
    return { status: 502, error: 'Bad Gateway', code: 'KSEF_NETWORK_ERROR', message: 'KSeF is unreachable.' };
  }
  if (e instanceof NotFoundException) return { status: 404, error: 'Not Found', code: 'KSEF_NOT_FOUND', message: 'Not found in KSeF.' };
  if (e instanceof GoneException) return { status: 410, error: 'Gone', code: 'KSEF_GONE', message: 'The resource is no longer available in KSeF.' };
  if (e instanceof SessionException) {
    const unavailable = e.ksefCodes.includes(ERROR_CODE_SESSION_UNAVAILABLE);
    return {
      status: unavailable ? 503 : 409,
      error: unavailable ? 'Service Unavailable' : 'Conflict',
      code: unavailable ? 'KSEF_SESSION_UNAVAILABLE' : 'KSEF_SESSION_ERROR',
      message: unavailable ? 'KSeF session temporarily unavailable.' : 'KSeF session state problem.',
    };
  }
  if (e instanceof AbortException) {
    return { status: 503, error: 'Service Unavailable', code: 'KSEF_ABORTED', message: 'The operation was aborted.' };
  }
  if (e instanceof ApiException) {
    return { status: 502, error: 'Bad Gateway', code: 'KSEF_UPSTREAM_ERROR', message: 'KSeF answered with an error.' };
  }
  return { status: 500, error: 'Internal Server Error', code: 'KSEF_INTERNAL_ERROR', message: 'Internal KSeF integration error.' };
}

interface ExpressLike {
  status(code: number): { json(body: unknown): unknown };
  setHeader(name: string, value: string): unknown;
}

/**
 * Maps SDK exceptions to HTTP responses with whitelisted fields only (minimal by default, see KsefExceptionFilterOptions) (no tokens, no raw bodies,
 * no stack traces). Works with Express and Fastify through Nest's `HttpAdapterHost`.
 * Use `@UseFilters(KsefExceptionFilter)`, `app.useGlobalFilters(new KsefExceptionFilter())` or `APP_FILTER`.
 *
 * Mapping: RateLimit 429 (+Retry-After); Authorization 403; Authentication 502 (OUR credentials failed
 * upstream - a 401 would wrongly tell your API client to log in to your service); Validation (KSeF 400) 400;
 * XmlValidation / InvoiceBuilder / InvoiceRejected 422; UnknownOutcome 409 (+reconcile hint; also used by the core for
 * malformed 2xx answers and aborted sends); Timeout 504; Network 502;
 * NotFound 404; Gone 410; Session 409 (503 for code 21184); Abort 503; other ApiException 502;
 * Configuration / Certificate / Encryption 500 with a generic message.
 */
@Catch(KsefException)
export class KsefExceptionFilter implements ExceptionFilter<KsefException> {
  readonly #logger = new Logger('KsefExceptionFilter');
  readonly #host: HttpAdapterHost | undefined;
  readonly #expose: boolean;

  /**
   * @param options `new KsefExceptionFilter({ exposeUpstreamDetails: true })`, or provide
   *   {@link KSEF_EXCEPTION_FILTER_OPTIONS} when Nest instantiates the filter
   * @param host injected by Nest; without it the Express response API is used
   */
  constructor(
    @Optional() @Inject(KSEF_EXCEPTION_FILTER_OPTIONS) options?: KsefExceptionFilterOptions,
    @Optional() @Inject(HttpAdapterHost) host?: HttpAdapterHost,
  ) {
    this.#expose = options?.exposeUpstreamDetails === true;
    this.#host = host;
  }

  catch(exception: KsefException, host: ArgumentsHost): void {
    const m = map(exception);
    const ksef = safeKsef(exception, this.#expose);
    const body: KsefErrorBody = {
      statusCode: m.status,
      error: m.error,
      code: m.code,
      message: m.message,
      ksef,
      ...m.extra,
    };
    const extra = `${ksef.ksefCode !== undefined ? ` ksefCode=${String(ksef.ksefCode)}` : ''}${ksef.traceId !== undefined ? ` traceId=${ksef.traceId}` : ''}`;
    this.#logger.warn(`${exception.name} -> ${String(m.status)} ${m.code}${extra}`);
    const response: unknown = host.switchToHttp().getResponse();
    const adapter = this.#host?.httpAdapter;
    if (adapter !== undefined) {
      for (const [k, v] of Object.entries(m.headers ?? {})) adapter.setHeader(response, k, v);
      adapter.reply(response, body, m.status);
      return;
    }
    const res = response as ExpressLike;
    for (const [k, v] of Object.entries(m.headers ?? {})) res.setHeader(k, v);
    res.status(m.status).json(body);
  }
}
