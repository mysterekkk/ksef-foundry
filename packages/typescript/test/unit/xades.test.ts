import { createVerify, X509Certificate, createHash } from 'node:crypto';
import { DOMParser } from '@xmldom/xmldom';
import { SignedXml } from 'xml-crypto';
import { describe, expect, it } from 'vitest';
import {
  CertificateException,
  Context,
  SensitiveString,
  TestCertificateFactory,
  buildAuthTokenRequest,
  loadSigningMaterial,
  signXadesBes,
  CertificateCredentials,
} from '../../src/index.js';

const DS = 'http://www.w3.org/2000/09/xmldsig#';
const CHALLENGE = '20261113-CR-4554351000-E39F377DCD-54';

function unsigned(): string {
  return buildAuthTokenRequest({ challenge: CHALLENGE, context: Context.nip('5265877635'), subjectIdentifierType: 'certificateSubject' });
}

/** Independent verification through xml-crypto's verifier (separate code path from the signer). */
function verifyWithXmlCrypto(xml: string, certPem: string): { ok: boolean } {
  const doc = new DOMParser().parseFromString(xml, 'text/xml');
  const sigNode = doc.getElementsByTagNameNS(DS, 'Signature').item(0);
  if (sigNode === null) return { ok: false };
  const sig = new SignedXml({ publicCert: certPem });
  sig.loadSignature(sigNode);
  const ok = sig.checkSignature(xml);
  return { ok };
}

describe.each([
  ['RSA 2048', 'rsa' as const, 'rsa-sha256'],
  ['ECDSA P-256', 'ec' as const, 'ecdsa-sha256'],
])('XAdES-BES enveloped signature (%s)', (_label, keyType, algoSuffix) => {
  it('is structurally XAdES-BES and verifies independently', async () => {
    const cert = await TestCertificateFactory.createPersonal({ serialNumberPrefix: 'PNOPL', identifier: '73122269138', keyType });
    const material = loadSigningMaterial(cert.certificatePem, cert.privateKeyPem);
    const signed = signXadesBes(unsigned(), material, { signingTime: new Date('2026-11-13T20:00:00Z') });

    expect(signed.startsWith('<?xml')).toBe(true);
    expect(signed).toContain(`xmldsig-more#${algoSuffix}`);
    expect(signed).toContain('<xades:SigningTime>2026-11-13T20:00:00Z</xades:SigningTime>');
    expect(signed).toContain('Type="http://uri.etsi.org/01903#SignedProperties"');
    expect(signed).toContain('http://www.w3.org/2000/09/xmldsig#enveloped-signature');
    expect(signed).toContain('http://www.w3.org/2001/10/xml-exc-c14n#');
    expect(signed).toContain('http://www.w3.org/2001/04/xmlenc#sha256');
    // signature is a child of the root (enveloped)
    const doc = new DOMParser().parseFromString(signed, 'text/xml');
    expect(doc.documentElement.localName).toBe('AuthTokenRequest');
    expect(doc.getElementsByTagNameNS(DS, 'Signature').item(0)?.parentNode).toBe(doc.documentElement);

    // CertDigest equals SHA-256 of the DER
    const x = new X509Certificate(cert.certificatePem);
    const digest = createHash('sha256').update(x.raw).digest('base64');
    expect(signed).toContain(`<ds:DigestValue>${digest}</ds:DigestValue>`);

    // xml-crypto cannot verify ECDSA XML signatures; RSA is verified fully here, ECDSA by the node:crypto test below + live KSeF.
    if (keyType === 'rsa') expect(verifyWithXmlCrypto(signed, cert.certificatePem).ok).toBe(true);
  });

  it('detects tampering with the signed content and with SignedProperties', async () => {
    const cert = await TestCertificateFactory.createPersonal({ serialNumberPrefix: 'PNOPL', identifier: '73122269138', keyType });
    const material = loadSigningMaterial(cert.certificatePem, cert.privateKeyPem);
    const signed = signXadesBes(unsigned(), material);
    if (keyType === 'ec') return; // see note above
    expect(verifyWithXmlCrypto(signed, cert.certificatePem).ok).toBe(true); // control: untampered verifies
    expect(verifyWithXmlCrypto(signed.replace('5265877635', '5265877636'), cert.certificatePem).ok).toBe(false);
    expect(verifyWithXmlCrypto(signed.replace(/<xades:SigningTime>[^<]*/, '<xades:SigningTime>2020-01-01T00:00:00Z'), cert.certificatePem).ok).toBe(false);
  });

  it('SignatureValue is a valid signature over canonical SignedInfo (verified with node:crypto)', async () => {
    const cert = await TestCertificateFactory.createPersonal({ serialNumberPrefix: 'PNOPL', identifier: '73122269138', keyType });
    const material = loadSigningMaterial(cert.certificatePem, cert.privateKeyPem);
    const signed = signXadesBes(unsigned(), material);
    const doc = new DOMParser().parseFromString(signed, 'text/xml');
    const sigValue = doc.getElementsByTagNameNS(DS, 'SignatureValue').item(0)?.textContent ?? '';
    const raw = Buffer.from(sigValue, 'base64');
    expect(raw.length).toBe(keyType === 'rsa' ? 256 : 64); // ECDSA is R||S (2 x 32 bytes), not DER
    const { ExclusiveCanonicalization } = await import('xml-crypto');
    const si = doc.getElementsByTagNameNS(DS, 'SignedInfo').item(0);
    const canon = new ExclusiveCanonicalization().process(si as Element, {});
    const ver = createVerify('sha256');
    ver.update(canon, 'utf8');
    const key = new X509Certificate(cert.certificatePem).publicKey;
    expect(ver.verify(keyType === 'ec' ? { key, dsaEncoding: 'ieee-p1363' } : key, raw)).toBe(true);
  });
});

