import { X509Certificate, createHash, createPrivateKey, createPublicKey, type KeyObject } from 'node:crypto';
import { Crypto, TestCertificateFactory } from '../../src/index.js';
import { json, startServer, type LocalServer } from './local-server.js';

/**
 * A REAL local HTTP server that speaks the subset of the KSeF protocol needed to exercise
 * authentication and the session lifecycle end to end with REAL crypto (it decrypts what the SDK
 * encrypts with real RSA-OAEP/AES keys). It is a fault-injection fixture: it proves SDK behaviour
 * (single-flight, refresh, re-login, 21470, reconcile) - NOT compatibility with the real API (see test/live).
 */
export interface ProtocolServer {
  server: LocalServer;
  readonly counters: { challenge: number; login: number; redeem: number; refresh: number; keys: number; open: number; send: number };
  /** token string -> times it logged in */
  readonly tokenLogins: Map<string, number>;
  /** `context` seen per login (`type:value`) */
  readonly loginContexts: string[];
  /** Access token lifetime handed out (ms). */
  accessTtlMs: number;
  /** When true `POST /auth/token/refresh` answers 401. */
  refreshFails: boolean;
  /** Replace the published token-encryption key (simulates key rotation -> 21470 for the old id). */
  rotateTokenKey(): Promise<void>;
  /** Replace the published symmetric-key (session) certificate. */
  rotateSymmetricKey(): Promise<void>;
  /** When true the next authenticated request is answered 401 (token revoked server-side). */
  reject401Once: boolean;
  /** Invoices received by the server (decrypted with the session key). */
  readonly received: { sessionRef: string; xml: string; hash: string }[];
  /** `send` handler mode. */
  sendMode:
    | 'ok'
    | 'process-then-reset'
    | 'reset-without-processing'
    | 'code-21184'
    | 'garbled-202'
    | 'truncated-202'
    | 'empty-202'
    | 'wrong-shape-202'
    | 'hang';
  /** Shape of the answer to `POST /sessions/online`. */
  openMode: 'ok' | 'garbled' | 'empty' | 'wrong-shape';
  /** When true every authenticated API call answers 401 (even with a fresh token). */
  reject401Always: boolean;
  /** When set, `GET /auth/{ref}` finishes with this authentication status code (e.g. 450). */
  authFailCode: number | undefined;
  /** statuses returned for invoices (code). */
  invoiceStatusCode: number;
  close(): Promise<void>;
}

interface Key {
  certB64: string;
  publicKeyId: string;
  priv: KeyObject;
}

async function makeKey(): Promise<Key> {
  const c = await TestCertificateFactory.createPersonal({ serialNumberPrefix: 'TINPL', identifier: '1234567890', validityDays: 365 });
  const x = new X509Certificate(c.certificatePem);
  const spki = createPublicKey(c.certificatePem).export({ type: 'spki', format: 'der' });
  return {
    certB64: Buffer.from(x.raw).toString('base64'),
    publicKeyId: createHash('sha256').update(spki).digest('base64'),
    priv: createPrivateKey(c.privateKeyPem.reveal()),
  };
}

/** KSeF reference numbers are 36 characters; the SDK validates them at the service boundary. */
const pad = (s: string): string => s.padEnd(36, '-');

