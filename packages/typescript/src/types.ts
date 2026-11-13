/**
 * Curated response/request DTOs of the KSeF API 2.0 (derived from schemas/openapi/test.json).
 * Responses tolerate additive unknown fields: every interface is open (extra properties may
 * exist at runtime). Date-time fields are ISO-8601 strings exactly as returned by the API.
 */
import { inspect } from 'node:util';
import type { ContextIdentifier } from './identifiers.js';
import { SensitiveString } from './sensitive.js';

export type IsoDateTime = string;

export interface StatusInfo {
  readonly code: number;
  readonly description: string;
  readonly details?: readonly string[] | null;
}

export interface TokenInfo {
  readonly token: string;
  readonly validUntil: IsoDateTime;
}

export interface AuthenticationChallenge {
  readonly challenge: string;
  readonly timestamp: IsoDateTime;
  readonly timestampMs: number;
  readonly clientIp: string;
}

export interface AuthenticationInit {
  readonly referenceNumber: string;
  readonly authenticationToken: TokenInfo;
}

export const AuthenticationMethod = {
  Token: 'Token',
  TrustedProfile: 'TrustedProfile',
  InternalCertificate: 'InternalCertificate',
  QualifiedSignature: 'QualifiedSignature',
  QualifiedSeal: 'QualifiedSeal',
  PersonalSignature: 'PersonalSignature',
  PeppolSignature: 'PeppolSignature',
} as const;
export type AuthenticationMethod = (typeof AuthenticationMethod)[keyof typeof AuthenticationMethod];

export interface AuthenticationMethodInfo {
  readonly category: 'XadesSignature' | 'NationalNode' | 'Token' | 'Other' | (string & {});
  readonly code: string;
  readonly displayName: string;
}

export interface AuthenticationOperationStatus {
  readonly startDate: IsoDateTime;
  readonly authenticationMethod: AuthenticationMethod | (string & {});
  readonly authenticationMethodInfo: AuthenticationMethodInfo;
  readonly status: StatusInfo;
  readonly isTokenRedeemed?: boolean | null;
  readonly lastTokenRefreshDate?: IsoDateTime | null;
  readonly refreshTokenValidUntil?: IsoDateTime | null;
}

export interface AuthenticationTokens {
  readonly accessToken: TokenInfo;
  readonly refreshToken: TokenInfo;
}

export interface AuthenticationTokenRefresh {
  readonly accessToken: TokenInfo;
}

export interface AuthSessionItem extends AuthenticationOperationStatus {
  readonly referenceNumber: string;
  readonly isCurrent?: boolean;
}

export interface AuthSessionPage {
  readonly continuationToken?: string | null;
  readonly items: readonly AuthSessionItem[];
}

// ---- KSeF tokens -----------------------------------------------------------------------------

export const TokenPermission = {
  InvoiceRead: 'InvoiceRead',
  InvoiceWrite: 'InvoiceWrite',
  CredentialsRead: 'CredentialsRead',
  CredentialsManage: 'CredentialsManage',
  SubunitManage: 'SubunitManage',
  EnforcementOperations: 'EnforcementOperations',
  Introspection: 'Introspection',
  CollectiveIdentifierManage: 'CollectiveIdentifierManage',
} as const;
export type TokenPermission = (typeof TokenPermission)[keyof typeof TokenPermission];

export const KsefTokenStatus = {
  Pending: 'Pending',
  Active: 'Active',
  Revoking: 'Revoking',
  Revoked: 'Revoked',
  Failed: 'Failed',
} as const;
export type KsefTokenStatus = (typeof KsefTokenStatus)[keyof typeof KsefTokenStatus];

export interface GenerateTokenRequest {
  readonly permissions: readonly TokenPermission[];
  /** 5..256 characters. */
  readonly description: string;
}

/**
 * Response of `POST /tokens`. The KSeF token is shown by KSeF exactly once: store it safely.
 * `console.log`, `util.inspect`, `JSON.stringify` and `String()` print `[REDACTED]` for the token;
 * `generated.token` (old accessor) and `generated.secret()` still return it.
 */
export class GeneratedKsefToken {
  readonly referenceNumber: string;
  readonly #token: string;

  constructor(init: { referenceNumber: string; token: string }) {
    this.referenceNumber = init.referenceNumber;
    this.#token = init.token;
  }

  /** The raw KSeF token. Secret. */
  get token(): string {
    return this.#token;
  }

