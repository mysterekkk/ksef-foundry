import { ConfigurationException } from './errors.js';

/** Known KSeF environment names. */
export type EnvironmentName = 'TEST' | 'DEMO' | 'PRODUCTION' | 'CUSTOM';

/** Immutable description of a KSeF environment. */
export class Environment {
  /** Public TEST environment (default for all examples/tests). */
  static readonly TEST = new Environment('TEST', 'https://api-test.ksef.mf.gov.pl/v2');
  /** Pre-production DEMO environment. */
  static readonly DEMO = new Environment('DEMO', 'https://api-demo.ksef.mf.gov.pl/v2');
  /** PRODUCTION. Must always be selected explicitly. */
  static readonly PRODUCTION = new Environment('PRODUCTION', 'https://api.ksef.mf.gov.pl/v2');

  readonly name: EnvironmentName;
  /** Base URL without trailing slash, e.g. `https://api-test.ksef.mf.gov.pl/v2`. */
  readonly baseUrl: string;

  private constructor(name: EnvironmentName, baseUrl: string) {
    this.name = name;
    this.baseUrl = baseUrl;
    Object.freeze(this);
  }

  /**
   * UNSAFE FOR REAL DATA. Intended for fault-injection servers and future MF environments.
   * Must be `https`, except loopback hosts (`127.0.0.1`, `::1`, `localhost`) which may use `http`.
   */
  static custom(baseUrl: string): Environment {
    let url: URL;
    try {
      url = new URL(baseUrl);
    } catch (cause) {
      throw new ConfigurationException(`Invalid environment URL: ${baseUrl}`, { cause });
    }
    const loopback = ['127.0.0.1', '[::1]', '::1', 'localhost'].includes(url.hostname);
    if (url.protocol !== 'https:' && !(url.protocol === 'http:' && loopback)) {
      throw new ConfigurationException('Environment.custom requires https (http only for loopback hosts)');
    }
    if (url.username !== '' || url.password !== '') {
      throw new ConfigurationException('Environment URL must not contain credentials');
    }
    const clean = `${url.origin}${url.pathname}`.replace(/\/+$/, '');
    return new Environment('CUSTOM', clean);
  }

  /** Host (with port) of the environment; the only host the SDK will talk to. */
  get host(): string {
    return new URL(this.baseUrl).host;
  }

  get isTest(): boolean {
    return this.name === 'TEST';
  }

  toString(): string {
    return `${this.name} (${this.baseUrl})`;
  }
}
