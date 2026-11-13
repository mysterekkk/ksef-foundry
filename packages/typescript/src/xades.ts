import {
  X509Certificate,
  createHash,
  createPrivateKey,
  createPublicKey,
  createSign,
  randomUUID,
  type KeyObject,
} from 'node:crypto';
import { DOMParser } from '@xmldom/xmldom';
import { ExclusiveCanonicalization } from 'xml-crypto';
import { CertificateException } from './errors.js';
import { SensitiveString } from './sensitive.js';

const DS = 'http://www.w3.org/2000/09/xmldsig#';
const XADES = 'http://uri.etsi.org/01903/v1.3.2#';
const EXC_C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';
const ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
const SHA256_DIGEST = 'http://www.w3.org/2001/04/xmlenc#sha256';

/** Signing material resolved from PEM inputs. */
export interface SigningMaterial {
  readonly certificate: X509Certificate;
  readonly privateKey: KeyObject;
}

/** Parses and cross-checks certificate + private key (PEM). Throws {@link CertificateException}. */
export function loadSigningMaterial(
  certificatePem: string,
  privateKeyPem: string | SensitiveString,
  passphrase?: string | SensitiveString,
): SigningMaterial {
  let certificate: X509Certificate;
  try {
    certificate = new X509Certificate(certificatePem);
  } catch (cause) {
    throw new CertificateException('Certificate PEM does not parse', { cause });
  }
  let privateKey: KeyObject;
  try {
    privateKey = createPrivateKey({
      key: SensitiveString.from(privateKeyPem).reveal(),
      ...(passphrase !== undefined ? { passphrase: SensitiveString.from(passphrase).reveal() } : {}),
    });
  } catch (cause) {
    // never include cause message: it may echo key material details
    throw new CertificateException('Private key could not be loaded (wrong format or passphrase)', {
      cause: cause instanceof Error ? new Error(cause.name) : undefined,
    });
  }
  const now = Date.now();
  if (now < Date.parse(certificate.validFrom) || now > Date.parse(certificate.validTo)) {
    throw new CertificateException('Certificate is not valid at the current time');
  }
  const certPub = certificate.publicKey.export({ type: 'spki', format: 'der' });
  const keyPub = createPublicKeyDer(privateKey);
  if (!Buffer.from(certPub).equals(Buffer.from(keyPub))) {
    throw new CertificateException('Private key does not match the certificate');
  }
  const type = privateKey.asymmetricKeyType;
  if (type === 'rsa') {
    if ((privateKey.asymmetricKeyDetails?.modulusLength ?? 0) < 2048) {
      throw new CertificateException('RSA key must be at least 2048 bits');
    }
  } else if (type === 'ec') {
    const curve = privateKey.asymmetricKeyDetails?.namedCurve;
    if (curve !== 'prime256v1' && curve !== 'secp384r1' && curve !== 'secp521r1') {
      throw new CertificateException('ECDSA key must use P-256, P-384 or P-521');
    }
  } else {
    throw new CertificateException('Only RSA and ECDSA keys are supported for XAdES');
  }
  return { certificate, privateKey };
}

function createPublicKeyDer(privateKey: KeyObject): Uint8Array {
  return createPublicKey(privateKey).export({ type: 'spki', format: 'der' });
}

interface Algo {
  readonly signatureUri: string;
  readonly hash: 'sha256' | 'sha384' | 'sha512';
  readonly ieee: boolean;
}

