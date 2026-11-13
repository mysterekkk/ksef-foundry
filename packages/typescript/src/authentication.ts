import { createHash } from 'node:crypto';
import { buildAuthTokenRequest } from './auth-request.js';
import { InMemoryTokenStorage, TokenPair, type TokenStorage } from './auth-storage.js';
import { Crypto } from './crypto.js';
import { CertificateCredentials, KsefTokenCredentials, type Credentials } from './credentials.js';
import {
  AbortException,
  AuthenticationException,
  AuthorizationException,
  TimeoutException,
} from './errors.js';
import type { HttpTransport } from './http.js';
import type { PublicKeyProvider } from './public-keys.js';
import { SensitiveString } from './sensitive.js';
import { signXadesBes } from './xades.js';
import type {
  AuthenticationChallenge,
  AuthenticationInit,
  AuthenticationOperationStatus,
  AuthenticationTokenRefresh,
  AuthenticationTokens,
} from './types.js';

export interface AuthenticationManagerOptions {
  readonly http: HttpTransport;
  readonly publicKeys: PublicKeyProvider;
  /** Default: {@link InMemoryTokenStorage}. */
  readonly storage?: TokenStorage;
  /** Refresh when the access token expires in less than this many seconds. Default 60. */
  readonly expirySkewSeconds?: number;
  /** Initial poll interval of `GET /auth/{ref}`. Default 500 ms (grows to 2 s). */
  readonly pollIntervalMs?: number;
  /** Max time to wait for the authentication to complete. Default 120 s. */
  readonly pollTimeoutMs?: number;
  readonly now?: () => number;
}

/** Statuses of the authentication operation that mean "missing permissions". */
const NO_PERMISSION_AUTH_STATUS = 415;

/**
 * Logs in (KSeF token or XAdES certificate), caches JWTs per tenant, refreshes and re-logs in.
 * Concurrent callers for the same tenant share one in-flight operation (single-flight).
 */
export class AuthenticationManager {
  readonly #http: HttpTransport;
  readonly #publicKeys: PublicKeyProvider;
  readonly #storage: TokenStorage;
  readonly #skewMs: number;
  readonly #pollIntervalMs: number;
  readonly #pollTimeoutMs: number;
  readonly #now: () => number;
  readonly #inflight = new Map<string, Promise<TokenPair>>();

  constructor(options: AuthenticationManagerOptions) {
    this.#http = options.http;
    this.#publicKeys = options.publicKeys;
    this.#storage = options.storage ?? new InMemoryTokenStorage();
    this.#skewMs = (options.expirySkewSeconds ?? 60) * 1000;
    this.#pollIntervalMs = options.pollIntervalMs ?? 500;
    this.#pollTimeoutMs = options.pollTimeoutMs ?? 120_000;
    this.#now = options.now ?? Date.now;
  }

  /** `sha256(env | contextType | contextValue | authMethod | credentialFingerprint)` (hex). */
  tenantKey(credentials: Credentials): string {
    const method = credentials instanceof KsefTokenCredentials ? 'ksef-token' : 'certificate';
    const raw = [
      this.#http.environment.baseUrl,
      credentials.context.type,
      credentials.context.value,
      method,
      credentials.fingerprint(),
    ].join('|');
    return createHash('sha256').update(raw, 'utf8').digest('hex');
  }

  /** A valid access token (JWT) for the credentials. Reuses, refreshes or logs in as needed. */
  async getAccessToken(credentials: Credentials, signal?: AbortSignal): Promise<SensitiveString> {
    const pair = await this.getTokenPair(credentials, signal);
    return new SensitiveString(pair.accessToken);
  }

