import { inspect } from 'node:util';
import { ConfigurationException } from './errors.js';

/**
 * Serialisable form of a {@link TokenPair}. It contains SECRETS (JWT access token and refresh token):
 * persist it only in stores you trust (encrypted cache, vault) and never log it.
 */
export interface StoredTokenPair {
  readonly accessToken: string;
  /** Epoch ms of `accessToken.validUntil`. */
  readonly accessTokenExpiresAt: number;
  readonly refreshToken: string;
  /** Epoch ms of `refreshToken.validUntil`. */
  readonly refreshTokenExpiresAt: number;
}

const REDACTED = '[REDACTED]';

/**
 * JWT pair kept by {@link TokenStorage}. The long-lived KSeF token / private key never reach storage.
 *
 * Secrets are NOT leaked by `console.log`, `util.inspect`, `JSON.stringify` or `String()`: those print `[REDACTED]`.
 * `pair.accessToken` / `pair.refreshToken` still return the raw strings (old accessors keep working).
 * To persist a pair use {@link TokenPair.toStorage} (plain JSON-able object) and restore it with
 * {@link TokenPair.fromStorage}; never `JSON.stringify(pair)` (that yields redacted placeholders on purpose).
 */
export class TokenPair {
  readonly #access: string;
  readonly #refresh: string;
  readonly accessTokenExpiresAt: number;
  readonly refreshTokenExpiresAt: number;

  constructor(init: StoredTokenPair) {
    this.#access = init.accessToken;
    this.#refresh = init.refreshToken;
    this.accessTokenExpiresAt = init.accessTokenExpiresAt;
    this.refreshTokenExpiresAt = init.refreshTokenExpiresAt;
  }

  get accessToken(): string {
    return this.#access;
  }

  get refreshToken(): string {
    return this.#refresh;
  }

  /** Plain object with the raw tokens for persistence. Treat the result as a secret. */
  toStorage(): StoredTokenPair {
    return {
      accessToken: this.#access,
      accessTokenExpiresAt: this.accessTokenExpiresAt,
      refreshToken: this.#refresh,
      refreshTokenExpiresAt: this.refreshTokenExpiresAt,
    };
  }

  /**
   * Restores a pair from {@link TokenPair.toStorage} output (object or parsed JSON). Throws when the value is malformed or
   * was produced by `JSON.stringify(pair)` (redacted placeholders).
   */
  static fromStorage(value: unknown): TokenPair {
    if (value instanceof TokenPair) return value;
    const v = value as Partial<Record<keyof StoredTokenPair, unknown>> | null;
    if (
      v === null ||
      typeof v !== 'object' ||
      typeof v.accessToken !== 'string' ||
      typeof v.refreshToken !== 'string' ||
      typeof v.accessTokenExpiresAt !== 'number' ||
      typeof v.refreshTokenExpiresAt !== 'number'
    ) {
      throw new ConfigurationException('Stored token pair is malformed');
    }
    if (v.accessToken === REDACTED || v.refreshToken === REDACTED) {
      throw new ConfigurationException('Stored token pair holds redacted placeholders: persist TokenPair.toStorage(), not JSON.stringify(pair)');
    }
    return new TokenPair({
      accessToken: v.accessToken,
      accessTokenExpiresAt: v.accessTokenExpiresAt,
      refreshToken: v.refreshToken,
      refreshTokenExpiresAt: v.refreshTokenExpiresAt,
    });
  }

  toJSON(): Record<string, unknown> {
    return {
      accessToken: REDACTED,
      accessTokenExpiresAt: this.accessTokenExpiresAt,
      refreshToken: REDACTED,
      refreshTokenExpiresAt: this.refreshTokenExpiresAt,
    };
  }

  toString(): string {
    return REDACTED;
  }

  [inspect.custom](): string {
    return `TokenPair { accessToken: ${REDACTED}, refreshToken: ${REDACTED}, accessTokenExpiresAt: ${String(this.accessTokenExpiresAt)}, refreshTokenExpiresAt: ${String(this.refreshTokenExpiresAt)} }`;
  }
}

/**
 * Pluggable JWT pair store (Laravel cache, Nest cache manager, Redis...).
 * `get` may return a {@link TokenPair} or the plain {@link StoredTokenPair} (e.g. parsed JSON).
 */
export interface TokenStorage {
  get(key: string): Promise<TokenPair | StoredTokenPair | undefined>;
  /** `ttlSeconds` = remaining lifetime of the refresh token. Persist `pair.toStorage()`. */
  put(key: string, pair: TokenPair, ttlSeconds: number): Promise<void>;
  delete(key: string): Promise<void>;
}

/** Default in-process storage. */
export class InMemoryTokenStorage implements TokenStorage {
  readonly #map = new Map<string, { pair: TokenPair; expiresAt: number }>();

  get(key: string): Promise<TokenPair | undefined> {
    const e = this.#map.get(key);
    if (e === undefined) return Promise.resolve(undefined);
    if (e.expiresAt <= Date.now()) {
      this.#map.delete(key);
      return Promise.resolve(undefined);
    }
    return Promise.resolve(e.pair);
  }

  put(key: string, pair: TokenPair, ttlSeconds: number): Promise<void> {
    this.#map.set(key, { pair, expiresAt: Date.now() + ttlSeconds * 1000 });
    return Promise.resolve();
  }

  delete(key: string): Promise<void> {
    this.#map.delete(key);
    return Promise.resolve();
  }

  /** Number of stored entries (diagnostics/tests). */
  get size(): number {
    return this.#map.size;
  }
}
