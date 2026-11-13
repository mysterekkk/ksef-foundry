import { webcrypto, randomInt } from 'node:crypto';
import { CertificateException, ConfigurationException } from './errors.js';
import { SensitiveString } from './sensitive.js';

/**
 * TEST-ONLY helpers: synthetic identifiers and self-signed certificates.
 * Self-signed certificates are accepted by the KSeF TEST environment only.
 * Never use anything from this module with real data or against DEMO/PRODUCTION.
 */

/** Random, checksum-valid, clearly synthetic NIP (never a real one by design of randomness only). */
export function generateTestNip(): string {
  const weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
  for (;;) {
    const digits = [randomInt(1, 10), randomInt(1, 10), randomInt(1, 10)];
    for (let i = 3; i < 9; i++) digits.push(randomInt(0, 10));
    const sum = digits.reduce((acc, d, i) => acc + d * (weights[i] ?? 0), 0);
    const check = sum % 11;
    if (check === 10) continue;
    const nip = [...digits, check].join('');
    if (/^[1-9]((\d[1-9])|([1-9]\d))\d{7}$/.test(nip)) return nip;
  }
}

/** Random, checksum-valid PESEL (born 1960-1999, day 1-28). */
export function generateTestPesel(): string {
  const weights = [1, 3, 7, 9, 1, 3, 7, 9, 1, 3];
  const yy = randomInt(60, 100);
  const mm = randomInt(1, 13);
  const dd = randomInt(1, 29);
  const base = [
    Math.floor(yy / 10),
    yy % 10,
    Math.floor(mm / 10),
    mm % 10,
    Math.floor(dd / 10),
    dd % 10,
    randomInt(0, 10),
    randomInt(0, 10),
    randomInt(0, 10),
    randomInt(0, 10),
  ];
  const sum = base.reduce((acc, d, i) => acc + d * (weights[i] ?? 0), 0);
  return [...base, (10 - (sum % 10)) % 10].join('');
}

export interface TestCertificateOptions {
  /** `PNOPL` (PESEL, 11 digits) or `TINPL` (NIP, 10 digits). */
  readonly serialNumberPrefix: 'PNOPL' | 'TINPL';
  /** PESEL or NIP matching the prefix. */
  readonly identifier: string;
  readonly givenName?: string;
  readonly surname?: string;
  readonly commonName?: string;
  /** `rsa` (RSA 2048, default) or `ec` (ECDSA P-256). */
  readonly keyType?: 'rsa' | 'ec';
  /** Validity in days. Default 30. */
  readonly validityDays?: number;
}

export interface TestCertificate {
  readonly certificatePem: string;
  readonly privateKeyPem: SensitiveString;
  /** Subject string as decoded from the generated certificate. */
  readonly subject: string;
}

/**
 * TEST-ONLY self-signed personal certificate with the attributes KSeF expects from a qualified
 * signature certificate: C=PL, CN, givenName, surname, serialNumber=`PNOPL-<pesel>` / `TINPL-<nip>`.
 * Requires the optional peer dependencies `@peculiar/x509` and `reflect-metadata`.
 */
export const TestCertificateFactory = {
  async createPersonal(options: TestCertificateOptions): Promise<TestCertificate> {
    const expectedLen = options.serialNumberPrefix === 'PNOPL' ? 11 : 10;
    if (!new RegExp(`^\\d{${String(expectedLen)}}$`).test(options.identifier)) {
      throw new ConfigurationException(`Identifier for ${options.serialNumberPrefix} must have ${String(expectedLen)} digits`);
    }
    let x509: typeof import('@peculiar/x509');
    try {
      await import('reflect-metadata'); // required by @peculiar/x509 (tsyringe)
      x509 = await import('@peculiar/x509');
    } catch (cause) {
      throw new ConfigurationException('TestCertificateFactory requires the optional dependencies "@peculiar/x509" and "reflect-metadata"', { cause });
    }
    const subtle = webcrypto.subtle;
    const isEc = options.keyType === 'ec';
    const alg = isEc
      ? ({ name: 'ECDSA', namedCurve: 'P-256', hash: 'SHA-256' } as const)
      : ({ name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256', publicExponent: new Uint8Array([1, 0, 1]), modulusLength: 2048 } as const);
    const keys = await subtle.generateKey(alg, true, ['sign', 'verify']);
    const given = options.givenName ?? 'Test';
    const sur = options.surname ?? 'Synthetic';
    const cn = options.commonName ?? `${given} ${sur}`;
    const name = new x509.Name(
      [
        { '2.5.4.6': ['PL'] },
        { '2.5.4.3': [cn] },
        { '2.5.4.42': [given] },
        { '2.5.4.4': [sur] },
        { '2.5.4.5': [`${options.serialNumberPrefix}-${options.identifier}`] },
      ],
    );
    const now = Date.now();
    const cert = await x509.X509CertificateGenerator.createSelfSigned({
      serialNumber: Buffer.from(webcrypto.getRandomValues(new Uint8Array(8))).toString('hex').replace(/^([89a-f])/, '0$1'),
      name,
      notBefore: new Date(now - 5 * 60_000),
      notAfter: new Date(now + (options.validityDays ?? 30) * 86_400_000),
      signingAlgorithm: alg,
      keys: keys as unknown as CryptoKeyPair,
      extensions: [
        new x509.BasicConstraintsExtension(false, undefined, true),
        new x509.KeyUsagesExtension(x509.KeyUsageFlags.digitalSignature | x509.KeyUsageFlags.nonRepudiation, true),
      ],
    });
    const pkcs8 = Buffer.from(await subtle.exportKey('pkcs8', keys.privateKey));
    const privateKeyPem = `-----BEGIN PRIVATE KEY-----\n${pkcs8.toString('base64').replace(/(.{64})/g, '$1\n')}\n-----END PRIVATE KEY-----\n`;
    const certificatePem = cert.toString('pem');
    const { X509Certificate } = await import('node:crypto');
    const decoded = new X509Certificate(certificatePem);
    const subject = decoded.subject.split('\n').join(', ');
    if (!subject.includes(`${options.serialNumberPrefix}-${options.identifier}`)) {
      throw new CertificateException('Generated test certificate lacks the expected serialNumber attribute');
    }
    return { certificatePem, privateKeyPem: new SensitiveString(privateKeyPem), subject };
  },
} as const;
