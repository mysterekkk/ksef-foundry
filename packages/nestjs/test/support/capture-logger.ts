import type { LoggerService } from '@nestjs/common';

/** Real Nest `LoggerService` that records everything written through any Nest `Logger`. */
export class CaptureLogger implements LoggerService {
  readonly lines: string[] = [];
  #push(level: string, message: unknown, rest: unknown[]): void {
    const text = typeof message === 'string' ? message : JSON.stringify(message);
    this.lines.push(`${level} ${text} ${rest.map((r) => (typeof r === 'string' ? r : JSON.stringify(r))).join(' ')}`);
  }
  log(message: unknown, ...rest: unknown[]): void {
    this.#push('log', message, rest);
  }
  error(message: unknown, ...rest: unknown[]): void {
    this.#push('error', message, rest);
  }
  warn(message: unknown, ...rest: unknown[]): void {
    this.#push('warn', message, rest);
  }
  debug(message: unknown, ...rest: unknown[]): void {
    this.#push('debug', message, rest);
  }
  verbose(message: unknown, ...rest: unknown[]): void {
    this.#push('verbose', message, rest);
  }
  get text(): string {
    return this.lines.join('\n');
  }
}
