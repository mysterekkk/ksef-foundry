import { Injectable, type OnModuleDestroy } from '@nestjs/common';
import { ConfigurationException, type KsefClient } from '@luroweb/ksef-foundry';
import { KsefService } from './ksef.service.js';
import type { KsefModuleOptions } from './options.js';
import { KsefRuntime } from './runtime.js';

/**
 * Process-wide registry of named KSeF clients (multi-tenant). Provided by a global module, so every
 * `KsefModule.forRoot({ name })` and every dynamically registered tenant ends up in the same place.
 * Token caches stay isolated: the SDK keys them by environment, context and credential fingerprint.
 */
@Injectable()
export class KsefClientRegistry implements OnModuleDestroy {
  readonly #runtimes = new Map<string, KsefRuntime>();
  readonly #services = new Map<string, KsefService>();

  /** Registers a runtime created by the module. @internal */
  add(runtime: KsefRuntime): void {
    if (this.#runtimes.has(runtime.name)) {
      throw new ConfigurationException(`A KSeF client named "${runtime.name}" is already registered`);
    }
    this.#runtimes.set(runtime.name, runtime);
    this.#services.set(runtime.name, new KsefService(runtime));
  }

  /** Removes a runtime (called when its module is destroyed). @internal */
  remove(name: string): void {
    this.#runtimes.delete(name);
    this.#services.delete(name);
  }

  /**
   * Creates and registers a client at run time (e.g. tenant credentials loaded from a database).
   * It is closed with the application like every other client.
   */
  register(name: string, options: KsefModuleOptions): KsefService {
    this.add(new KsefRuntime(name, options));
    return this.service(name);
  }

  has(name: string): boolean {
    return this.#runtimes.has(name);
  }

  names(): string[] {
    return [...this.#runtimes.keys()];
  }

  /** The client of tenant `name`. Throws `ConfigurationException` when unknown. */
  get(name: string): KsefClient {
    return this.service(name).client;
  }

  service(name: string): KsefService {
    const s = this.#services.get(name);
    if (s === undefined) throw new ConfigurationException(`No KSeF client registered under "${name}"`);
    return s;
  }

  onModuleDestroy(): Promise<void> {
    return this.closeAll();
  }

  /** Closes dynamically registered and module clients. @internal */
  async closeAll(): Promise<void> {
    const all = [...this.#runtimes.values()];
    this.#runtimes.clear();
    this.#services.clear();
    await Promise.all(all.map((r) => r.onModuleDestroy()));
  }
}
