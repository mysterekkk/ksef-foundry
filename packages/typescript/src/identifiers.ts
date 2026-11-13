import { ConfigurationException } from './errors.js';

/** Context identifier types accepted by `AuthenticationContextIdentifier` (OpenAPI). */
export const ContextIdentifierType = {
  Nip: 'Nip',
  InternalId: 'InternalId',
  NipVatUe: 'NipVatUe',
  PeppolId: 'PeppolId',
} as const;
export type ContextIdentifierType = (typeof ContextIdentifierType)[keyof typeof ContextIdentifierType];

/** Context in which operations are executed (taxpayer NIP, internal id, ...). */
export interface ContextIdentifier {
  readonly type: ContextIdentifierType;
  readonly value: string;
}

/** Helpers building {@link ContextIdentifier} values. */
export const Context = {
  nip(value: string): ContextIdentifier {
    if (!/^[1-9]((\d[1-9])|([1-9]\d))\d{7}$/.test(value)) {
      throw new ConfigurationException('Invalid NIP format (10 digits, pattern from OpenAPI)');
    }
    return { type: 'Nip', value };
  },
  internalId(value: string): ContextIdentifier {
    return { type: 'InternalId', value };
  },
  nipVatUe(value: string): ContextIdentifier {
    return { type: 'NipVatUe', value };
  },
  peppolId(value: string): ContextIdentifier {
    return { type: 'PeppolId', value };
  },
} as const;

/** Invoice schema selector sent on `POST /sessions/online`. */
export interface FormCode {
  readonly systemCode: string;
  readonly schemaVersion: string;
  readonly value: string;
}

/** Well known form codes (values taken from OpenAPI `OpenOnlineSessionRequest.formCode`). */
export const FormCodes = {
  FA3: { systemCode: 'FA (3)', schemaVersion: '1-0E', value: 'FA' },
  FA2: { systemCode: 'FA (2)', schemaVersion: '1-0E', value: 'FA' },
} as const satisfies Record<string, FormCode>;

/** Subject types of invoice metadata queries. */
export const InvoiceSubjectType = {
  Seller: 'Subject1',
  Buyer: 'Subject2',
  Subject3: 'Subject3',
  Authorized: 'SubjectAuthorized',
} as const;
export type InvoiceSubjectType = (typeof InvoiceSubjectType)[keyof typeof InvoiceSubjectType];

/** Certificate subject identifier type (AuthTokenRequest `SubjectIdentifierType`). */
export type SubjectIdentifierType = 'certificateSubject' | 'certificateFingerprint';
