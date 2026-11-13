import { createHash } from 'node:crypto';
import type { AuthorizationPolicy } from './auth-request.js';
import { ConfigurationException } from './errors.js';
import type { ContextIdentifier, SubjectIdentifierType } from './identifiers.js';
import { SensitiveString } from './sensitive.js';
import { loadSigningMaterial, type SigningMaterial } from './xades.js';

/** Long-lived KSeF token credentials (secret wrapped in {@link SensitiveString}). */
export class KsefTokenCredentials {
  readonly kind = 'ksef-token' as const;
  readonly token: SensitiveString;
  readonly context: ContextIdentifier;
  readonly authorizationPolicy: AuthorizationPolicy | undefined;

  constructor(token: string | SensitiveString, context: ContextIdentifier, options: { authorizationPolicy?: AuthorizationPolicy } = {}) {
    if (SensitiveString.from(token).length === 0) throw new ConfigurationException('KSeF token must not be empty');
    this.token = SensitiveString.from(token);
    this.context = context;
    this.authorizationPolicy = options.authorizationPolicy;
  }

  /** Non-reversible fingerprint used for the tenant key. */
  fingerprint(): string {
    return createHash('sha256').update(this.token.reveal(), 'utf8').digest('hex');
  }

  toJSON(): Record<string, unknown> {
    return { kind: this.kind, context: this.context, token: '[REDACTED]' };
  }
}

export interface CertificateCredentialsInit {
  readonly certificatePem: string;
  readonly privateKeyPem: string | SensitiveString;
  readonly passphrase?: string | SensitiveString;
  readonly context: ContextIdentifier;
  /** Default `certificateSubject`. */
  readonly subjectIdentifierType?: SubjectIdentifierType;
  readonly authorizationPolicy?: AuthorizationPolicy;
}

/**
 * Certificate credentials for the XAdES login.
 * Only Authentication / qualified / (TEST only) self-signed certificates work.
 * Offline certificates are NOT accepted for authentication.
 *
 * Verified scope (LIVE TEST, api-test.ksef.mf.gov.pl, 2026-11-13): self-signed personal certificate
 * (serialNumber=PNOPL-<pesel>), RSA 2048 and ECDSA P-256, certificateSubject, Nip context.
 * NOT verified: qualified/KSeF certificates, certificateFingerprint, NipVatUe/InternalId contexts, DEMO/PRODUCTION.
 */
export class CertificateCredentials {
  readonly kind = 'certificate' as const;
  readonly context: ContextIdentifier;
  readonly subjectIdentifierType: SubjectIdentifierType;
  readonly authorizationPolicy: AuthorizationPolicy | undefined;
  readonly #material: SigningMaterial;

  constructor(init: CertificateCredentialsInit) {
    this.#material = loadSigningMaterial(init.certificatePem, init.privateKeyPem, init.passphrase);
    this.context = init.context;
    this.subjectIdentifierType = init.subjectIdentifierType ?? 'certificateSubject';
    this.authorizationPolicy = init.authorizationPolicy;
  }

  /** Signing material (internal use by the authentication manager). */
  get material(): SigningMaterial {
    return this.#material;
  }

  /** SHA-256 of the certificate DER (hex). Public information, safe for tenant keys. */
  fingerprint(): string {
    return createHash('sha256').update(this.#material.certificate.raw).digest('hex');
  }

  toJSON(): Record<string, unknown> {
    return { kind: this.kind, context: this.context, fingerprint: this.fingerprint() };
  }
}

export type Credentials = KsefTokenCredentials | CertificateCredentials;
