import { Environment } from './environment.js';
import {
  AbortException,
  ApiException,
  ConfigurationException,
  KsefException,
  NetworkException,
  TimeoutException,
  UnknownOutcomeException,
  createApiException,
} from './errors.js';
import { noopLogger, type Logger } from './logger.js';
import { backoffDelayMs, defaultRetryPolicy, parseRetryAfter, sleep, type RetryPolicy } from './retry.js';
import { SensitiveString, redactHeaders } from './sensitive.js';

/** `fetch`-compatible function. Inject your own for proxies / custom `Agent`s (never for mocking). */
export type FetchLike = (input: string, init: RequestInit) => Promise<Response>;

export interface HttpTransportOptions {
  readonly environment: Environment;
  /** Custom fetch (proxy, custom undici dispatcher). Default: global `fetch`. */
  readonly fetch?: FetchLike;
  /** Per-attempt timeout in ms. Default 30000. */
  readonly timeoutMs?: number;
  /** Overall deadline per logical request (all attempts and waits) in ms. Default 120000. */
  readonly deadlineMs?: number;
  readonly retry?: Partial<RetryPolicy>;
  readonly logger?: Logger;
  readonly userAgent?: string;
  /** Extra hosts (besides the environment host) that absolute URLs may point to. */
  readonly allowedHosts?: readonly string[];
  /** Random source for jitter (tests may make it deterministic). */
  readonly random?: () => number;
  /** Client-level abort: aborts all in-flight and future requests (never retried). */
  readonly signal?: AbortSignal;
  /** Maximum response body size (after decompression) in bytes. Default 64 MiB. */
  readonly maxResponseBytes?: number;
}

export type ResponseType = 'json' | 'text' | 'bytes' | 'void';

export interface RequestOptions {
  readonly method: 'GET' | 'POST' | 'PUT' | 'DELETE';
  /** Path relative to the environment base URL, starting with `/`. Absolute URLs are refused. */
  readonly path: string;
  readonly query?: Readonly<Record<string, string | number | boolean | readonly (string | number | boolean)[] | undefined>>;
  readonly headers?: Readonly<Record<string, string>>;
  /** JSON body (serialised) or, together with `contentType`, a raw string body. */
  readonly json?: unknown;
  readonly body?: string;
  readonly contentType?: string;
  /** Bearer credential. */
  readonly bearer?: string | SensitiveString;
  /** Safe to repeat after an ambiguous failure (GET, challenge...). Default: `method === 'GET'`. */
  readonly idempotent?: boolean;
  readonly responseType?: ResponseType;
  readonly signal?: AbortSignal | undefined;
  readonly timeoutMs?: number;
  readonly deadlineMs?: number;
  /** When set, ambiguous failures (timeout/reset/5xx) surface as {@link UnknownOutcomeException}. */
  readonly unknownOutcome?: { operation: string; invoiceHash?: string; sessionReferenceNumber?: string };
  /**
   * Shape check of a successful (2xx) JSON answer. When it returns false the answer is treated as garbled:
   * with `unknownOutcome` set that is an {@link UnknownOutcomeException}, otherwise an `ApiException` (`invalid_response`).
   */
  readonly validateResponse?: (data: unknown) => boolean;
  /** Per-request override of the response size cap. */
  readonly maxResponseBytes?: number;
}

export interface HttpResponse<T> {
  readonly status: number;
  readonly headers: Headers;
  readonly data: T;
}

const MAX_SAME_HOST_REDIRECTS = 3;

/** Low-level HTTP: base URL, headers, JSON, timeouts, retry policy and error mapping. */
export class HttpTransport {
  readonly environment: Environment;
  readonly #fetch: FetchLike;
  readonly #timeoutMs: number;
  readonly #deadlineMs: number;
  readonly #retry: RetryPolicy;
  readonly #logger: Logger;
  readonly #userAgent: string;
  readonly #allowedHosts: ReadonlySet<string>;
  readonly #random: () => number;
  readonly #clientSignal: AbortSignal | undefined;
  readonly #maxResponseBytes: number;