function pickAlgorithm(key: KeyObject): Algo {
  if (key.asymmetricKeyType === 'rsa') {
    return { signatureUri: 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256', hash: 'sha256', ieee: false };
  }
  const curve = key.asymmetricKeyDetails?.namedCurve;
  if (curve === 'secp384r1') {
    return { signatureUri: 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha384', hash: 'sha384', ieee: true };
  }
  if (curve === 'secp521r1') {
    return { signatureUri: 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha512', hash: 'sha512', ieee: true };
  }
  return { signatureUri: 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256', hash: 'sha256', ieee: true };
}

function esc(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** RFC 4514/2253-style issuer DN from Node's newline separated (certificate order) representation. */
function issuerDn(cert: X509Certificate): string {
  return cert.issuer
    .split('\n')
    .reverse()
    .map((rdn) => {
      const i = rdn.indexOf('=');
      return `${rdn.slice(0, i)}=${rdn.slice(i + 1).replace(/([,+"\\<>;])/g, '\\$1')}`;
    })
    .join(',');
}

function serialDecimal(cert: X509Certificate): string {
  return BigInt(`0x${cert.serialNumber}`).toString(10);
}

function parse(xml: string): Document {
  const errors: string[] = [];
  const doc = new DOMParser({
    errorHandler: {
      warning: () => undefined,
      error: (msg: string) => void errors.push(msg),
      fatalError: (msg: string) => void errors.push(msg),
    },
  }).parseFromString(xml, 'text/xml');
  if (errors.length > 0) throw new CertificateException('Internal error: generated XML is not well-formed');
  return doc;
}

function c14n(node: Element | Document): string {
  return new ExclusiveCanonicalization().process(node, {});
}

export interface SignOptions {
  /** `SigningTime` (default: now). */
  readonly signingTime?: Date;
}

/**
 * Adds an XAdES-BES **enveloped** signature to `xml` (a document without XML declaration issues).
 *
 * - Canonicalisation: Exclusive C14N 1.0; references: enveloped-signature + exc-c14n, SHA-256.
 * - SignedProperties: SigningTime, SigningCertificate (CertDigest SHA-256 + IssuerSerial).
 * - RSA (PKCS#1 v1.5, SHA-256) and ECDSA (R||S, SHA-256/384/512 by curve).
 *
 * Verified live on KSeF TEST (RSA 2048 + ECDSA P-256 self-signed certificates); see docs/dev/findings-ts.md.
 */
export function signXadesBes(xml: string, material: SigningMaterial, options: SignOptions = {}): string {
  const algo = pickAlgorithm(material.privateKey);
  const sigId = `Signature-${randomUUID()}`;
  const propsId = `SignedProperties-${randomUUID()}`;
  const refId = `Reference-${randomUUID()}`;
  const certDer = material.certificate.raw;
  const certDigest = createHash('sha256').update(certDer).digest('base64');
  const signingTime = (options.signingTime ?? new Date()).toISOString().replace(/\.\d{3}Z$/, 'Z');

  const signedPropertiesXml =
    `<xades:SignedProperties xmlns:xades="${XADES}" Id="${propsId}">` +
    `<xades:SignedSignatureProperties>` +
    `<xades:SigningTime>${signingTime}</xades:SigningTime>` +
    `<xades:SigningCertificate><xades:Cert>` +
    `<xades:CertDigest><ds:DigestMethod Algorithm="${SHA256_DIGEST}"/><ds:DigestValue>${certDigest}</ds:DigestValue></xades:CertDigest>` +
    `<xades:IssuerSerial><ds:X509IssuerName>${esc(issuerDn(material.certificate))}</ds:X509IssuerName>` +
    `<ds:X509SerialNumber>${serialDecimal(material.certificate)}</ds:X509SerialNumber></xades:IssuerSerial>` +
    `</xades:Cert></xades:SigningCertificate>` +
    `</xades:SignedSignatureProperties></xades:SignedProperties>`;

  const buildSignature = (docDigest: string, propsDigest: string, sigValue: string): string =>
    `<ds:Signature xmlns:ds="${DS}" Id="${sigId}">` +
    `<ds:SignedInfo>` +
    `<ds:CanonicalizationMethod Algorithm="${EXC_C14N}"/>` +
    `<ds:SignatureMethod Algorithm="${algo.signatureUri}"/>` +
    `<ds:Reference Id="${refId}" URI="">` +
    `<ds:Transforms><ds:Transform Algorithm="${ENVELOPED}"/><ds:Transform Algorithm="${EXC_C14N}"/></ds:Transforms>` +
    `<ds:DigestMethod Algorithm="${SHA256_DIGEST}"/><ds:DigestValue>${docDigest}</ds:DigestValue></ds:Reference>` +
    `<ds:Reference Type="http://uri.etsi.org/01903#SignedProperties" URI="#${propsId}">` +
    `<ds:Transforms><ds:Transform Algorithm="${EXC_C14N}"/></ds:Transforms>` +
    `<ds:DigestMethod Algorithm="${SHA256_DIGEST}"/><ds:DigestValue>${propsDigest}</ds:DigestValue></ds:Reference>` +
    `</ds:SignedInfo>` +
    `<ds:SignatureValue>${sigValue}</ds:SignatureValue>` +
    `<ds:KeyInfo><ds:X509Data><ds:X509Certificate>${Buffer.from(certDer).toString('base64')}</ds:X509Certificate></ds:X509Data></ds:KeyInfo>` +
    `<ds:Object><xades:QualifyingProperties xmlns:xades="${XADES}" Target="#${sigId}">${signedPropertiesXml.replace(` xmlns:xades="${XADES}"`, '')}</xades:QualifyingProperties></ds:Object>` +
    `</ds:Signature>`;

  // Strip an XML declaration for splicing; keep it for output.
  const declMatch = /^\s*(<\?xml[^?]*\?>)\s*/.exec(xml);
  const decl = declMatch?.[1] ?? '';
  const body = declMatch !== null ? xml.slice(declMatch[0].length) : xml.trim();
  const closeIdx = body.lastIndexOf('</');
  if (closeIdx < 0 || !body.startsWith('<')) throw new CertificateException('Document to sign is not an XML element');

  // 1. digest of the enveloped document (document without the Signature) -- exc-c14n of the original.
  const docDigest = createHash('sha256').update(c14n(parse(body)), 'utf8').digest('base64');

  // 2. digest of SignedProperties (canonicalised in its final context).
  const draft = (decl !== '' ? decl : '') + body.slice(0, closeIdx) + buildSignature(docDigest, 'x', 'x') + body.slice(closeIdx);
  const draftDoc = parse(draft);
  const propsEl = findById(draftDoc, propsId);
  const propsDigest = createHash('sha256').update(c14n(propsEl), 'utf8').digest('base64');

  // 3. canonicalise SignedInfo with real digests, sign it.
  const withDigests = body.slice(0, closeIdx) + buildSignature(docDigest, propsDigest, 'x') + body.slice(closeIdx);
  const doc2 = parse(withDigests);
  const signedInfo = doc2.getElementsByTagNameNS(DS, 'SignedInfo').item(0);
  if (signedInfo === null) throw new CertificateException('Internal error: SignedInfo missing');
  const canonical = c14n(signedInfo);
  const signer = createSign(algo.hash);
  signer.update(canonical, 'utf8');
  const sigBytes = signer.sign(algo.ieee ? { key: material.privateKey, dsaEncoding: 'ieee-p1363' } : material.privateKey);
  const sigValue = sigBytes.toString('base64');

  const out = body.slice(0, closeIdx) + buildSignature(docDigest, propsDigest, sigValue) + body.slice(closeIdx);
  return decl !== '' ? `${decl}\n${out}` : out;
}

function findById(doc: Document, id: string): Element {
  const all = doc.getElementsByTagNameNS(XADES, 'SignedProperties');
  for (let i = 0; i < all.length; i++) {
    const el = all.item(i);
    if (el?.getAttribute('Id') === id) return el;
  }
  throw new CertificateException('Internal error: SignedProperties missing');
}