describe('certificate / key loading', () => {
  it('rejects mismatching key, garbage and wrong passphrase without leaking key material', async () => {
    const a = await TestCertificateFactory.createPersonal({ serialNumberPrefix: 'PNOPL', identifier: '73122269138' });
    const b = await TestCertificateFactory.createPersonal({ serialNumberPrefix: 'PNOPL', identifier: '73122269138' });
    expect(() => loadSigningMaterial(a.certificatePem, b.privateKeyPem)).toThrow(/does not match/);
    expect(() => loadSigningMaterial('garbage', a.privateKeyPem)).toThrow(CertificateException);
    expect(() => loadSigningMaterial(a.certificatePem, 'garbage')).toThrow(CertificateException);
    try {
      loadSigningMaterial(a.certificatePem, 'garbage', 'pw');
    } catch (e) {
      expect(JSON.stringify((e as CertificateException).diagnostics())).not.toContain('garbage');
    }
  });

  it('test certificate carries the KSeF personal-certificate attributes', async () => {
    const c = await TestCertificateFactory.createPersonal({
      serialNumberPrefix: 'PNOPL',
      identifier: '73122269138',
      givenName: 'Jan',
      surname: 'Testowy',
    });
    const x = new X509Certificate(c.certificatePem);
    const lines = x.subject.split('\n');
    expect(lines).toContain('C=PL');
    expect(lines).toContain('serialNumber=PNOPL-73122269138');
    expect(lines).toContain('GN=Jan');
    expect(lines).toContain('SN=Testowy');
    expect(lines.some((l) => l.startsWith('CN='))).toBe(true);
    expect(x.publicKey.asymmetricKeyDetails?.modulusLength).toBe(2048);
    expect(String(c.privateKeyPem)).toBe('[REDACTED]');
    await expect(TestCertificateFactory.createPersonal({ serialNumberPrefix: 'PNOPL', identifier: '123' })).rejects.toThrow();
  });

  it('credentials never reveal key material when serialised', async () => {
    const c = await TestCertificateFactory.createPersonal({ serialNumberPrefix: 'TINPL', identifier: '5265877635' });
    const creds = new CertificateCredentials({ certificatePem: c.certificatePem, privateKeyPem: c.privateKeyPem, context: Context.nip('5265877635') });
    const dump = JSON.stringify(creds) + String(new SensitiveString(c.privateKeyPem.reveal()));
    expect(dump).not.toContain('PRIVATE KEY');
    expect(creds.fingerprint()).toMatch(/^[0-9a-f]{64}$/);
  });
});
