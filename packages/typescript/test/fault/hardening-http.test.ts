import { gzipSync } from 'node:zlib';
import { afterEach, describe, expect, it } from 'vitest';
import {
  ApiContext,
  ApiException,
  AuthenticationException,
  ConfigurationException,
  Context,
  Environment,
  HttpTransport,
  InvoiceQueryService,
  KsefClient,
  KsefTokenCredentials,
  KsefTokenService,
  PaginationException,
  SensitiveString,
  UnknownOutcomeException,
} from '../../src/index.js';
import { json, startServer, type LocalServer } from './local-server.js';
import { startProtocolServer, type ProtocolServer } from './protocol-server.js';

// fault-injection: real local HTTP servers. NOT proof of KSeF compatibility.

const NIP = '5265877635';
const REF = 'ABCDEFGH-AU-0123456789-0123456789-AB';
const servers: LocalServer[] = [];
let ps: ProtocolServer | undefined;
afterEach(async () => {
  await ps?.close();
  ps = undefined;
  await Promise.all(servers.splice(0).map((s) => s.close()));
});
async function serve(h: Parameters<typeof startServer>[0]): Promise<LocalServer> {
  const s = await startServer(h);
  servers.push(s);
  return s;
}
function ctxFor(s: LocalServer): ApiContext {
  const http = new HttpTransport({ environment: Environment.custom(s.baseUrl), retry: { maxAttempts: 2, baseDelayMs: 5, maxDelayMs: 10 } });
  return new ApiContext(http, { getAccessToken: () => Promise.resolve(new SensitiveString('t')), invalidate: () => Promise.resolve() });
}

describe('L3: path traversal is impossible', () => {
  it.each(['/sessions/../../tokens', '/sessions/./x', '/a//b', '/a/b/', '/%2e%2e/tokens', '/a/%2E%2E/b', '/a/%2e/b', '/a/b%2Fc', '/a/b%5Cc', '/a?x=1', '/a#f', '/a/%zz'])(
    'transport refuses %s without any request',
    async (path) => {
      const s = await serve((_q, res) => json(res, 200, {}));
      const http = new HttpTransport({ environment: Environment.custom(s.baseUrl) });
      await expect(http.request({ method: 'GET', path })).rejects.toBeInstanceOf(ConfigurationException);
      expect(s.hits).toHaveLength(0);
    },
  );

  it('services validate reference numbers and KSeF numbers before calling', async () => {
    const s = await serve((_q, res) => json(res, 200, {}));
    const c = KsefClient.create({
      environment: Environment.custom(s.baseUrl),
      credentials: new KsefTokenCredentials('tok', Context.nip(NIP)),
    });
    const bad = ['..', '.', '', 'x', '../../tokens', `${REF}/../x`, `${REF.slice(0, 35)}/`, 'ABCDEFGH-AU-0123456789-0123456789-A%'];
    for (const b of bad) {
      await expect(c.sessions.getStatus(b), b).rejects.toBeInstanceOf(ConfigurationException);
      await expect(c.sessions.getInvoiceStatus(REF, b), b).rejects.toBeInstanceOf(ConfigurationException);
      await expect(c.tokens.get(b), b).rejects.toBeInstanceOf(ConfigurationException);
      await expect(c.tokens.revoke(b), b).rejects.toBeInstanceOf(ConfigurationException);
      await expect(c.authSessions.revoke(b), b).rejects.toBeInstanceOf(ConfigurationException);
      await expect(c.upo.getSessionUpo(REF, b), b).rejects.toBeInstanceOf(ConfigurationException);
      await expect(c.invoices.getByKsefNumber(b), b).rejects.toBeInstanceOf(ConfigurationException);
    }
    await expect(c.invoices.getByKsefNumber('5265877635-20261113-0100AAAAAAAA-A')).rejects.toBeInstanceOf(ConfigurationException);
    await expect(c.upo.getInvoiceUpoByKsefNumber(REF, '../x')).rejects.toBeInstanceOf(ConfigurationException);
    expect(s.hits).toHaveLength(0); // not even a login
  });
});