export async function startProtocolServer(): Promise<ProtocolServer> {
  let tokenKey = await makeKey();
  let symKey = await makeKey();
  const sessionKeys = new Map<string, { key: Buffer; iv: Buffer }>();
  const sessionInvoices = new Map<string, { referenceNumber: string; invoiceHash: string }[]>();
  const authInfo = new Map<string, { polls: number; subject: string }>();
  const refreshToSubject = new Map<string, string>();
  const ps: ProtocolServer = {
    server: undefined as unknown as LocalServer,
    counters: { challenge: 0, login: 0, redeem: 0, refresh: 0, keys: 0, open: 0, send: 0 },
    tokenLogins: new Map(),
    loginContexts: [],
    accessTtlMs: 600_000,
    refreshFails: false,
    received: [],
    sendMode: 'ok',
    openMode: 'ok',
    reject401Always: false,
    authFailCode: undefined,
    invoiceStatusCode: 200,
    reject401Once: false,
    async rotateSymmetricKey() {
      symKey = await makeKey();
    },
    async rotateTokenKey() {
      tokenKey = await makeKey();
    },
    close: () => ps.server.close(),
  };
  const now = (): number => Date.now();
  const iso = (ms: number): string => new Date(ms).toISOString();
  let seq = 0;

  ps.server = await startServer(async (req, res, body) => {
    const path = (req.url ?? '').replace(/^\/v2/, '').split('?')[0] ?? '';
    const method = req.method ?? '';
    const auth = req.headers['authorization']?.replace(/^Bearer /, '');
    const j = body === '' ? undefined : (JSON.parse(body) as Record<string, unknown>);

    if (method === 'GET' && path === '/security/public-key-certificates') {
      ps.counters.keys++;
      const mk = (k: Key, usage: string): unknown => ({
        certificate: k.certB64,
        certificateId: 'cid',
        publicKeyId: k.publicKeyId,
        validFrom: iso(now() - 86_400_000),
        validTo: iso(now() + 86_400_000 * 300),
        usage: [usage],
      });
      return json(res, 200, [mk(tokenKey, 'KsefTokenEncryption'), mk(symKey, 'SymmetricKeyEncryption')]);
    }
    if (method === 'POST' && path === '/auth/challenge') {
      ps.counters.challenge++;
      const ts = now();
      return json(res, 200, { challenge: '20261113-CR-4554351000-E39F377DCD-54', timestamp: iso(ts), timestampMs: ts, clientIp: '127.0.0.1' });
    }
    if (method === 'POST' && path === '/auth/ksef-token') {
      const pid = j?.['publicKeyId'];
      if (pid !== tokenKey.publicKeyId) {
        return json(res, 400, { title: 'Bad Request', status: 400, detail: 'x', traceId: 't', errors: [{ code: 21470, description: 'Unknown key id' }] });
      }
      const plain = Buffer.from(Crypto.rsaOaepSha256Decrypt(tokenKey.priv, Buffer.from(String(j?.['encryptedToken']), 'base64'))).toString('utf8');
      const [token, ts] = plain.split('|');
      if (token === undefined || ts === undefined || !/^\d+$/.test(ts)) return json(res, 400, { title: 'bad', status: 400, detail: 'bad token', errors: [] });
      ps.counters.login++;
      ps.tokenLogins.set(token, (ps.tokenLogins.get(token) ?? 0) + 1);
      const ctx = j?.['contextIdentifier'] as { type: string; value: string };
      ps.loginContexts.push(`${ctx.type}:${ctx.value}`);
      const ref = `AUTHREF-${String(++seq)}`;
      authInfo.set(ref, { polls: 0, subject: `${createHash('sha256').update(token).digest('hex').slice(0, 8)}@${ctx.value}` });
      return json(res, 202, { referenceNumber: ref, authenticationToken: { token: `TEMP-${ref}`, validUntil: iso(now() + 60_000) } });
    }
    const authStatus = /^\/auth\/(AUTHREF-\d+)$/.exec(path);
    if (method === 'GET' && authStatus !== null) {
      const info = authInfo.get(authStatus[1] ?? '');
      if (info === undefined) return json(res, 401, { title: 'Unauthorized', status: 401, detail: 'unknown' });
      info.polls++;
      const code = info.polls < 2 ? 100 : (ps.authFailCode ?? 200);
      return json(res, 200, { startDate: iso(now()), authenticationMethod: 'Token', authenticationMethodInfo: { category: 'Token', code: 'T', displayName: 'T' }, status: { code, description: code === 100 ? 'in progress' : 'ok' } });
    }
    if (method === 'POST' && path === '/auth/token/redeem') {
      ps.counters.redeem++;
      const ref = auth?.replace('TEMP-', '') ?? '';
      const info = authInfo.get(ref);
      if (info === undefined) return json(res, 401, { title: 'Unauthorized', status: 401, detail: 'bad temp token' });
      authInfo.delete(ref); // one-shot
      const refresh = `REFRESH-${info.subject}-${String(++seq)}`;
      refreshToSubject.set(refresh, info.subject);
      return json(res, 200, {
        accessToken: { token: `ACCESS-${info.subject}-${String(++seq)}`, validUntil: iso(now() + ps.accessTtlMs) },
        refreshToken: { token: refresh, validUntil: iso(now() + 7 * 86_400_000) },
      });
    }
    if (method === 'POST' && path === '/auth/token/refresh') {
      ps.counters.refresh++;
      const subject = refreshToSubject.get(auth ?? '');
      if (ps.refreshFails || subject === undefined) return json(res, 401, { title: 'Unauthorized', status: 401, detail: 'refresh token invalid' });
      return json(res, 200, { accessToken: { token: `ACCESS-${subject}-${String(++seq)}`, validUntil: iso(now() + ps.accessTtlMs) } });
    }

    // ---- authenticated area
    if (auth === undefined || !auth.startsWith('ACCESS-')) return json(res, 401, { title: 'Unauthorized', status: 401, detail: 'no token' });

    if (ps.reject401Always) return json(res, 401, { title: 'Unauthorized', status: 401, detail: 'always' });
    if (ps.reject401Once) {
      ps.reject401Once = false;
      return json(res, 401, { title: 'Unauthorized', status: 401, detail: 'token revoked' });
    }

    if (method === 'GET' && path === '/limits/context') {
      return json(res, 200, { whoami: auth, onlineSession: { maxInvoiceSizeInMB: 1, maxInvoiceWithAttachmentSizeInMB: 3, maxInvoices: 10000 }, batchSession: { maxInvoiceSizeInMB: 1, maxInvoiceWithAttachmentSizeInMB: 3, maxInvoices: 10000 } });
    }
    if (method === 'POST' && path === '/sessions/online') {
      ps.counters.open++;
      const enc = j?.['encryption'] as { encryptedSymmetricKey: string; initializationVector: string; publicKeyId: string };
      if (enc.publicKeyId !== symKey.publicKeyId) return json(res, 400, { title: 'Bad Request', status: 400, detail: 'x', errors: [{ code: 21470, description: 'Unknown key id' }] });
      const key = Buffer.from(Crypto.rsaOaepSha256Decrypt(symKey.priv, Buffer.from(enc.encryptedSymmetricKey, 'base64')));
      const iv = Buffer.from(enc.initializationVector, 'base64');
      if (ps.openMode === 'empty') return void (res.writeHead(201, { 'content-length': '0' }), res.end());
      if (ps.openMode === 'garbled') return void (res.writeHead(201, { 'content-type': 'application/json' }), res.end('{"referenceNumber":'));
      if (ps.openMode === 'wrong-shape') return json(res, 201, { unexpected: true });
      const ref = pad(`SESSION-${String(++seq)}`);
      sessionKeys.set(ref, { key, iv });
      sessionInvoices.set(ref, []);
      return json(res, 201, { referenceNumber: ref, validUntil: iso(now() + 12 * 3_600_000) });
    }
    const sendM = /^\/sessions\/online\/(SESSION-\d+-*)\/invoices$/.exec(path);
    if (method === 'POST' && sendM !== null) {
      ps.counters.send++;
      const ref = sendM[1] ?? '';
      if (ps.sendMode === 'code-21184') return json(res, 400, { title: 'Bad Request', status: 400, detail: 'x', errors: [{ code: 21184, description: 'Sesja tymczasowo niedostępna' }] });
      if (ps.sendMode === 'reset-without-processing') return void res.destroy();
      if (ps.sendMode === 'hang') return; // never answers
      const keys = sessionKeys.get(ref);
      if (keys === undefined) return json(res, 400, { title: 'Bad Request', status: 400, detail: 'no session', errors: [] });
      const content = Buffer.from(String(j?.['encryptedInvoiceContent']), 'base64');
      if (Crypto.sha256Base64(content) !== j?.['encryptedInvoiceHash'] || content.length !== j?.['encryptedInvoiceSize']) return json(res, 400, { title: 'Bad Request', status: 400, detail: 'enc hash', errors: [] });
      const plain = Buffer.from(Crypto.aes256CbcDecrypt(content, keys));
      if (Crypto.sha256Base64(plain) !== j?.['invoiceHash'] || plain.length !== j?.['invoiceSize']) return json(res, 400, { title: 'Bad Request', status: 400, detail: 'hash', errors: [] });
      const invRef = pad(`INVOICE-${String(++seq)}`);
      ps.received.push({ sessionRef: ref, xml: plain.toString('utf8'), hash: j?.['invoiceHash'] });
      sessionInvoices.get(ref)?.push({ referenceNumber: invRef, invoiceHash: j?.['invoiceHash'] });
      if (ps.sendMode === 'process-then-reset') return void res.destroy();
      if (ps.sendMode === 'garbled-202') return void (res.writeHead(202, { 'content-type': 'application/json' }), res.end('{"referenceNumber": oops'));
      if (ps.sendMode === 'empty-202') return void (res.writeHead(202, { 'content-length': '0' }), res.end());
      if (ps.sendMode === 'wrong-shape-202') return json(res, 202, { ok: true });
      if (ps.sendMode === 'truncated-202') {
        res.writeHead(202, { 'content-type': 'application/json', 'content-length': '200' });
        res.write('{"referenceNumber":"');
        setTimeout(() => res.destroy(), 20);
        return;
      }
      return json(res, 202, { referenceNumber: invRef });
    }
    const invList = /^\/sessions\/(SESSION-\d+-*)\/invoices$/.exec(path);
    const mkInvoice = (i: { referenceNumber: string; invoiceHash: string }, ord: number): unknown => ({
      ordinalNumber: ord,
      referenceNumber: i.referenceNumber,
      invoiceHash: i.invoiceHash,
      invoicingDate: iso(now()),
      ksefNumber: ps.invoiceStatusCode === 200 ? '5265877635-20261113-0100AAAAAAAA-AA' : null,
      upoDownloadUrl: null,
      status: { code: ps.invoiceStatusCode, description: ps.invoiceStatusCode === 200 ? 'Sukces' : 'Błąd', details: ps.invoiceStatusCode >= 400 ? ['detail'] : [] },
    });
    if (method === 'GET' && invList !== null) {
      const list = sessionInvoices.get(invList[1] ?? '') ?? [];
      return json(res, 200, { invoices: list.map((i, k) => mkInvoice(i, k + 1)) });
    }
    const invOne = /^\/sessions\/(SESSION-\d+-*)\/invoices\/(INVOICE-\d+-*)$/.exec(path);
    if (method === 'GET' && invOne !== null) {
      const i = sessionInvoices.get(invOne[1] ?? '')?.find((x) => x.referenceNumber === invOne[2]);
      if (i === undefined) return json(res, 404, { title: 'Not Found', status: 404, detail: 'x' });
      return json(res, 200, mkInvoice(i, 1));
    }
    const closeM = /^\/sessions\/online\/(SESSION-\d+-*)\/close$/.exec(path);
    if (method === 'POST' && closeM !== null) {
      res.writeHead(204);
      return void res.end();
    }
    return json(res, 404, { title: 'Not Found', status: 404, detail: `${method} ${path}` });
  });
  return ps;
}
