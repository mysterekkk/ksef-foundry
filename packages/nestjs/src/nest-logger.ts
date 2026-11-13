import { Logger as NestLogger, type LoggerService } from '@nestjs/common';
import { redact, type Logger as SdkLogger } from '@luroweb/ksef-foundry';

/**
 * Adapter from the SDK `Logger` contract to a Nest `LoggerService`.
 * Payloads are passed through the SDK `redact()` again (defence in depth) before serialisation.
 */
export class NestKsefLogger implements SdkLogger {
  readonly #target: LoggerService;
  readonly #context: string | undefined;

  constructor(target?: LoggerService, context = 'Ksef') {
    // an own Nest Logger already carries the context; a user supplied LoggerService gets it as last argument
    this.#target = target ?? new NestLogger(context);
    this.#context = target === undefined ? undefined : context;
  }

  #ctx(): string[] {
    return this.#context === undefined ? [] : [this.#context];
  }

  log(level: 'debug' | 'info' | 'warn' | 'error', event: string, data?: Record<string, unknown>): void {
    const message = data === undefined ? event : `${event} ${JSON.stringify(redact(data))}`;
    switch (level) {
      case 'debug':
        this.#target.debug?.(message, ...this.#ctx());
        break;
      case 'info':
        this.#target.log(message, ...this.#ctx());
        break;
      case 'warn':
        this.#target.warn(message, ...this.#ctx());
        break;
      case 'error':
        this.#target.error(message, undefined, ...this.#ctx());
        break;
    }
  }
}