describe('L4: response size cap (incl. decompression bombs)', () => {
  it('rejects an oversized body announced by Content-Length without reading it', async () => {
    const s = await serve((_q, res) => {
      res.writeHead(200, { 'content-type': 'application/json', 'content-length': String(5_000_000) });
      res.write('{"a":"');
      setTimeout(() => res.destroy(), 200);
    });
    const http = new HttpTransport({ environment: Environment.custom(s.baseUrl), maxResponseBytes: 1_000_000 });
    const err = await http.request({ method: 'GET', path: '/x', idempotent: false }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiException);
    expect((err as ApiException).category).toBe('response_too_large');
  });

  it('rejects an oversized chunked body (no Content-Length) while streaming', async () => {
    const s = await serve((_q, res) => {
      res.writeHead(200, { 'content-type': 'application/json' });
      const chunk = Buffer.alloc(256 * 1024, 0x61);
      let n = 0;
      const t = setInterval(() => {
        if (res.destroyed || n++ > 200) {
          clearInterval(t);
          res.end();
          return;
        }
        res.write(chunk);
      }, 1);
    });
    const http = new HttpTransport({ environment: Environment.custom(s.baseUrl), maxResponseBytes: 1_000_000 });
    const err = await http.request({ method: 'GET', path: '/x', idempotent: false }).catch((e: unknown) => e);
    expect((err as ApiException).category).toBe('response_too_large');
  });

  it('rejects a gzip bomb (tiny on the wire, huge after decompression)', async () => {
    const bomb = gzipSync(Buffer.alloc(80 * 1024 * 1024, 0x20)); // 80 MiB of spaces
    expect(bomb.length).toBeLessThan(200_000);
    const s = await serve((_q, res) => {
      res.writeHead(200, { 'content-type': 'application/json', 'content-encoding': 'gzip', 'content-length': bomb.length });
      res.end(bomb);
    });
    const http = new HttpTransport({ environment: Environment.custom(s.baseUrl), maxResponseBytes: 1_000_000 });
    const err = await http.request({ method: 'GET', path: '/x', idempotent: false }).catch((e: unknown) => e);
    expect((err as ApiException).category).toBe('response_too_large');
  });

  it('applies to error bodies and bytes/text responses; accepts bodies within the limit', async () => {
    const big = Buffer.alloc(300_000, 0x62);
    const s = await serve((q, res) => {
      if (q.url?.endsWith('/err')) {
        res.writeHead(500, { 'content-type': 'application/json' });
        res.end(big);
      } else {
        res.writeHead(200, { 'content-type': 'application/xml' });
        res.end(big);
      }
    });
    const http = new HttpTransport({ environment: Environment.custom(s.baseUrl), maxResponseBytes: 100_000 });
    expect(((await http.request({ method: 'GET', path: '/err', idempotent: false }).catch((e: unknown) => e)) as ApiException).category).toBe('response_too_large');
    expect(((await http.request({ method: 'GET', path: '/b', responseType: 'bytes' }).catch((e: unknown) => e)) as ApiException).category).toBe('response_too_large');
    const ok = await http.request<Uint8Array>({ method: 'GET', path: '/b', responseType: 'bytes', maxResponseBytes: 400_000 });
    expect(ok.data.byteLength).toBe(300_000);
  });

  it('an oversized 2xx answer to a send is an unknown outcome', async () => {
    const s = await serve((_q, res) => {
      res.writeHead(202, { 'content-type': 'application/json', 'content-length': '5000000' });
      res.write('{');
      setTimeout(() => res.destroy(), 100);
    });
    const http = new HttpTransport({ environment: Environment.custom(s.baseUrl), maxResponseBytes: 1000 });
    const err = await http
      .request({ method: 'POST', path: '/x', json: {}, unknownOutcome: { operation: 'sendInvoice', invoiceHash: 'H', sessionReferenceNumber: 'S' } })
      .catch((e: unknown) => e);
    expect(err).toBeInstanceOf(UnknownOutcomeException);
  });

  it('default cap is 64 MiB (a 1 MiB answer passes)', async () => {
    const s = await serve((_q, res) => {
      res.writeHead(200, { 'content-type': 'application/xml' });
      res.end(Buffer.alloc(1_048_576, 0x63));
    });
    const http = new HttpTransport({ environment: Environment.custom(s.baseUrl) });
    expect((await http.request<string>({ method: 'GET', path: '/x', responseType: 'text' })).data).toHaveLength(1_048_576);
  });
});

describe('L6: no login storm on persistent 401 / failed login', () => {
  function client(p: ProtocolServer, backoff: number): KsefClient {
    return KsefClient.create({
      environment: Environment.custom(p.server.baseUrl),
      credentials: new KsefTokenCredentials('tok', Context.nip(NIP)),
      retry: { maxAttempts: 2, baseDelayMs: 5, maxDelayMs: 10 },
      reloginBackoffMs: backoff,
    });
  }

  it('persistent 401 on API calls: one re-login per request at most, then fail fast without any /auth/* traffic', async () => {
    ps = await startProtocolServer();
    const c = client(ps, 600);
    await c.getAccessToken();
    expect(ps.counters.login).toBe(1);
    ps.reject401Always = true;
    const e1 = await c.limits.context().catch((e: unknown) => e);
    expect(e1).toBeInstanceOf(AuthenticationException);
    expect(ps.counters.login).toBe(2); // exactly one re-login for that request
    const challenges = ps.counters.challenge;
    for (let i = 0; i < 6; i++) {
      await expect(c.limits.context()).rejects.toBeInstanceOf(AuthenticationException);
    }
    expect(ps.counters.login).toBe(2); // fail-fast window: no further logins
    expect(ps.counters.challenge).toBe(challenges);
    // after the window a fresh re-login is attempted again (and once more only)
    await new Promise((r) => setTimeout(r, 700));
    await expect(c.limits.context()).rejects.toBeInstanceOf(AuthenticationException);
    expect(ps.counters.login).toBe(3);
    await expect(c.limits.context()).rejects.toBeInstanceOf(AuthenticationException);
    expect(ps.counters.login).toBe(3);
  });

  it('recovers: a 401 that a fresh login cures re-logs in once and later 401s may re-login again', async () => {
    ps = await startProtocolServer();
    const c = client(ps, 600);
    await c.getAccessToken();
    ps.reject401Once = true;
    expect((await c.limits.context()).onlineSession.maxInvoices).toBe(10000);
    expect(ps.counters.login).toBe(2);
    ps.reject401Once = true;
    await c.limits.context();
    expect(ps.counters.login).toBe(3);
  });

  it('a failing login (auth status 450) is negatively cached: one login attempt per window, same error', async () => {
    ps = await startProtocolServer();
    ps.authFailCode = 450;
    const c = client(ps, 500);
    const errs: unknown[] = [];
    for (let i = 0; i < 6; i++) errs.push(await c.limits.context().catch((e: unknown) => e));
    expect(errs.every((e) => e instanceof AuthenticationException)).toBe(true);
    expect((errs[0] as AuthenticationException).authStatusCode).toBe(450);
    expect(ps.counters.challenge).toBe(1);
    await new Promise((r) => setTimeout(r, 600));
    await c.limits.context().catch(() => undefined);
    expect(ps.counters.challenge).toBe(2);
  });
});

