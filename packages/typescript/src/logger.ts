/** Minimal logger contract. Implementations must treat `data` as already redacted. */
export interface Logger {
  log(level: 'debug' | 'info' | 'warn' | 'error', event: string, data?: Record<string, unknown>): void;
}

/** Default logger: does nothing. */
export const noopLogger: Logger = Object.freeze({
  log(): void {
    /* intentionally empty */
  },
});
