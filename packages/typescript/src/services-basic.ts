import { ApiContext, paginate } from './api-context.js';
import { ConfigurationException, TimeoutException } from './errors.js';
import { isReferenceNumber, refSeg } from './refs.js';
import { GeneratedKsefToken } from './types.js';
import type {
  AuthSessionItem,
  AuthSessionPage,
  ContextLimits,
  GenerateTokenRequest,
  KsefTokenPage,
  KsefTokenStatus,
  KsefTokenStatusResponse,
  RateLimits,
  SubjectLimits,
} from './types.js';

function continuation(token: string | undefined): Record<string, string> {
  return token !== undefined ? { 'x-continuation-token': token } : {};
}

export interface PageOptions {
  /** API enforces endpoint specific bounds (e.g. 10..100). */
  readonly pageSize?: number;
  readonly continuationToken?: string;
  readonly signal?: AbortSignal;
  /** Page ceiling of the lazy iterators (default 10000); exceeding it throws `PaginationException`. */
  readonly maxPages?: number;
}

export interface TokenListFilter extends PageOptions {
  readonly statuses?: readonly KsefTokenStatus[];
  /** At least 3 characters. */
  readonly description?: string;
  /** At least 3 characters. */
  readonly authorIdentifier?: string;
  readonly authorIdentifierType?: 'Nip' | 'Pesel' | 'Fingerprint';
}

/** Management of long-lived KSeF tokens: `/tokens`. */
export class KsefTokenService {
  readonly #ctx: ApiContext;
  constructor(ctx: ApiContext) {
    this.#ctx = ctx;
  }

  /**
   * `POST /tokens`. Needs an authenticated context created with XAdES.
   * The returned `token` is secret and shown only once.
   */
  async generate(request: GenerateTokenRequest, signal?: AbortSignal): Promise<GeneratedKsefToken> {
    const res = await this.#ctx.call<{ referenceNumber: string; token: string }>({
      method: 'POST',
      path: '/tokens',
      json: { permissions: request.permissions, description: request.description },
      idempotent: false,
      validateResponse: (d) =>
        typeof d === 'object' && d !== null && isReferenceNumber((d as Record<string, unknown>)['referenceNumber']) && typeof (d as Record<string, unknown>)['token'] === 'string',
      signal,
    });
    return new GeneratedKsefToken(res.data);
  }

  /** `GET /tokens/{referenceNumber}`. */
  async get(referenceNumber: string, signal?: AbortSignal): Promise<KsefTokenStatusResponse> {
    return (
      await this.#ctx.call<KsefTokenStatusResponse>({
        method: 'GET',
        path: `/tokens/${refSeg(referenceNumber)}`,
        signal,
      })
    ).data;
  }

  /** `GET /tokens` (one page). */
  async list(filter: TokenListFilter = {}): Promise<KsefTokenPage> {
    return (
      await this.#ctx.call<KsefTokenPage>({
        method: 'GET',
        path: '/tokens',
        query: {
          status: filter.statuses === undefined ? undefined : [...filter.statuses],
          description: filter.description,
          authorIdentifier: filter.authorIdentifier,
          authorIdentifierType: filter.authorIdentifierType,
          pageSize: filter.pageSize,
        },
        headers: continuation(filter.continuationToken),
        signal: filter.signal,
      })
    ).data;
  }

  /** Lazily iterates all tokens following continuation tokens. */
  iterate(filter: Omit<TokenListFilter, 'continuationToken'> = {}): AsyncGenerator<KsefTokenStatusResponse, void, undefined> {
    return paginate(
      (ct) => this.list({ ...filter, ...(ct !== undefined ? { continuationToken: ct } : {}) }),
      (p) => p.tokens,
      filter.maxPages !== undefined ? { maxPages: filter.maxPages } : {},
    );
  }

  /** `DELETE /tokens/{referenceNumber}`. */
  async revoke(referenceNumber: string, signal?: AbortSignal): Promise<void> {
    await this.#ctx.call({
      method: 'DELETE',
      path: `/tokens/${refSeg(referenceNumber)}`,
      responseType: 'void',
      idempotent: true,
      signal,
    });
  }

  /** Polls until the token leaves `Pending` (a new token is not usable before it is `Active`). */
  async awaitActive(
    referenceNumber: string,
    options: { timeoutMs?: number; pollIntervalMs?: number; signal?: AbortSignal } = {},
  ): Promise<KsefTokenStatusResponse> {
    const deadline = Date.now() + (options.timeoutMs ?? 60_000);
    for (;;) {
      const st = await this.get(referenceNumber, options.signal);
      if (st.status !== 'Pending') return st;
      if (Date.now() > deadline) throw new TimeoutException('KSeF token stayed Pending past the timeout');
      await this.#ctx.http.pause(options.pollIntervalMs ?? 500, options.signal);
    }
  }
}

/** `/auth/sessions`: list and revoke authentication sessions. */
export class AuthSessionService {
  readonly #ctx: ApiContext;
  constructor(ctx: ApiContext) {
    this.#ctx = ctx;
  }

