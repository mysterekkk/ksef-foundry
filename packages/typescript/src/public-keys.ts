import { X509Certificate, createHash, type KeyObject } from 'node:crypto';
import { EncryptionException, ValidationException } from './errors.js';
import type { HttpTransport } from './http.js';

/** Key usages published by KSeF. */
export type PublicKeyUsage = 'KsefTokenEncryption' | 'SymmetricKeyEncryption';

/** Entry of `GET /security/public-key-certificates`. */
export interface PublicKeyCertificateInfo {
  readonly certificate: string;
  readonly certificateId: string;
  readonly publicKeyId: string;
  readonly validFrom: string;
  readonly validTo: string;
  readonly usage: readonly string[];
}

/** A validated key ready for encryption. */
export interface ResolvedPublicKey {
  readonly publicKeyId: string;
  readonly certificateId: string;
  readonly usage: PublicKeyUsage;
  readonly publicKey: KeyObject;
  readonly validFrom: Date;
  readonly validTo: Date;
}

export interface PublicKeyProviderOptions {
  /** Cache lifetime in ms. Default 1 hour. */
  readonly ttlMs?: number;
  readonly now?: () => number;
}

/** KSeF error code: unknown/withdrawn `publicKeyId`. */
export const ERROR_CODE_UNKNOWN_PUBLIC_KEY = 21470;

/** Fetches, validates and caches KSeF public keys (rotation aware). */
export class PublicKeyProvider {
  readonly #http: HttpTransport;
  readonly #ttlMs: number;
  readonly #now: () => number;
  #cache: { at: number; entries: readonly PublicKeyCertificateInfo[] } | undefined;
  #inflight: Promise<readonly PublicKeyCertificateInfo[]> | undefined;

  constructor(http: HttpTransport, options: PublicKeyProviderOptions = {}) {
    this.#http = http;
    this.#ttlMs = options.ttlMs ?? 3_600_000;
    this.#now = options.now ?? Date.now;
  }

  /** Drops the cache (e.g. after error 21470). */
  invalidate(): void {
    this.#cache = undefined;
  }

  async #list(signal?: AbortSignal): Promise<readonly PublicKeyCertificateInfo[]> {
    if (this.#cache !== undefined && this.#now() - this.#cache.at < this.#ttlMs) return this.#cache.entries;
    this.#inflight ??= this.#http
      .request<PublicKeyCertificateInfo[]>({ method: 'GET', path: '/security/public-key-certificates', signal })
      .then((r) => {
        if (!Array.isArray(r.data)) throw new EncryptionException('Unexpected public-key-certificates response');
        this.#cache = { at: this.#now(), entries: r.data };
        return r.data;
      })
      .finally(() => {
        this.#inflight = undefined;
      });
    return this.#inflight;
  }

  /** Best valid key for the usage: valid now, latest `validFrom` wins. */
  async getKey(usage: PublicKeyUsage, signal?: AbortSignal): Promise<ResolvedPublicKey> {
    const entries = await this.#list(signal);
    return selectPublicKey(entries, usage, this.#now());
  }

  /**
   * Runs `op` with the current key; on KSeF error 21470 refetches the keys once and retries once.
   */
  async withKey<T>(usage: PublicKeyUsage, op: (key: ResolvedPublicKey) => Promise<T>, signal?: AbortSignal): Promise<T> {
    const key = await this.getKey(usage, signal);
    try {
      return await op(key);
    } catch (e) {
      if (e instanceof ValidationException && e.ksefCodes.includes(ERROR_CODE_UNKNOWN_PUBLIC_KEY)) {
        this.invalidate();
        const fresh = await this.getKey(usage, signal);
        return op(fresh);
      }
      throw e;
    }
  }
}

/** Pure selection + validation (exported for tests). */
export function selectPublicKey(
  entries: readonly PublicKeyCertificateInfo[],
  usage: PublicKeyUsage,
  nowMs: number,
): ResolvedPublicKey {
  const candidates: { entry: PublicKeyCertificateInfo; from: number; to: number }[] = [];
  for (const entry of entries) {
    if (!entry.usage.includes(usage)) continue;
    const from = Date.parse(entry.validFrom);
    const to = Date.parse(entry.validTo);
    if (Number.isNaN(from) || Number.isNaN(to)) continue;
    if (from <= nowMs && nowMs <= to) candidates.push({ entry, from, to });
  }
  candidates.sort((a, b) => b.from - a.from);
  const errors: string[] = [];
  for (const c of candidates) {
    try {
      return validateEntry(c.entry, usage, c.from, c.to, nowMs);
    } catch (e) {
      errors.push(e instanceof Error ? e.message : 'invalid key');
    }
  }
  throw new EncryptionException(
    `No valid KSeF public key for usage ${usage}${errors.length > 0 ? ` (${errors.join('; ')})` : ''}`,
  );
}

function validateEntry(
  entry: PublicKeyCertificateInfo,
  usage: PublicKeyUsage,
  from: number,
  to: number,
  nowMs: number,
): ResolvedPublicKey {
  let cert: X509Certificate;
  try {
    cert = new X509Certificate(Buffer.from(entry.certificate, 'base64'));
  } catch (cause) {
    throw new EncryptionException('Public key certificate does not parse', { cause });
  }
  if (nowMs < Date.parse(cert.validFrom) || nowMs > Date.parse(cert.validTo)) {
    throw new EncryptionException('Public key certificate is outside its validity period');
  }
  const key = cert.publicKey;
  if (key.asymmetricKeyType !== 'rsa') throw new EncryptionException('Public key is not RSA');
  const bits = key.asymmetricKeyDetails?.modulusLength ?? 0;
  if (bits < 2048) throw new EncryptionException(`RSA modulus too small (${String(bits)} bits)`);
  const spki = key.export({ type: 'spki', format: 'der' });
  const computed = createHash('sha256').update(spki).digest('base64');
  if (computed !== entry.publicKeyId) {
    throw new EncryptionException('publicKeyId does not match SHA-256 of the certificate SubjectPublicKeyInfo');
  }
  return {
    publicKeyId: entry.publicKeyId,
    certificateId: entry.certificateId,
    usage,
    publicKey: key,
    validFrom: new Date(from),
    validTo: new Date(to),
  };
}