  /** Same as {@link getAccessToken} but returns the whole pair (for adapters). */
  async getTokenPair(credentials: Credentials, signal?: AbortSignal): Promise<TokenPair> {
    const key = this.tenantKey(credentials);
    let cached: TokenPair | undefined;
    try {
      const raw = await this.#storage.get(key);
      cached = raw === undefined ? undefined : TokenPair.fromStorage(raw);
    } catch {
      // malformed / redacted entry written by a misbehaving storage: drop it and log in again
      await this.#storage.delete(key);
      cached = undefined;
    }
    if (cached !== undefined && cached.accessTokenExpiresAt - this.#now() > this.#skewMs) return cached;

    let flight = this.#inflight.get(key);
    if (flight === undefined) {
      flight = this.#obtain(key, credentials, cached).finally(() => {
        this.#inflight.delete(key);
      });
      this.#inflight.set(key, flight);
    }
    return abortable(flight, signal);
  }

  /** Forgets cached tokens of this tenant (next call logs in again). */
  async invalidate(credentials: Credentials): Promise<void> {
    await this.#storage.delete(this.tenantKey(credentials));
  }

  /** `DELETE /auth/sessions/current` and forgets the tokens. */
  async logout(credentials: Credentials, signal?: AbortSignal): Promise<void> {
    const token = await this.getAccessToken(credentials, signal);
    try {
      await this.#http.request({ method: 'DELETE', path: '/auth/sessions/current', bearer: token, responseType: 'void', signal });
    } finally {
      await this.invalidate(credentials);
    }
  }

  async #obtain(key: string, credentials: Credentials, cached: TokenPair | undefined): Promise<TokenPair> {
    if (cached !== undefined && cached.refreshTokenExpiresAt - this.#now() > this.#skewMs) {
      try {
        return await this.#refresh(key, cached);
      } catch (e) {
        const authFailure = e instanceof AuthenticationException || e instanceof AuthorizationException;
        if (!authFailure) throw e;
        await this.#storage.delete(key);
      }
    } else if (cached !== undefined) {
      await this.#storage.delete(key);
    }
    return this.#login(key, credentials);
  }

  async #refresh(key: string, cached: TokenPair): Promise<TokenPair> {
    const res = await this.#http.request<AuthenticationTokenRefresh>({
      method: 'POST',
      path: '/auth/token/refresh',
      bearer: cached.refreshToken,
      idempotent: false,
    });
    const pair = new TokenPair({
      accessToken: res.data.accessToken.token,
      accessTokenExpiresAt: Date.parse(res.data.accessToken.validUntil),
      refreshToken: cached.refreshToken,
      refreshTokenExpiresAt: cached.refreshTokenExpiresAt,
    });
    await this.#store(key, pair);
    return pair;
  }

  async #login(key: string, credentials: Credentials): Promise<TokenPair> {
    const challenge = (
      await this.#http.request<AuthenticationChallenge>({ method: 'POST', path: '/auth/challenge', idempotent: true })
    ).data;

    let init: AuthenticationInit;
    if (credentials instanceof KsefTokenCredentials) {
      init = await this.#publicKeys.withKey('KsefTokenEncryption', async (pk) => {
        const encryptedToken = Crypto.encryptKsefToken(credentials.token, challenge.timestampMs, pk.publicKey);
        const policy = credentials.authorizationPolicy;
        return (
          await this.#http.request<AuthenticationInit>({
            method: 'POST',
            path: '/auth/ksef-token',
            json: {
              challenge: challenge.challenge,
              contextIdentifier: { type: credentials.context.type, value: credentials.context.value },
              encryptedToken,
              publicKeyId: pk.publicKeyId,
              ...(policy !== undefined ? { authorizationPolicy: policy } : {}),
            },
          })
        ).data;
      });
    } else {
      init = await this.#submitXades(credentials, challenge.challenge);
    }

    await this.#waitForAuthentication(init);

    const tokens = (
      await this.#http.request<AuthenticationTokens>({
        method: 'POST',
        path: '/auth/token/redeem',
        bearer: init.authenticationToken.token,
        idempotent: false, // one-shot: never auto-retried after an ambiguous failure
      })
    ).data;
    const pair = new TokenPair({
      accessToken: tokens.accessToken.token,
      accessTokenExpiresAt: Date.parse(tokens.accessToken.validUntil),
      refreshToken: tokens.refreshToken.token,
      refreshTokenExpiresAt: Date.parse(tokens.refreshToken.validUntil),
    });
    await this.#store(key, pair);
    return pair;
  }

  #submitXades(credentials: CertificateCredentials, challenge: string): Promise<AuthenticationInit> {
    const unsigned = buildAuthTokenRequest({
      challenge,
      context: credentials.context,
      subjectIdentifierType: credentials.subjectIdentifierType,
      ...(credentials.authorizationPolicy !== undefined ? { authorizationPolicy: credentials.authorizationPolicy } : {}),
    });
    const signed = signXadesBes(unsigned, credentials.material);
    return this.#http
      .request<AuthenticationInit>({
        method: 'POST',
        path: '/auth/xades-signature',
        body: signed,
        contentType: 'application/xml',
      })
      .then((r) => r.data);
  }

  async #waitForAuthentication(init: AuthenticationInit): Promise<void> {
    const started = this.#now();
    let interval = this.#pollIntervalMs;
    for (;;) {
      const st = (
        await this.#http.request<AuthenticationOperationStatus>({
          method: 'GET',
          path: `/auth/${encodeURIComponent(init.referenceNumber)}`,
          bearer: init.authenticationToken.token,
        })
      ).data;
      const code = st.status.code;
      if (code === 200) return;
      if (code !== 100) {
        const details = [...(st.status.details ?? [])];
        const message = `Authentication failed: ${String(code)} ${st.status.description}${details.length > 0 ? ` (${details.join('; ')})` : ''}`;
        const base = { message, details, authStatusCode: code, path: `/auth/${init.referenceNumber}` };
        if (code === NO_PERMISSION_AUTH_STATUS) throw new AuthorizationException({ statusCode: 403, ...base });
        throw new AuthenticationException({ statusCode: 401, ...base });
      }
      if (this.#now() - started > this.#pollTimeoutMs) {
        throw new TimeoutException('Authentication did not complete in time (still "in progress")');
      }
      await this.#http.pause(interval);
      interval = Math.min(interval * 1.5, 2000);
    }
  }

  async #store(key: string, pair: TokenPair): Promise<void> {
    const ttl = Math.max(1, Math.floor((pair.refreshTokenExpiresAt - this.#now()) / 1000));
    await this.#storage.put(key, pair, ttl);
  }
}

/** Resolves with `p`, rejects with {@link AbortException} when `signal` aborts first (p keeps running). */
export function abortable<T>(p: Promise<T>, signal: AbortSignal | undefined): Promise<T> {
  if (signal === undefined) return p;
  if (signal.aborted) return Promise.reject(new AbortException('Operation aborted by caller', { cause: signal.reason }));
  return new Promise<T>((resolve, reject) => {
    const onAbort = (): void => {
      reject(new AbortException('Operation aborted by caller', { cause: signal.reason }));
    };
    signal.addEventListener('abort', onAbort, { once: true });
    p.then(
      (v) => {
        signal.removeEventListener('abort', onAbort);
        resolve(v);
      },
      (e: unknown) => {
        signal.removeEventListener('abort', onAbort);
        reject(e instanceof Error ? e : new Error('operation failed'));
      },
    );
  });
}
