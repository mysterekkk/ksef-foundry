import { createPublicKey, generateKeyPairSync, randomBytes, X509Certificate, createHash } from 'node:crypto';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import { describe, expect, it } from 'vitest';
import {
  Crypto,
  EncryptionException,
  TestCertificateFactory,
  selectPublicKey,
  type PublicKeyCertificateInfo,
} from '../../src/index.js';

const KEY = Buffer.from('000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f', 'hex');
const IV = Buffer.from('0f0e0d0c0b0a09080706050403020100', 'hex');
const PLAIN = 'KSeF known answer test: <Faktura/>';

describe('known-answer crypto (expected values computed with `openssl enc` / `sha256sum`)', () => {
  it('AES-256-CBC/PKCS7', () => {
    const c = Crypto.aes256CbcEncrypt(Buffer.from(PLAIN), { key: KEY, iv: IV });
    expect(Buffer.from(c).toString('base64')).toBe('wDgWpT+jJU0RxV8Z8MUbNznryJV3LoL8jOIlPizqiGqmcKOX7EPuUlBuBvX4OGTn');
    expect(Buffer.from(Crypto.aes256CbcDecrypt(c, { key: KEY, iv: IV })).toString()).toBe(PLAIN);
  });
  it('SHA-256 base64', () => {
    expect(Crypto.sha256Base64(PLAIN)).toBe('SrXEvAwlRbOHijFVRGaHUjjRqYsNU8lUMmISmBIH1HA=');
    expect(Crypto.fileMetadata(Buffer.from(PLAIN))).toEqual({
      hash: 'SrXEvAwlRbOHijFVRGaHUjjRqYsNU8lUMmISmBIH1HA=',
      size: 34,
    });
  });
  it('rejects wrong key/iv sizes', () => {
    expect(() => Crypto.aes256CbcEncrypt(Buffer.from('x'), { key: randomBytes(16), iv: IV })).toThrow(EncryptionException);
    expect(() => Crypto.aes256CbcEncrypt(Buffer.from('x'), { key: KEY, iv: randomBytes(8) })).toThrow(EncryptionException);
  });
  it('session keys are random 32 + 16 bytes', () => {
    const a = Crypto.generateSessionKey();
    const b = Crypto.generateSessionKey();
    expect(a.key.byteLength).toBe(32);
    expect(a.iv.byteLength).toBe(16);
    expect(Buffer.from(a.key).equals(Buffer.from(b.key))).toBe(false);
  });
  it('encryptInvoice produces consistent metadata', () => {
    const m = { key: KEY, iv: IV };
    const r = Crypto.encryptInvoice(Buffer.from(PLAIN), m);
    expect(r.invoiceSize).toBe(34);
    expect(r.encryptedInvoiceSize).toBe(48);
    expect(r.encryptedInvoiceHash).toBe(Crypto.sha256Base64(Buffer.from(r.encryptedInvoiceContent, 'base64')));
    expect(r.encryptedInvoiceContent).toBe('wDgWpT+jJU0RxV8Z8MUbNznryJV3LoL8jOIlPizqiGqmcKOX7EPuUlBuBvX4OGTn');
  });
});