  /** The KSeF token wrapped in a {@link SensitiveString} (preferred: pass it to `KsefTokenCredentials`). */
  secret(): SensitiveString {
    return new SensitiveString(this.#token);
  }

  toJSON(): { referenceNumber: string; token: string } {
    return { referenceNumber: this.referenceNumber, token: '[REDACTED]' };
  }

  toString(): string {
    return `GeneratedKsefToken(${this.referenceNumber}, [REDACTED])`;
  }

  [inspect.custom](): string {
    return this.toString();
  }
}

export interface TypedIdentifier {
  readonly type: string;
  readonly value: string;
}

export interface KsefTokenStatusResponse {
  readonly referenceNumber: string;
  readonly authorIdentifier: TypedIdentifier;
  readonly contextIdentifier: TypedIdentifier;
  readonly description: string;
  readonly requestedPermissions: readonly (TokenPermission | (string & {}))[];
  readonly dateCreated: IsoDateTime;
  readonly lastUseDate?: IsoDateTime | null;
  readonly status: KsefTokenStatus | (string & {});
  readonly statusDetails?: readonly string[] | null;
}

export interface KsefTokenPage {
  readonly continuationToken?: string | null;
  readonly tokens: readonly KsefTokenStatusResponse[];
}

// ---- Sessions / invoices ---------------------------------------------------------------------

export interface UpoPage {
  readonly referenceNumber: string;
  readonly downloadUrl: string;
  readonly downloadUrlExpirationDate: IsoDateTime;
}

export interface SessionStatus {
  readonly status: StatusInfo;
  readonly dateCreated: IsoDateTime;
  readonly dateUpdated: IsoDateTime;
  readonly validUntil?: IsoDateTime | null;
  readonly upo?: { readonly pages: readonly UpoPage[] } | null;
  readonly invoiceCount?: number | null;
  readonly successfulInvoiceCount?: number | null;
  readonly failedInvoiceCount?: number | null;
}

export interface InvoiceStatusInfo {
  readonly code: number;
  readonly description: string;
  readonly details?: readonly string[] | null;
  readonly extensions?: Record<string, unknown> | null;
}

export interface SessionInvoice {
  readonly ordinalNumber: number;
  readonly invoiceNumber?: string | null;
  readonly ksefNumber?: string | null;
  readonly referenceNumber: string;
  readonly invoiceHash: string;
  readonly invoiceFileName?: string | null;
  readonly acquisitionDate?: IsoDateTime | null;
  readonly invoicingDate: IsoDateTime;
  readonly permanentStorageDate?: IsoDateTime | null;
  readonly upoDownloadUrl?: string | null;
  readonly upoDownloadUrlExpirationDate?: IsoDateTime | null;
  readonly invoicingMode?: 'Online' | 'Offline' | null;
  readonly status: InvoiceStatusInfo;
}

export interface SessionInvoicesPage {
  readonly continuationToken?: string | null;
  readonly invoices: readonly SessionInvoice[];
}

export const SessionKind = { Online: 'Online', Batch: 'Batch' } as const;
export type SessionKind = (typeof SessionKind)[keyof typeof SessionKind];

export const CommonSessionStatus = {
  InProgress: 'InProgress',
  Succeeded: 'Succeeded',
  Failed: 'Failed',
  Cancelled: 'Cancelled',
} as const;
export type CommonSessionStatus = (typeof CommonSessionStatus)[keyof typeof CommonSessionStatus];

export interface SessionListItem {
  readonly referenceNumber: string;
  readonly status: StatusInfo;
  readonly dateCreated: IsoDateTime;
  readonly dateUpdated: IsoDateTime;
  readonly validUntil?: IsoDateTime | null;
  readonly totalInvoiceCount: number;
  readonly successfulInvoiceCount: number;
  readonly failedInvoiceCount: number;
}

export interface SessionListPage {
  readonly continuationToken?: string | null;
  readonly sessions: readonly SessionListItem[];
}

// ---- Invoice metadata ------------------------------------------------------------------------

export const InvoiceDateType = { Issue: 'Issue', Invoicing: 'Invoicing', PermanentStorage: 'PermanentStorage' } as const;
export type InvoiceDateType = (typeof InvoiceDateType)[keyof typeof InvoiceDateType];

export interface InvoiceMetadata {
  readonly ksefNumber: string;
  readonly invoiceNumber: string;
  readonly issueDate: string;
  readonly invoicingDate: IsoDateTime;
  readonly acquisitionDate: IsoDateTime;
  readonly permanentStorageDate: IsoDateTime;
  readonly seller: { readonly nip: string; readonly name?: string | null };
  readonly buyer: { readonly identifier: { readonly type: string; readonly value?: string | null }; readonly name?: string | null };
  readonly netAmount: number;
  readonly grossAmount: number;
  readonly vatAmount: number;
  readonly currency: string;
  readonly invoicingMode: 'Online' | 'Offline' | (string & {});
  readonly invoiceType: string;
  readonly formCode: { readonly systemCode: string; readonly schemaVersion: string; readonly value: string };
  readonly isSelfInvoicing: boolean;
  readonly hasAttachment: boolean;
  readonly invoiceHash: string;
  readonly hashOfCorrectedInvoice?: string | null;
  readonly thirdSubjects?: readonly unknown[] | null;
  readonly authorizedSubject?: unknown;
}

export interface InvoiceMetadataPage {
  readonly hasMore: boolean;
  readonly isTruncated: boolean;
  readonly permanentStorageHwmDate?: IsoDateTime | null;
  readonly invoices: readonly InvoiceMetadata[];
}

// ---- Limits ----------------------------------------------------------------------------------

export interface SessionLimits {
  readonly maxInvoiceSizeInMB: number;
  readonly maxInvoiceWithAttachmentSizeInMB: number;
  readonly maxInvoices: number;
}

export interface ContextLimits {
  readonly onlineSession: SessionLimits;
  readonly batchSession: SessionLimits;
  readonly collectiveIdentifier?: unknown;
}

export interface SubjectLimits {
  readonly enrollment?: { readonly maxEnrollments?: number } | null;
  readonly certificate?: { readonly maxCertificates?: number } | null;
}

export interface RateLimitValues {
  readonly perSecond: number;
  readonly perMinute: number;
  readonly perHour: number;
}

export type RateLimits = Readonly<Record<string, RateLimitValues>>;

// ---- Helpers ---------------------------------------------------------------------------------

export type { ContextIdentifier };