  constructor(options: HttpTransportOptions) {
    this.environment = options.environment;
    this.#fetch = options.fetch ?? ((input, init) => fetch(input, init));
    this.#timeoutMs = options.timeoutMs ?? 30_000;
    this.#deadlineMs = options.deadlineMs ?? 120_000;
    this.#retry = { ...defaultRetryPolicy, ...options.retry };
    this.#logger = options.logger ?? noopLogger;
    this.#userAgent = options.userAgent ?? '@luroweb/ksef-foundry/0.1.0';
    this.#allowedHosts = new Set([options.environment.host, ...(options.allowedHosts ?? [])]);
    this.#random = options.random ?? Math.random;
    this.#clientSignal = options.signal;
    this.#maxResponseBytes = options.maxResponseBytes ?? 64 * 1024 * 1024;
  }

  /** Builds the final URL; refuses anything that is not a plain path below the environment base URL. */
  buildUrl(path: string, query?: RequestOptions['query']): string {
    if (!path.startsWith('/') || path.startsWith('//') || path.includes('://') || path.includes('\\')) {
      throw new ConfigurationException('Request path must be a relative path starting with "/" (absolute URLs are refused)');
    }
    for (const seg of path.slice(1).split('/')) {
      let decoded: string;
      try {
        decoded = decodeURIComponent(seg);
      } catch {
        throw new ConfigurationException('Request path contains an invalid percent-encoding');
      }
      if (seg === '' || decoded === '' || decoded === '.' || decoded === '..' || /[/\\?#]/.test(decoded)) {
        throw new ConfigurationException('Request path contains an empty, dot or separator segment');
      }
    }
    const url = new URL(this.environment.baseUrl + path);
    if (url.host !== this.environment.host) {
      throw new ConfigurationException('Request path escapes the environment host');
    }
    if (query !== undefined) {
      for (const [k, v] of Object.entries(query)) {
        if (v === undefined) continue;
        if (Array.isArray(v)) for (const item of v) url.searchParams.append(k, String(item));
        else url.searchParams.append(k, String(v));
      }
    }
    return url.toString();
  }

  /** Combines a per-call signal with the client-level signal (if any). */
  signalFor(signal?: AbortSignal): AbortSignal | undefined {
    if (signal === undefined) return this.#clientSignal;
    if (this.#clientSignal === undefined) return signal;
    return AbortSignal.any([signal, this.#clientSignal]);
  }

  /** Abortable pause honouring the per-call and client-level signals; throws {@link AbortException} on abort. */
  async pause(ms: number, signal?: AbortSignal): Promise<void> {
    try {
      await sleep(ms, this.signalFor(signal));
    } catch (cause) {
      throw new AbortException('Operation aborted by caller', { cause });
    }
  }

  /** Executes a request with the retry policy of contract section 6. */
  async request<T = unknown>(opts: RequestOptions): Promise<HttpResponse<T>> {
    const idempotent = opts.idempotent ?? opts.method === 'GET';
    const url = this.buildUrl(opts.path, opts.query);
    const deadlineSignal = AbortSignal.timeout(opts.deadlineMs ?? this.#deadlineMs);
    const caller = this.signalFor(opts.signal);
    const baseSignals: AbortSignal[] = [deadlineSignal];
    if (caller !== undefined) baseSignals.push(caller);
    const overall = AbortSignal.any(baseSignals);

    const state = { sent: false };
    let attempt = 0;
    for (;;) {
      attempt += 1;
      let failure: KsefException;
      let retryAfterMs: number | undefined;
      try {
        const r = await this.#attempt<T>(opts, url, overall, caller, attempt, state);
        if (opts.validateResponse !== undefined && !opts.validateResponse(r.data)) {
          throw new ApiException(
            { statusCode: r.status, message: 'Unexpected response shape', method: opts.method, path: opts.path },
            'invalid_response',
          );
        }
        return r;
      } catch (e) {
        if (e instanceof AbortException) {
          // An abort while the request may have reached KSeF leaves the outcome unknown (never "just aborted").
          if (opts.unknownOutcome !== undefined && state.sent) {
            throw new UnknownOutcomeException(
              `Outcome of ${opts.unknownOutcome.operation} is unknown (aborted in flight); reconcile before retrying`,
              { ...opts.unknownOutcome, cause: e },
            );
          }
          throw e;
        }
        if (!(e instanceof KsefException)) throw e;
        failure = e;
        if (e instanceof ApiException && e.statusCode === 429 && e.retryAfterSeconds !== undefined) {
          if (e.retryAfterSeconds > this.#retry.maxRetryAfterSeconds) throw e;
          retryAfterMs = e.retryAfterSeconds * 1000;
        }
      }

      const retryable = this.#isRetryable(failure, idempotent);
      if (!retryable || attempt >= this.#retry.maxAttempts) {
        throw this.#finalError(failure, opts);
      }
      const delay = retryAfterMs ?? backoffDelayMs(attempt, this.#retry, this.#random);
      this.#logger.log('warn', 'ksef.http.retry', {
        method: opts.method,
        path: opts.path,
        attempt,
        delayMs: delay,
        reason: failure.name,
      });
      try {
        await sleep(delay, overall);
      } catch {
        if (caller?.aborted === true) throw new AbortException('Operation aborted by caller', { cause: caller.reason });
        throw this.#finalError(failure, opts); // deadline exhausted while waiting
      }
    }
  }

  #isRetryable(failure: KsefException, idempotent: boolean): boolean {
    if (failure instanceof ApiException) {
      if (failure.statusCode === 429) return true; // request was not processed
      if (!idempotent) return false;
      return failure.statusCode === 502 || failure.statusCode === 503 || failure.statusCode === 504;
    }
    if (failure instanceof NetworkException) return idempotent; // includes TimeoutException
    return false;
  }

  #finalError(failure: KsefException, opts: RequestOptions): KsefException {
    const ambiguous =
      failure instanceof NetworkException ||
      (failure instanceof ApiException &&
        (failure.statusCode >= 500 ||
          (failure.statusCode < 300 && (failure.category === 'invalid_response' || failure.category === 'response_too_large'))));
    if (opts.unknownOutcome !== undefined && ambiguous) {
      return new UnknownOutcomeException(
        `Outcome of ${opts.unknownOutcome.operation} is unknown (${failure.name}); reconcile before retrying`,
        { ...opts.unknownOutcome, cause: failure },
      );
    }
    return failure;
  }

  async #attempt<T>(
    opts: RequestOptions,
    firstUrl: string,
    overall: AbortSignal,
    callerSignal: AbortSignal | undefined,
    attempt: number,
    state: { sent: boolean },
  ): Promise<HttpResponse<T>> {
    const headers: Record<string, string> = {
      Accept: opts.responseType === 'text' || opts.responseType === 'bytes' ? 'application/xml, application/json;q=0.5, */*;q=0.1' : 'application/json',
      'User-Agent': this.#userAgent,
      'X-Error-Format': 'problem-details',
      ...opts.headers,
    };
    if (opts.bearer !== undefined) {
      headers['Authorization'] = `Bearer ${SensitiveString.from(opts.bearer).reveal()}`;
    }
    let body: string | undefined;
    if (opts.json !== undefined) {
      body = JSON.stringify(opts.json);
      headers['Content-Type'] = 'application/json';
    } else if (opts.body !== undefined) {
      body = opts.body;
      headers['Content-Type'] = opts.contentType ?? 'application/octet-stream';
    }

    const timeoutSignal = AbortSignal.timeout(opts.timeoutMs ?? this.#timeoutMs);
    const signal = AbortSignal.any([overall, timeoutSignal]);
    const classify = (cause: unknown): KsefException => {
      if (callerSignal?.aborted === true) return new AbortException('Operation aborted by caller', { cause });
      if (signal.aborted) return new TimeoutException(`${opts.method} ${opts.path} timed out`, { cause });
      return new NetworkException(`${opts.method} ${opts.path} failed: ${cause instanceof Error ? cause.message : 'network error'}`, { cause });
    };

    let url = firstUrl;
    let method = opts.method;
    this.#logger.log('debug', 'ksef.http.request', {
      method,
      path: opts.path,
      attempt,
      headers: redactHeaders(headers),
    });

    for (let hop = 0; ; hop++) {
      let res: Response;
      try {
        const init: RequestInit = { method, headers, redirect: 'manual', signal };
        if (body !== undefined) init.body = body;
        if (!signal.aborted) state.sent = true;
        res = await this.#fetch(url, init);
      } catch (cause) {
        throw classify(cause);
      }

      if (res.status >= 300 && res.status < 400 && res.status !== 304) {
        await res.body?.cancel().catch(() => undefined);
        const location = res.headers.get('location');
        let target: URL | undefined;
        if (location !== null) {
          try {
            target = new URL(location, url);
          } catch {
            target = undefined;
          }
        }
        const sameHost = target !== undefined && this.#allowedHosts.has(target.host) && target.protocol === new URL(url).protocol;
        if (target !== undefined && target.host !== this.environment.host) delete headers['Authorization']; // never leak the bearer to another host
        if (!sameHost || method !== 'GET' || hop >= MAX_SAME_HOST_REDIRECTS || target === undefined) {
          throw new ApiException(
            {
              statusCode: res.status,
              message: `Refusing redirect (${String(res.status)}) ${sameHost ? 'for non-GET or too many hops' : 'to a different host'}`,
              method: opts.method,
              path: opts.path,
            },
            'redirect_refused',
          );
        }
        url = target.toString();
        method = 'GET';
        continue;
      }

      try {
        const data = await this.#readBody<T>(res, opts);
        this.#logger.log('debug', 'ksef.http.response', { method: opts.method, path: opts.path, status: res.status });
        return { status: res.status, headers: res.headers, data };
      } catch (e) {
        if (e instanceof KsefException) throw e;
        throw classify(e);
      }
    }
  }

  async #readCapped(res: Response, opts: RequestOptions): Promise<Uint8Array> {
    const limit = opts.maxResponseBytes ?? this.#maxResponseBytes;
    const tooLarge = (): ApiException =>
      new ApiException(
        { statusCode: res.status, message: `Response exceeds the ${String(limit)} byte limit`, method: opts.method, path: opts.path },
        'response_too_large',
      );
    const declared = Number(res.headers.get('content-length'));
    if (Number.isFinite(declared) && declared > limit) {
      await res.body?.cancel().catch(() => undefined);
      throw tooLarge();
    }
    if (res.body === null) return new Uint8Array();
    const reader = res.body.getReader();
    const chunks: Uint8Array[] = [];
    let total = 0;
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      total += value.byteLength;
      if (total > limit) {
        await reader.cancel().catch(() => undefined);
        throw tooLarge();
      }
      chunks.push(value);
    }
    return Buffer.concat(chunks);
  }

  async #readBody<T>(res: Response, opts: RequestOptions): Promise<T> {
    if (res.status >= 200 && res.status < 300) {
      switch (opts.responseType ?? 'json') {
        case 'void':
          await res.body?.cancel().catch(() => undefined);
          return undefined as T;
        case 'bytes':
          return (await this.#readCapped(res, opts)) as T;
        case 'text':
          return new TextDecoder('utf-8').decode(await this.#readCapped(res, opts)) as T;
        case 'json': {
          const text = new TextDecoder('utf-8').decode(await this.#readCapped(res, opts));
          if (text.trim() === '') return undefined as T;
          try {
            return JSON.parse(text) as T;
          } catch (cause) {
            throw new ApiException(
              { statusCode: res.status, message: 'Response is not valid JSON', method: opts.method, path: opts.path, cause },
              'invalid_response',
            );
          }
        }
      }
    }
    const text = new TextDecoder('utf-8').decode(await this.#readCapped(res, opts));
    let parsed: unknown;
    if (text.trim() !== '') {
      try {
        parsed = JSON.parse(text);
      } catch {
        parsed = undefined;
      }
    }
    throw createApiException({
      statusCode: res.status,
      body: parsed,
      retryAfterSeconds: parseRetryAfter(res.headers.get('retry-after')),
      method: opts.method,
      path: opts.path,
    });
  }
}