describe('RSA-OAEP SHA-256 / MGF1 SHA-256', () => {
  const { publicKey, privateKey } = generateKeyPairSync('rsa', { modulusLength: 2048 });
  it('round-trips with a locally generated key', () => {
    const ct = Crypto.rsaOaepSha256Encrypt(publicKey, KEY);
    expect(ct.byteLength).toBe(256);
    expect(Buffer.from(Crypto.rsaOaepSha256Decrypt(privateKey, ct)).equals(KEY)).toBe(true);
    // randomised padding
    expect(Buffer.from(Crypto.rsaOaepSha256Encrypt(publicKey, KEY)).equals(Buffer.from(ct))).toBe(false);
  });
  it('cannot be decrypted as SHA-1 OAEP (hash really is SHA-256)', () => {
    const ct = Crypto.rsaOaepSha256Encrypt(publicKey, KEY);
    expect(() => decryptSha1Oaep(privateKey, ct)).toThrow();
  });
  const hasOpenssl = spawnSync('openssl', ['version']).status === 0;
  it.skipIf(!hasOpenssl)('independent decryption with the openssl CLI (pkeyutl, md=sha256, mgf1=sha256)', () => {
    const dir = mkdtempSync(join(tmpdir(), 'ksef-oaep-'));
    try {
      writeFileSync(join(dir, 'k.pem'), privateKey.export({ type: 'pkcs8', format: 'pem' }));
      const token = Crypto.encryptKsefToken('synthetic-token', 1794600696657, publicKey);
      writeFileSync(join(dir, 'ct.bin'), Buffer.from(token, 'base64'));
      const r = spawnSync('openssl', [
        'pkeyutl', '-decrypt', '-inkey', join(dir, 'k.pem'), '-in', join(dir, 'ct.bin'),
        '-pkeyopt', 'rsa_padding_mode:oaep', '-pkeyopt', 'rsa_oaep_md:sha256', '-pkeyopt', 'rsa_mgf1_md:sha256',
      ]);
      expect(r.status).toBe(0);
      expect(r.stdout.toString()).toBe('synthetic-token|1794600696657');
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });
});

import { constants, privateDecrypt, type KeyObject } from 'node:crypto';
function decryptSha1Oaep(k: KeyObject, ct: Uint8Array): Buffer {
  return privateDecrypt({ key: k, padding: constants.RSA_PKCS1_OAEP_PADDING, oaepHash: 'sha1' }, ct);
}

describe('public key selection', () => {
  async function entry(usage: string, from: string, to: string): Promise<PublicKeyCertificateInfo> {
    const c = await TestCertificateFactory.createPersonal({ serialNumberPrefix: 'TINPL', identifier: '1234567890', validityDays: 365 });
    const x = new X509Certificate(c.certificatePem);
    const spki = createPublicKey(c.certificatePem).export({ type: 'spki', format: 'der' });
    return {
      certificate: Buffer.from(x.raw).toString('base64'),
      certificateId: createHash('sha256').update(x.raw).digest('base64'),
      publicKeyId: createHash('sha256').update(spki).digest('base64'),
      validFrom: from,
      validTo: to,
      usage: [usage],
    };
  }
  const now = Date.now();
  const iso = (d: number): string => new Date(now + d * 86_400_000).toISOString();

  it('prefers the latest validFrom among valid keys of the usage', async () => {
    const older = await entry('SymmetricKeyEncryption', iso(-10), iso(100));
    const newer = await entry('SymmetricKeyEncryption', iso(-1), iso(100));
    const other = await entry('KsefTokenEncryption', iso(-1), iso(100));
    const r = selectPublicKey([older, other, newer], 'SymmetricKeyEncryption', now);
    expect(r.publicKeyId).toBe(newer.publicKeyId);
    expect(selectPublicKey([older, other, newer], 'KsefTokenEncryption', now).publicKeyId).toBe(other.publicKeyId);
  });
  it('skips keys that are not yet valid', async () => {
    const future = await entry('SymmetricKeyEncryption', iso(5), iso(100));
    const ok = await entry('SymmetricKeyEncryption', iso(-10), iso(100));
    expect(selectPublicKey([future, ok], 'SymmetricKeyEncryption', now).publicKeyId).toBe(ok.publicKeyId);
  });
  it('rejects a mismatching publicKeyId, expired window, missing usage and garbage certificates', async () => {
    const e = await entry('SymmetricKeyEncryption', iso(-10), iso(100));
    expect(() => selectPublicKey([{ ...e, publicKeyId: Buffer.alloc(32).toString('base64') }], 'SymmetricKeyEncryption', now)).toThrow(/publicKeyId/);
    expect(() => selectPublicKey([{ ...e, validFrom: iso(-10), validTo: iso(-5) }], 'SymmetricKeyEncryption', now)).toThrow(EncryptionException);
    expect(() => selectPublicKey([e], 'KsefTokenEncryption', now)).toThrow(EncryptionException);
    expect(() => selectPublicKey([{ ...e, certificate: 'AAAA' }], 'SymmetricKeyEncryption', now)).toThrow(/does not parse/);
  });
});
