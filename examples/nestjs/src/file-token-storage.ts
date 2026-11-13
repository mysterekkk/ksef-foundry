import { chmod, mkdir, readFile, rename, writeFile } from 'node:fs/promises';
import { dirname } from 'node:path';
import { TokenPair, type StoredTokenPair, type TokenStorage } from '@luroweb/ksef-foundry';

interface Entry {
  /** Raw JWT pair (`TokenPair.toStorage()`): a secret, hence the 0600 file. */
  readonly pair: StoredTokenPair;
  readonly expiresAt: number;
}

/**
 * Example custom `TokenStorage`: keeps the JWT pair in a JSON file so a restart does not force a new login.
 *
 * The SDK only ever hands over the short-lived JWT pair (never the long-lived KSeF token or a private key),
 * but a refresh token is still a credential for up to 7 days: in production use an encrypted cache or a
 * secret store (Redis with TLS + ACL, Vault, ...) instead of a plain file. Enabled with `KSEF_TOKEN_CACHE_FILE`.
 */
export class FileTokenStorage implements TokenStorage {
  readonly #path: string;
  #chain: Promise<unknown> = Promise.resolve();

  constructor(path: string) {
    this.#path = path;
  }

  async #read(): Promise<Record<string, Entry>> {
    try {
      return JSON.parse(await readFile(this.#path, 'utf8')) as Record<string, Entry>;
    } catch {
      return {};
    }
  }

  async #write(data: Record<string, Entry>): Promise<void> {
    await mkdir(dirname(this.#path), { recursive: true });
    const tmp = `${this.#path}.tmp`;
    await writeFile(tmp, JSON.stringify(data), { mode: 0o600 });
    await rename(tmp, this.#path);
    await chmod(this.#path, 0o600).catch(() => undefined);
  }

  /** Serialises read-modify-write cycles inside this process. */
  #exclusive<T>(op: () => Promise<T>): Promise<T> {
    const next = this.#chain.then(op, op);
    this.#chain = next.catch(() => undefined);
    return next;
  }

  get(key: string): Promise<TokenPair | undefined> {
    return this.#exclusive(async () => {
      const entry = (await this.#read())[key];
      return entry !== undefined && entry.expiresAt > Date.now() ? TokenPair.fromStorage(entry.pair) : undefined;
    });
  }

  put(key: string, pair: TokenPair, ttlSeconds: number): Promise<void> {
    return this.#exclusive(async () => {
      const data = await this.#read();
      data[key] = { pair: pair.toStorage(), expiresAt: Date.now() + ttlSeconds * 1000 }; // never JSON.stringify(pair): it prints [REDACTED]
      await this.#write(data);
    });
  }

  delete(key: string): Promise<void> {
    return this.#exclusive(async () => {
      const data = await this.#read();
      delete data[key];
      await this.#write(data);
    });
  }
}
