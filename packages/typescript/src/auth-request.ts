import { ConfigurationException } from './errors.js';
import type { ContextIdentifier, SubjectIdentifierType } from './identifiers.js';

/** Namespace of `schemat_auth_v2-1.xsd`. */
export const AUTH_TOKEN_REQUEST_NS = 'http://ksef.mf.gov.pl/auth/token/2.1';

/** AuthorizationPolicy (allowed client IPs) shared by the XML and the JSON login flows. */
export interface AuthorizationPolicy {
  readonly allowedIps?: {
    readonly ip4Addresses?: readonly string[];
    readonly ip4Ranges?: readonly string[];
    readonly ip4Masks?: readonly string[];
  };
}

export interface AuthTokenRequestInput {
  readonly challenge: string;
  readonly context: ContextIdentifier;
  readonly subjectIdentifierType: SubjectIdentifierType;
  readonly authorizationPolicy?: AuthorizationPolicy;
  /** Override only when KSeF changes the namespace. Default {@link AUTH_TOKEN_REQUEST_NS}. */
  readonly namespace?: string;
}

function esc(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/** Builds the unsigned `AuthTokenRequest` XML document (UTF-8, no pretty printing). */
export function buildAuthTokenRequest(input: AuthTokenRequestInput): string {
  if (!/^\d{8}-CR-[A-F0-9]{10}-[A-F0-9]{10}-[A-F0-9]{2}$/.test(input.challenge)) {
    throw new ConfigurationException('Challenge does not match the AuthTokenRequest pattern');
  }
  const ctxElement = input.context.type; // Nip | InternalId | NipVatUe | PeppolId == XSD element names
  let policy = '';
  const ips = input.authorizationPolicy?.allowedIps;
  if (ips !== undefined) {
    policy =
      '<AuthorizationPolicy><AllowedIps>' +
      (ips.ip4Addresses ?? []).map((v) => `<Ip4Address>${esc(v)}</Ip4Address>`).join('') +
      (ips.ip4Ranges ?? []).map((v) => `<Ip4Range>${esc(v)}</Ip4Range>`).join('') +
      (ips.ip4Masks ?? []).map((v) => `<Ip4Mask>${esc(v)}</Ip4Mask>`).join('') +
      '</AllowedIps></AuthorizationPolicy>';
  }
  return (
    `<?xml version="1.0" encoding="utf-8"?>` +
    `<AuthTokenRequest xmlns="${input.namespace ?? AUTH_TOKEN_REQUEST_NS}">` +
    `<Challenge>${esc(input.challenge)}</Challenge>` +
    `<ContextIdentifier><${ctxElement}>${esc(input.context.value)}</${ctxElement}></ContextIdentifier>` +
    `<SubjectIdentifierType>${input.subjectIdentifierType}</SubjectIdentifierType>` +
    policy +
    `</AuthTokenRequest>`
  );
}
