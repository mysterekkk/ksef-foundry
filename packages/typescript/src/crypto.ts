import {
  constants,
  createCipheriv,
  createDecipheriv,
  createHash,
  publicEncrypt,
  privateDecrypt,
  randomBytes,
  type KeyObject,
} from 'node:crypto';
import { EncryptionException } from './errors.js';
import { SensitiveString } from './sensitive.js';

/** Symmetric session key material (kept in memory only). */
export interface SessionKeyMaterial {
  /** 32 random bytes (AES-256). */
  readonly key: Uint8Array;
  /** 16 random bytes (CBC IV). */
  readonly iv: Uint8Array;
}

/** Cryptographic primitives required by KSeF 2.0 (contract section 5). */
export const Crypto = {
  /** base64(sha256(bytes)). */
  sha256Base64(data: Uint8Array | string): string {
    return createHash('sha256').update(data).digest('base64');
  },

  /** Fresh CSPRNG key (32 B) and IV (16 B). Generate one pair per session. */
  generateSessionKey(): SessionKeyMaterial {
    return { key: randomBytes(32), iv: randomBytes(16) };
  },

  /** AES-256-CBC with PKCS#7 padding. */
  aes256CbcEncrypt(plain: Uint8Array, material: SessionKeyMaterial): Uint8Array {
    assertSizes(material);
    const c = createCipheriv('aes-256-cbc', material.key, material.iv);
    return Buffer.concat([c.update(plain), c.final()]);
  },

  /** Inverse of {@link Crypto.aes256CbcEncrypt} (used for verification/tests). */
  aes256CbcDecrypt(cipher: Uint8Array, material: SessionKeyMaterial): Uint8Array {
    assertSizes(material);
    const d = createDecipheriv('aes-256-cbc', material.key, material.iv);
    return Buffer.concat([d.update(cipher), d.final()]);
  },

  /** RSA-OAEP, OAEP hash SHA-256, MGF1 SHA-256, empty label. */
  rsaOaepSha256Encrypt(publicKey: KeyObject, data: Uint8Array): Uint8Array {
    try {
      return publicEncrypt({ key: publicKey, padding: constants.RSA_PKCS1_OAEP_PADDING, oaepHash: 'sha256' }, data);
    } catch (cause) {
      throw new EncryptionException('RSA-OAEP encryption failed', { cause });
    }
  },

  /** Inverse of {@link Crypto.rsaOaepSha256Encrypt}; for tests and verification only. */
  rsaOaepSha256Decrypt(privateKey: KeyObject, data: Uint8Array): Uint8Array {
    try {
      return privateDecrypt({ key: privateKey, padding: constants.RSA_PKCS1_OAEP_PADDING, oaepHash: 'sha256' }, data);
    } catch (cause) {
      throw new EncryptionException('RSA-OAEP decryption failed', { cause });
    }
  },

  /** `base64(RSA-OAEP-SHA256("{ksefToken}|{timestampMs}"))` for `POST /auth/ksef-token`. */
  encryptKsefToken(token: string | SensitiveString, timestampMs: number, publicKey: KeyObject): string {
    const plain = Buffer.from(`${SensitiveString.from(token).reveal()}|${String(timestampMs)}`, 'utf8');
    return Buffer.from(Crypto.rsaOaepSha256Encrypt(publicKey, plain)).toString('base64');
  },

  /** `{ hash, size }` as required by KSeF for plain and encrypted invoice. */
  fileMetadata(data: Uint8Array): { hash: string; size: number } {
    return { hash: Crypto.sha256Base64(data), size: data.byteLength };
  },

  /**
   * Builds the body of `POST /sessions/online/{ref}/invoices`.
   * `invoiceBytes` is the exact XML (UTF-8) that will be hashed and encrypted.
   */
  encryptInvoice(
    invoiceBytes: Uint8Array,
    material: SessionKeyMaterial,
  ): {
    invoiceHash: string;
    invoiceSize: number;
    encryptedInvoiceHash: string;
    encryptedInvoiceSize: number;
    encryptedInvoiceContent: string;
  } {
    const cipher = Crypto.aes256CbcEncrypt(invoiceBytes, material);
    const plain = Crypto.fileMetadata(invoiceBytes);
    const enc = Crypto.fileMetadata(cipher);
    return {
      invoiceHash: plain.hash,
      invoiceSize: plain.size,
      encryptedInvoiceHash: enc.hash,
      encryptedInvoiceSize: enc.size,
      encryptedInvoiceContent: Buffer.from(cipher).toString('base64'),
    };
  },
} as const;

function assertSizes(m: SessionKeyMaterial): void {
  if (m.key.byteLength !== 32) throw new EncryptionException('AES key must be 32 bytes');
  if (m.iv.byteLength !== 16) throw new EncryptionException('IV must be 16 bytes');
}
