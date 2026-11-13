import {
  AuthenticationException,
  AuthorizationException,
  PaginationException,
  type KsefException,
} from './errors.js';
import type { HttpResponse, HttpTransport, RequestOptions } from './http.js';
import type { SensitiveString } from './sensitive.js';

/** Supplies bearer tokens for authenticated calls (bound to one set of credentials). */
export interface AccessTokenSource {
  getAccessToken(signal?: AbortSignal): Promise<SensitiveString>;
  /** Drop cached tokens (called after a 401 so the next call logs in again). */
  invalidate(): Promise<void>;
}

export interface ApiContextOptions {
  /**
   * After a failed (re-)login, or a 401 that a fresh login did not cure, further 401s / logins fail fast for this long
   * instead of triggering a login per call. Default 10000 ms.
   */
  readonly reloginBackoffMs?: number;
}

/** Shared plumbing of the API services: transport + authenticated requests. */
export class ApiContext {
  readonly http: HttpTransport;
  readonly tokens: AccessTokenSource;
  readonly #backoffMs: number;
  #reloginBlockedUntil = 0;
  #loginFailure: { until: number; error: KsefException } | undefined;

  constructor(http: HttpTransport, tokens: AccessTokenSource, options: ApiContextOptions = {}) {
    this.http = http;
    this.tokens = tokens;
    this.#backoffMs = options.reloginBackoffMs ?? 10_000;
  }

  async #token(signal: AbortSignal | undefined): Promise<SensitiveString> {
    if (this.#loginFailure !== undefined) {
      if (Date.now() < this.#loginFailure.until) throw this.#loginFailure.error; // negative cache: no login storm
      this.#loginFailure = undefined;
    }
    try {
      return await this.tokens.getAccessToken(signal);
    } catch (e) {
      if (e instanceof AuthenticationException || e instanceof AuthorizationException) {
        this.#loginFailure = { until: Date.now() + this.#backoffMs, error: e };
      }
      throw e;
    }
  }

  /**
   * Authenticated request. On HTTP 401 the cached tokens are dropped and the request is repeated once with a fresh login
   * (a 401 means the request was not processed, so this is duplicate-safe). At most one re-login per request; when a fresh
   * login does not cure the 401, or the login itself fails, further calls fail fast for `reloginBackoffMs`.
   */
  async call<T = unknown>(opts: Omit<RequestOptions, 'bearer'>): Promise<HttpResponse<T>> {
    const bearer = await this.#token(opts.signal);
    try {
      return await this.http.request<T>({ ...opts, bearer });
    } catch (e) {
      if (e instanceof AuthenticationException && e.statusCode === 401 && e.authStatusCode === undefined) {
        if (Date.now() < this.#reloginBlockedUntil) throw e;
        await this.tokens.invalidate();
        const fresh = await this.#token(opts.signal); // a failing login arms the negative cache
        try {
          const r = await this.http.request<T>({ ...opts, bearer: fresh });
          this.#reloginBlockedUntil = 0;
          return r;
        } catch (e2) {
          if (e2 instanceof AuthenticationException && e2.statusCode === 401) {
            this.#reloginBlockedUntil = Date.now() + this.#backoffMs;
          }
          throw e2;
        }
      }
      throw e;
    }
  }
}

/** Default page ceiling of the lazy iterators. */
export const DEFAULT_MAX_PAGES = 10_000;

/**
 * Generic continuation-token pagination (`x-continuation-token` header / `continuationToken` body).
 * Terminates with {@link PaginationException} on a repeated continuation token or when `maxPages` is exceeded.
 */
export async function* paginate<TPage extends { continuationToken?: string | null }, TItem>(
  fetchPage: (continuationToken: string | undefined) => Promise<TPage>,
  items: (page: TPage) => readonly TItem[],
  options: { maxPages?: number } = {},
): AsyncGenerator<TItem, void, undefined> {
  const maxPages = options.maxPages ?? DEFAULT_MAX_PAGES;
  const seen = new Set<string>();
  let token: string | undefined;
  let pages = 0;
  do {
    if (pages >= maxPages) throw new PaginationException(`Pagination exceeded ${String(maxPages)} pages`, pages);
    const page = await fetchPage(token);
    pages += 1;
    for (const item of items(page)) yield item;
    const next = page.continuationToken;
    token = next !== undefined && next !== null && next !== '' ? next : undefined;
    if (token !== undefined) {
      if (seen.has(token)) throw new PaginationException('Server repeated a continuation token (pagination does not progress)', pages);
      seen.add(token);
    }
  } while (token !== undefined);
}