describe('L7: pagination always terminates', () => {
  it('a repeated continuation token throws PaginationException', async () => {
    const s = await serve((_q, res) => json(res, 200, { continuationToken: 'same', tokens: [] }));
    const svc = new KsefTokenService(ctxFor(s));
    const err = await (async () => {
      for await (const _ of svc.iterate()) {
        /* drain */
      }
    })().catch((e: unknown) => e);
    expect(err).toBeInstanceOf(PaginationException);
    expect(s.hits.length).toBeLessThanOrEqual(3);
  });

  it('endlessly new tokens stop at maxPages', async () => {
    let n = 0;
    const s = await serve((_q, res) => json(res, 200, { continuationToken: `t${String(++n)}`, tokens: [] }));
    const svc = new KsefTokenService(ctxFor(s));
    const err = await (async () => {
      for await (const _ of svc.iterate({ maxPages: 5 })) {
        /* drain */
      }
    })().catch((e: unknown) => e);
    expect(err).toBeInstanceOf(PaginationException);
    expect((err as PaginationException).pages).toBe(5);
    expect(s.hits).toHaveLength(5);
  });

  it('well-behaved pagination still completes', async () => {
    let n = 0;
    const s = await serve((_q, res) => {
      n += 1;
      json(res, 200, { continuationToken: n < 3 ? `t${String(n)}` : null, tokens: [{ referenceNumber: `r${String(n)}` }] });
    });
    const out: string[] = [];
    for await (const t of new KsefTokenService(ctxFor(s)).iterate()) out.push(t.referenceNumber);
    expect(out).toEqual(['r1', 'r2', 'r3']);
  });

  const inv = {
    ksefNumber: '5265877635-20261113-0100AAAAAAAA-AA',
    invoiceNumber: 'X',
    issueDate: '2026-11-13',
    invoicingDate: '2026-11-13T10:00:00+00:00',
    acquisitionDate: '2026-11-13T10:00:00+00:00',
    permanentStorageDate: '2026-11-13T10:00:00+00:00',
  };
  const filters = { subjectType: 'Subject1', dateRange: { dateType: 'PermanentStorage', from: new Date(Date.now() - 86_400_000) } } as const;

  it('metadata: hasMore forever with the same invoices (no progress) throws', async () => {
    const s = await serve((_q, res) => json(res, 200, { hasMore: true, isTruncated: false, invoices: [inv] }));
    const q = new InvoiceQueryService(ctxFor(s));
    const err = await (async () => {
      for await (const _ of q.iterateMetadata(filters)) {
        /* drain */
      }
    })().catch((e: unknown) => e);
    expect(err).toBeInstanceOf(PaginationException);
    expect(s.hits.length).toBeLessThanOrEqual(3);
  });

  it('metadata: truncated forever without narrowing the range throws; maxPages bounds fresh-item floods', async () => {
    const s = await serve((_q, res) => json(res, 200, { hasMore: true, isTruncated: true, invoices: [inv] }));
    const q = new InvoiceQueryService(ctxFor(s));
    const err = await (async () => {
      for await (const _ of q.iterateMetadata(filters)) {
        /* drain */
      }
    })().catch((e: unknown) => e);
    expect(err).toBeInstanceOf(PaginationException);
    let n = 0;
    const s2 = await serve((_q, res) => json(res, 200, { hasMore: true, isTruncated: false, invoices: [{ ...inv, ksefNumber: `5265877635-20261113-${(++n).toString(16).toUpperCase().padStart(6, '0')}-0100AA-AA`.replace(/-0100AA-AA$/, '-0100AA-AA') }] }));
    const q2 = new InvoiceQueryService(ctxFor(s2));
    const err2 = await (async () => {
      for await (const _ of q2.iterateMetadata(filters, { maxPages: 4 })) {
        /* drain */
      }
    })().catch((e: unknown) => e);
    expect(err2).toBeInstanceOf(PaginationException);
    expect(s2.hits).toHaveLength(4);
  });
});
