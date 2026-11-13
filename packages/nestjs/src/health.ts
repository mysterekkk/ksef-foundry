import { KsefException } from '@luroweb/ksef-foundry';
import type { KsefService } from './ksef.service.js';

/** Shape compatible with `@nestjs/terminus` `HealthIndicatorResult` (terminus is not required). */
export type KsefHealthResult = Record<string, { status: 'up' | 'down'; message?: string; environment: string }>;

export interface KsefHealthOptions {
  /**
   * `public-key` (default): unauthenticated `GET /security/public-key-certificates`, uncached, proves
   * reachability + TLS. `rate-limits`: authenticated `GET /rate-limits`, also proves the credentials.
   */
  readonly mode?: 'public-key' | 'rate-limits';
  readonly timeoutMs?: number;
}

/** Optional health check. Usable as `KsefHealthIndicator.check()` or wrapped in a terminus indicator. */
export class KsefHealthIndicator {
  readonly #service: KsefService;
  readonly #options: KsefHealthOptions;

  constructor(service: KsefService, options: KsefHealthOptions = {}) {
    this.#service = service;
    this.#options = options;
  }

  async check(key = 'ksef'): Promise<KsefHealthResult> {
    const environment = this.#service.client.environment.name;
    const timeoutMs = this.#options.timeoutMs ?? 5000;
    const signal = AbortSignal.timeout(timeoutMs);
    try {
      if (this.#options.mode === 'rate-limits') {
        await this.#service.limits.rateLimits(AbortSignal.any([signal, this.#service.shutdownSignal]));
      } else {
        await this.#service.client.http.request({
          method: 'GET',
          path: '/security/public-key-certificates',
          idempotent: true,
          timeoutMs,
          deadlineMs: timeoutMs + 1000,
          signal: AbortSignal.any([signal, this.#service.shutdownSignal]),
        });
      }
      return { [key]: { status: 'up', environment } };
    } catch (e) {
      // only the exception class name: no URLs, no payloads, no secrets
      const message = e instanceof KsefException ? e.name : 'Error';
      return { [key]: { status: 'down', message, environment } };
    }
  }
}
