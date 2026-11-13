import { X509Certificate, createHash, createPrivateKey, createPublicKey } from 'node:crypto';
import type { ServerResponse } from 'node:http';
import { Crypto, TestCertificateFactory } from '@luroweb/ksef-foundry';
import { json, startServer, type LocalServer } from './local-server.js';

/**
 * REAL local HTTP server speaking the KSeF token-login protocol with REAL crypto (it decrypts the
 * RSA-OAEP-SHA256 encrypted token the SDK sends). Fault-injection fixture, not API compatibility proof.
 */
export interface AuthServer {
  server: LocalServer;
  readonly counters: { login: number; redeem: number; limits: number };
  /** Plaintext KSeF tokens (as decrypted by the server) that logged in, with counts. */
  readonly tokenLogins: Map<string, number>;
  /** Contexts (`Nip:123...`) seen on login. */
  readonly loginContexts: string[];
  /** Hook for authenticated endpoints; return true when the request was handled. */
  onAuthenticated: ((path: string, method: string, res: ServerResponse, bearer: string) => boolean) | undefined;
  close(): Promise<void>;
}

export async function startAuthServer(): Promise<AuthServer> {
  const c = await TestCertificateFactory.createPersonal({ serialNumberPrefix: 'TINPL', identifier: '1234567890', validityDays: 365 });
  const certB64 = Buffer.from(new X509Certificate(c.certificatePem).raw).toString('base64');
  const spki = createPublicKey(c.certificatePem).export({ type: 'spki', format: 'der' });
  const publicKeyId = createHash('sha256').update(spki).digest('base64');
  const priv = createPrivateKey(c.privateKeyPem.reveal());
  const authInfo = new Map<string, string>();
  let seq = 0;
  const iso = (ms: number): string => new Date(ms).toISOString();

  const as: AuthServer = {
    server: undefined as unknown as LocalServer,
    counters: { login: 0, redeem: 0, limits: 0 },
    tokenLogins: new Map(),
    loginContexts: [],
    onAuthenticated: undefined,
    close: () => as.server.close(),
  };
  as.server = await startServer((req, res, body) => {
    const path = (req.url ?? '').replace(/^\/v2/, '').split('?')[0] ?? '';
    const method = req.method ?? '';
    const bearer = req.headers['authorization']?.replace(/^Bearer /, '');
    const j = body === '' ? undefined : (JSON.parse(body) as Record<string, unknown>);

    if (method === 'GET' && path === '/security/public-key-certificates') {
      const now = Date.now();
      return json(res, 200, [
        { certificate: certB64, certificateId: 'cid', publicKeyId, validFrom: iso(now - 86_400_000), validTo: iso(now + 86_400_000 * 300), usage: ['KsefTokenEncryption', 'SymmetricKeyEncryption'] },
      ]);
    }
    if (method === 'POST' && path === '/auth/challenge') {
      const ts = Date.now();
      return json(res, 200, { challenge: '20261113-CR-4554351000-E39F377DCD-54', timestamp: iso(ts), timestampMs: ts, clientIp: '127.0.0.1' });
    }
    if (method === 'POST' && path === '/auth/ksef-token') {
      const plain = Buffer.from(Crypto.rsaOaepSha256Decrypt(priv, Buffer.from(String(j?.['encryptedToken']), 'base64'))).toString('utf8');
      const token = plain.split('|')[0] ?? '';
      as.counters.login++;
      as.tokenLogins.set(token, (as.tokenLogins.get(token) ?? 0) + 1);
      const ctx = j?.['contextIdentifier'] as { type: string; value: string };
      as.loginContexts.push(`${ctx.type}:${ctx.value}`);
      const ref = `AUTHREF-${String(++seq)}`;
      authInfo.set(ref, `${createHash('sha256').update(token).digest('hex').slice(0, 8)}@${ctx.value}`);
      return json(res, 202, { referenceNumber: ref, authenticationToken: { token: `TEMP-${ref}`, validUntil: iso(Date.now() + 60_000) } });
    }
    if (method === 'GET' && /^\/auth\/AUTHREF-\d+$/.test(path)) {
      return json(res, 200, { startDate: iso(Date.now()), authenticationMethod: 'Token', authenticationMethodInfo: { category: 'Token', code: 'T', displayName: 'T' }, status: { code: 200, description: 'ok' } });
    }
    if (method === 'POST' && path === '/auth/token/redeem') {
      as.counters.redeem++;
      const subject = authInfo.get(bearer?.replace('TEMP-', '') ?? '');
      if (subject === undefined) return json(res, 401, { title: 'Unauthorized', status: 401, detail: 'bad temp token' });
      return json(res, 200, {
        accessToken: { token: `ACCESS-${subject}-${String(++seq)}`, validUntil: iso(Date.now() + 600_000) },
        refreshToken: { token: `REFRESH-${subject}-${String(++seq)}`, validUntil: iso(Date.now() + 7 * 86_400_000) },
      });
    }
    if (bearer === undefined || !bearer.startsWith('ACCESS-')) return json(res, 401, { title: 'Unauthorized', status: 401, detail: 'no token' });
    if (as.onAuthenticated?.(path, method, res, bearer) === true) return;
    if (method === 'GET' && path === '/limits/context') {
      as.counters.limits++;
      return json(res, 200, { whoami: bearer, onlineSession: { maxInvoiceSizeInMB: 1, maxInvoiceWithAttachmentSizeInMB: 3, maxInvoices: 10000 }, batchSession: { maxInvoiceSizeInMB: 1, maxInvoiceWithAttachmentSizeInMB: 3, maxInvoices: 10000 } });
    }
    if (method === 'GET' && path === '/rate-limits') return json(res, 200, {});
    return json(res, 404, { title: 'Not Found', status: 404, detail: `${method} ${path}` });
  });
  return as;
}
