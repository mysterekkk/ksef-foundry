import { Logger as NestLogger, type OnModuleDestroy } from '@nestjs/common';
import { KsefClient, type Logger as SdkLogger } from '@luroweb/ksef-foundry';
import { NestKsefLogger } from './nest-logger.js';
import type { KsefModuleOptions } from './options.js';

/**
 * Owns one long-lived `KsefClient` plus the shutdown `AbortController` shared by all its requests.
 * Created once per registration (never per HTTP request).
 */
export class KsefRuntime implements OnModuleDestroy {
  readonly name: string;
  readonly client: KsefClient;
  readonly #controller = new AbortController();
  readonly #options: KsefModuleOptions;
  #closed = false;

  constructor(name: string, options: KsefModuleOptions) {
    this.name = name;
    this.#options = options;
    const logger = options.logger;
    const sdkLogger: SdkLogger | undefined = logger === 'nest' ? new NestKsefLogger(undefined, 'Ksef') : logger;
    this.client = KsefClient.create({
      credentials: options.credentials,
      ...(options.environment !== undefined ? { environment: options.environment } : {}),
      ...(options.storage !== undefined ? { storage: options.storage } : {}),
      ...(options.timeoutMs !== undefined ? { timeoutMs: options.timeoutMs } : {}),
      ...(options.deadlineMs !== undefined ? { deadlineMs: options.deadlineMs } : {}),
      ...(options.retry !== undefined ? { retry: options.retry } : {}),
      ...(options.expirySkewSeconds !== undefined ? { expirySkewSeconds: options.expirySkewSeconds } : {}),
      ...(options.allowedHosts !== undefined ? { allowedHosts: options.allowedHosts } : {}),
      ...(options.fetch !== undefined ? { fetch: options.fetch } : {}),
      // client-level abort: in-flight and future requests/waits of this client, never retried
      signal: options.signal === undefined ? this.#controller.signal : AbortSignal.any([options.signal, this.#controller.signal]),
      ...(sdkLogger !== undefined ? { logger: sdkLogger } : {}),
    });
  }

  /** Aborts when the application shuts down. Combine with caller signals via `AbortSignal.any`. */
  get shutdownSignal(): AbortSignal {
    return this.#controller.signal;
  }

  get closed(): boolean {
    return this.#closed;
  }

  get invoiceValidator(): KsefModuleOptions['invoiceValidator'] {
    return this.#options.invoiceValidator;
  }

  /** Optional logout (only when `logoutOnShutdown`), then aborts everything in flight. Idempotent. */
  async onModuleDestroy(): Promise<void> {
    if (this.#closed) return;
    this.#closed = true;
    try {
      if (this.#options.logoutOnShutdown === true) {
        await this.client.logout(AbortSignal.timeout(this.#options.shutdownLogoutTimeoutMs ?? 5000)).catch((e: unknown) => {
          new NestLogger('Ksef').warn(`logout on shutdown failed: ${e instanceof Error ? e.name : 'error'}`);
        });
      }
    } finally {
      this.#controller.abort(new Error('KSeF module is shutting down'));
    }
  }
}
