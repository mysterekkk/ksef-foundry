import { AuthenticationManager } from './authentication.js';
import type { TokenStorage } from './auth-storage.js';
import { ApiContext } from './api-context.js';
import type { Credentials } from './credentials.js';
import { Environment } from './environment.js';
import { HttpTransport, type FetchLike } from './http.js';
import {
  InvoiceQueryService,
  InvoiceSender,
  InvoiceService,
  UpoService,
} from './invoices.js';
import type { Logger } from './logger.js';
import { PublicKeyProvider } from './public-keys.js';
import type { RetryPolicy } from './retry.js';
import { InvoiceSessionService } from './sessions.js';
import { AuthSessionService, KsefTokenService, LimitsService, TestData } from './services-basic.js';

export interface KsefClientOptions {
  /** Default: {@link Environment.TEST}. PRODUCTION must be selected explicitly. */
  readonly environment?: Environment;
  readonly credentials: Credentials;
  readonly fetch?: FetchLike;
  readonly storage?: TokenStorage;
  readonly logger?: Logger;
  readonly timeoutMs?: number;
  readonly deadlineMs?: number;
  readonly retry?: Partial<RetryPolicy>;
  readonly expirySkewSeconds?: number;
  readonly allowedHosts?: readonly string[];
  /** Aborts all in-flight and future requests and waits of this client. Abort is never retried. */
  readonly signal?: AbortSignal;
  /** Fail-fast window after a failed login / a 401 that a fresh login did not cure. Default 10000 ms. */
  readonly reloginBackoffMs?: number;
}

/** Composition root: wires transport, authentication and all services for one set of credentials. */
export class KsefClient {
  readonly environment: Environment;
  readonly http: HttpTransport;
  readonly publicKeys: PublicKeyProvider;
  readonly authentication: AuthenticationManager;
  readonly credentials: Credentials;
  readonly tokens: KsefTokenService;
  readonly sessions: InvoiceSessionService;
  readonly sender: InvoiceSender;
  readonly invoices: InvoiceService;
  readonly invoiceQuery: InvoiceQueryService;
  readonly upo: UpoService;
  readonly authSessions: AuthSessionService;
  readonly limits: LimitsService;
  readonly testData: TestData;

  private constructor(options: KsefClientOptions) {
    this.environment = options.environment ?? Environment.TEST;
    this.credentials = options.credentials;
    this.http = new HttpTransport({
      environment: this.environment,
      ...(options.fetch !== undefined ? { fetch: options.fetch } : {}),
      ...(options.logger !== undefined ? { logger: options.logger } : {}),
      ...(options.timeoutMs !== undefined ? { timeoutMs: options.timeoutMs } : {}),
      ...(options.deadlineMs !== undefined ? { deadlineMs: options.deadlineMs } : {}),
      ...(options.retry !== undefined ? { retry: options.retry } : {}),
      ...(options.allowedHosts !== undefined ? { allowedHosts: options.allowedHosts } : {}),
      ...(options.signal !== undefined ? { signal: options.signal } : {}),
    });
    this.publicKeys = new PublicKeyProvider(this.http);
    this.authentication = new AuthenticationManager({
      http: this.http,
      publicKeys: this.publicKeys,
      ...(options.storage !== undefined ? { storage: options.storage } : {}),
      ...(options.expirySkewSeconds !== undefined ? { expirySkewSeconds: options.expirySkewSeconds } : {}),
    });
    const creds = options.credentials;
    const auth = this.authentication;
    const ctx = new ApiContext(
      this.http,
      {
      getAccessToken: (signal) => auth.getAccessToken(creds, signal),
      invalidate: () => auth.invalidate(creds),
      },
      options.reloginBackoffMs !== undefined ? { reloginBackoffMs: options.reloginBackoffMs } : {},
    );
    this.tokens = new KsefTokenService(ctx);
    this.sessions = new InvoiceSessionService(ctx, this.publicKeys);
    this.sender = new InvoiceSender(this.sessions);
    this.invoices = new InvoiceService(ctx);
    this.invoiceQuery = new InvoiceQueryService(ctx);
    this.upo = new UpoService(ctx);
    this.authSessions = new AuthSessionService(ctx);
    this.limits = new LimitsService(ctx);
    this.testData = new TestData(ctx);
  }

  /** Creates a client. Nothing is sent over the network until the first call. */
  static create(options: KsefClientOptions): KsefClient {
    return new KsefClient(options);
  }

  /** Valid access token (JWT) for the configured credentials. */
  getAccessToken(signal?: AbortSignal): ReturnType<AuthenticationManager['getAccessToken']> {
    return this.authentication.getAccessToken(this.credentials, signal);
  }

  /** Revokes the current authentication session and forgets cached tokens. */
  logout(signal?: AbortSignal): Promise<void> {
    return this.authentication.logout(this.credentials, signal);
  }
}
