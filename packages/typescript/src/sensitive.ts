import { inspect } from 'node:util';

const REDACTED = '[REDACTED]';

/**
 * Wrapper for secrets (KSeF token, private key, JWT, refresh token).
 * `toString`, `toJSON` and `util.inspect` all return `[REDACTED]`;
 * the raw value is only available through {@link SensitiveString.reveal}.
 */
export class SensitiveString {
  readonly #value: string;

  constructor(value: string) {
    this.#value = value;
  }

  /** Wrap a plain string (or return the same instance when already wrapped). */
  static from(value: string | SensitiveString): SensitiveString {
    return value instanceof SensitiveString ? value : new SensitiveString(value);
  }

  /** Returns the secret. Never log the result. */
  reveal(): string {
    return this.#value;
  }

  get length(): number {
    return this.#value.length;
  }

  toString(): string {
    return REDACTED;
  }

  toJSON(): string {
    return REDACTED;
  }

  [inspect.custom](): string {
    return REDACTED;
  }
}

/** Headers/keys whose values must never be logged. */
const SENSITIVE_KEYS = /^(authorization|cookie|set-cookie|x-api-key|token|accesstoken|refreshtoken|authenticationtoken|encryptedtoken|encryptedsymmetrickey|encryptedinvoicecontent|initializationvector|privatekey|passphrase|password|secret)$/i;

/** Returns a deep copy of `value` with secret-looking keys replaced by `[REDACTED]`. */
export function redact(value: unknown, depth = 0): unknown {
  if (depth > 8) return REDACTED;
  if (value instanceof SensitiveString) return REDACTED;
  if (Array.isArray(value)) return value.map((v) => redact(v, depth + 1));
  if (value !== null && typeof value === 'object') {
    const out: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(value)) {
      out[k] = SENSITIVE_KEYS.test(k) ? REDACTED : redact(v, depth + 1);
    }
    return out;
  }
  return value;
}

/** Redacts HTTP headers (Authorization etc.). */
export function redactHeaders(headers: Record<string, string>): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of Object.entries(headers)) out[k] = SENSITIVE_KEYS.test(k) ? REDACTED : v;
  return out;
}
