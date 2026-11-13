/** Retry policy knobs (contract section 6). */
export interface RetryPolicy {
  /** Total attempts including the first one. Default 4. */
  readonly maxAttempts: number;
  /** First backoff step in ms. Default 400. */
  readonly baseDelayMs: number;
  /** Backoff cap in ms. Default 10000. */
  readonly maxDelayMs: number;
  /** A `Retry-After` larger than this is not waited for; the 429 is surfaced instead. Default 60. */
  readonly maxRetryAfterSeconds: number;
}

export const defaultRetryPolicy: RetryPolicy = Object.freeze({
  maxAttempts: 4,
  baseDelayMs: 400,
  maxDelayMs: 10_000,
  maxRetryAfterSeconds: 60,
});

/**
 * Parses a `Retry-After` header value: delta-seconds or HTTP-date.
 * Returns whole seconds (>= 0) or `undefined` when absent/unparseable.
 */
export function parseRetryAfter(value: string | null | undefined, now: number = Date.now()): number | undefined {
  if (value === null || value === undefined) return undefined;
  const v = value.trim();
  if (v === '') return undefined;
  if (/^\d+$/.test(v)) return Number.parseInt(v, 10);
  const t = Date.parse(v);
  if (Number.isNaN(t)) return undefined;
  return Math.max(0, Math.ceil((t - now) / 1000));
}

/** Exponential backoff with jitter (50%..100% of the step). `attempt` is 1-based (the attempt that just failed). */
export function backoffDelayMs(attempt: number, policy: RetryPolicy, random: () => number = Math.random): number {
  const step = Math.min(policy.maxDelayMs, policy.baseDelayMs * 2 ** Math.max(0, attempt - 1));
  return Math.round(step * (0.5 + random() * 0.5));
}

/** Abortable sleep. Rejects with the signal reason when aborted. */
export function sleep(ms: number, signal?: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    if (signal?.aborted === true) {
      reject(abortError(signal));
      return;
    }
    const timer = setTimeout(() => {
      signal?.removeEventListener('abort', onAbort);
      resolve();
    }, ms);
    const onAbort = (): void => {
      clearTimeout(timer);
      reject(abortError(signal));
    };
    signal?.addEventListener('abort', onAbort, { once: true });
  });
}

function abortError(signal: AbortSignal | undefined): Error {
  const reason: unknown = signal?.reason;
  return reason instanceof Error ? reason : new Error('aborted');
}