  async list(options: PageOptions = {}): Promise<AuthSessionPage> {
    return (
      await this.#ctx.call<AuthSessionPage>({
        method: 'GET',
        path: '/auth/sessions',
        query: { pageSize: options.pageSize },
        headers: continuation(options.continuationToken),
        signal: options.signal,
      })
    ).data;
  }

  iterate(options: Omit<PageOptions, 'continuationToken'> = {}): AsyncGenerator<AuthSessionItem, void, undefined> {
    return paginate(
      (ct) => this.list({ ...options, ...(ct !== undefined ? { continuationToken: ct } : {}) }),
      (p) => p.items, options.maxPages !== undefined ? { maxPages: options.maxPages } : {},
    );
  }

  /** `DELETE /auth/sessions/current`: invalidates the refresh token of the current session. */
  async revokeCurrent(signal?: AbortSignal): Promise<void> {
    await this.#ctx.call({ method: 'DELETE', path: '/auth/sessions/current', responseType: 'void', idempotent: true, signal });
  }

  /** `DELETE /auth/sessions/{referenceNumber}`. */
  async revoke(referenceNumber: string, signal?: AbortSignal): Promise<void> {
    await this.#ctx.call({
      method: 'DELETE',
      path: `/auth/sessions/${refSeg(referenceNumber)}`,
      responseType: 'void',
      idempotent: true,
      signal,
    });
  }
}

/** `/limits/context`, `/limits/subject`, `/rate-limits`. */
export class LimitsService {
  readonly #ctx: ApiContext;
  constructor(ctx: ApiContext) {
    this.#ctx = ctx;
  }

  async context(signal?: AbortSignal): Promise<ContextLimits> {
    return (await this.#ctx.call<ContextLimits>({ method: 'GET', path: '/limits/context', signal })).data;
  }

  async subject(signal?: AbortSignal): Promise<SubjectLimits> {
    return (await this.#ctx.call<SubjectLimits>({ method: 'GET', path: '/limits/subject', signal })).data;
  }

  async rateLimits(signal?: AbortSignal): Promise<RateLimits> {
    return (await this.#ctx.call<RateLimits>({ method: 'GET', path: '/rate-limits', signal })).data;
  }
}

export interface TestPersonInput {
  readonly nip: string;
  readonly pesel: string;
  readonly description?: string;
  readonly isBailiff?: boolean;
  readonly isDeceased?: boolean;
}

export interface TestSubjectInput {
  readonly subjectNip: string;
  readonly subjectType: 'EnforcementAuthority' | 'VatGroup' | 'JST';
  readonly description?: string;
  readonly subunits?: readonly { subjectNip: string; description: string }[];
}

export interface TestPermissionsInput {
  readonly contextNip: string;
  readonly authorized: { readonly type: 'Nip' | 'Pesel' | 'Fingerprint'; readonly value: string };
  readonly permissions: readonly {
    permissionType:
      | 'InvoiceRead'
      | 'InvoiceWrite'
      | 'Introspection'
      | 'CredentialsRead'
      | 'CredentialsManage'
      | 'EnforcementOperations'
      | 'SubunitManage'
      | 'CollectiveIdentifierManage';
    description: string;
  }[];
}

/**
 * `/testdata/*` helpers. TEST ONLY: every call refuses to run against any other environment.
 * These endpoints are unauthenticated.
 */
export class TestData {
  readonly #ctx: ApiContext;
  constructor(ctx: ApiContext) {
    this.#ctx = ctx;
  }

  #post(path: string, json: unknown, signal?: AbortSignal): Promise<unknown> {
    if (!this.#ctx.http.environment.isTest) {
      throw new ConfigurationException('TestData is available only in the TEST environment');
    }
    return this.#ctx.http.request({ method: 'POST', path, json, idempotent: false, responseType: 'void', signal });
  }

  /** `POST /testdata/person`: creates a synthetic taxpayer with owner permissions in the context of `nip`. */
  async createPerson(input: TestPersonInput, signal?: AbortSignal): Promise<void> {
    await this.#post(
      '/testdata/person',
      {
        nip: input.nip,
        pesel: input.pesel,
        description: input.description ?? 'ksef-foundry synthetic taxpayer',
        isBailiff: input.isBailiff ?? false,
        ...(input.isDeceased !== undefined ? { isDeceased: input.isDeceased } : {}),
      },
      signal,
    );
  }

  async removePerson(nip: string, signal?: AbortSignal): Promise<void> {
    await this.#post('/testdata/person/remove', { nip }, signal);
  }

  async createSubject(input: TestSubjectInput, signal?: AbortSignal): Promise<void> {
    await this.#post(
      '/testdata/subject',
      {
        subjectNip: input.subjectNip,
        subjectType: input.subjectType,
        description: input.description ?? 'ksef-foundry synthetic subject',
        ...(input.subunits !== undefined ? { subunits: input.subunits } : {}),
      },
      signal,
    );
  }

  async removeSubject(subjectNip: string, signal?: AbortSignal): Promise<void> {
    await this.#post('/testdata/subject/remove', { subjectNip }, signal);
  }

  async grantPermissions(input: TestPermissionsInput, signal?: AbortSignal): Promise<void> {
    await this.#post(
      '/testdata/permissions',
      {
        contextIdentifier: { type: 'Nip', value: input.contextNip },
        authorizedIdentifier: input.authorized,
        permissions: input.permissions,
      },
      signal,
    );
  }

  async revokePermissions(contextNip: string, authorized: TestPermissionsInput['authorized'], signal?: AbortSignal): Promise<void> {
    await this.#post(
      '/testdata/permissions/revoke',
      { contextIdentifier: { type: 'Nip', value: contextNip }, authorizedIdentifier: authorized },
      signal,
    );
  }

  /** `POST /testdata/attachment`. */
  async enableAttachments(nip: string, signal?: AbortSignal): Promise<void> {
    await this.#post('/testdata/attachment', { nip }, signal);
  }

  /** `POST /testdata/attachment/revoke`. */
  async disableAttachments(nip: string, signal?: AbortSignal): Promise<void> {
    await this.#post('/testdata/attachment/revoke', { nip }, signal);
  }
}
