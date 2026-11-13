import type { KsefClientOptions, Logger as SdkLogger } from '@luroweb/ksef-foundry';

/**
 * Options of one KSeF client. Everything of `KsefClient.create` is supported:
 * `environment` (default TEST; PRODUCTION only when set explicitly), `credentials`, `storage`
 * (custom `TokenStorage`), `fetch` (custom transport), `timeoutMs`, `deadlineMs`, `retry`, ...
 */
export interface KsefModuleOptions extends Omit<KsefClientOptions, 'logger'> {
  /**
   * `'nest'` routes SDK events to a Nest `Logger` (context `Ksef`), already redacted by the SDK and
   * redacted once more here. Any SDK `Logger` object is accepted as well. Default: no logging.
   */
  readonly logger?: SdkLogger | 'nest';
  /**
   * Revoke the current authentication session (`client.logout()`) on shutdown.
   * Default `false`: long-lived sessions are left alone.
   */
  readonly logoutOnShutdown?: boolean;
  /** Time budget of the optional logout on shutdown. Default 5000 ms. */
  readonly shutdownLogoutTimeoutMs?: number;
  /**
   * Custom pre-flight validator for `KsefService.submitInvoice` (replaces the SDK default, which validates
   * against the bundled FA(3) XSD). Throw to reject.
   */
  readonly invoiceValidator?: (xml: string) => void | Promise<void>;
}

/** Module-level extras that must be known before the options factory runs. */
export interface KsefModuleExtras {
  /** Registration name (tenant). Omit for the default client. */
  readonly name?: string;
  /** Register the module globally. */
  readonly isGlobal?: boolean;
}
