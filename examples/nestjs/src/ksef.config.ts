import {
  CertificateCredentials,
  Context,
  Environment,
  InMemoryTokenStorage,
  KsefTokenCredentials,
  type Credentials,
  type TokenStorage,
} from '@luroweb/ksef-foundry';
import type { KsefModuleOptions } from '@luroweb/nestjs-ksef-foundry';
import { FileTokenStorage } from './file-token-storage.js';

/** Minimal view of `ConfigService` (keeps this file independent of `@nestjs/config` typings). */
export interface ConfigReader {
  get(key: string): string | undefined;
}

function required(config: ConfigReader, key: string): string {
  const v = config.get(key);
  if (v === undefined || v === '') throw new Error(`Missing environment variable ${key} (see .env.example)`);
  return v;
}

/**
 * The example talks to the KSeF TEST environment only. `KSEF_ENVIRONMENT` must be `test`;
 * `KSEF_BASE_URL` (loopback http or https) exists solely to point the app at a local fault-injection server.
 */
export function environmentFrom(config: ConfigReader): Environment {
  const name = (config.get('KSEF_ENVIRONMENT') ?? 'test').toLowerCase();
  if (name !== 'test') {
    throw new Error('This example only supports KSEF_ENVIRONMENT=test. Edit ksef.config.ts deliberately to use another environment.');
  }
  const base = config.get('KSEF_BASE_URL');
  return base !== undefined && base !== '' ? Environment.custom(base) : Environment.TEST;
}

/** KSeF token (`KSEF_TOKEN`) or, when absent, XAdES certificate credentials (inline PEMs, `\n` allowed). */
export function credentialsFrom(config: ConfigReader): Credentials {
  const context = Context.nip(required(config, 'KSEF_CONTEXT_NIP'));
  const token = config.get('KSEF_TOKEN');
  if (token !== undefined && token !== '') return new KsefTokenCredentials(token, context);
  const unescape = (s: string): string => s.replace(/\\n/g, '\n');
  const passphrase = config.get('KSEF_PRIVATE_KEY_PASSPHRASE');
  return new CertificateCredentials({
    certificatePem: unescape(required(config, 'KSEF_CERTIFICATE_PEM')),
    privateKeyPem: unescape(required(config, 'KSEF_PRIVATE_KEY_PEM')),
    ...(passphrase !== undefined && passphrase !== '' ? { passphrase } : {}),
    context,
  });
}

export function storageFrom(config: ConfigReader): TokenStorage {
  const file = config.get('KSEF_TOKEN_CACHE_FILE');
  return file !== undefined && file !== '' ? new FileTokenStorage(file) : new InMemoryTokenStorage();
}

export function ksefOptionsFrom(config: ConfigReader): KsefModuleOptions {
  return {
    environment: environmentFrom(config),
    credentials: credentialsFrom(config),
    storage: storageFrom(config),
    logger: 'nest',
    timeoutMs: 30_000,
  };
}
