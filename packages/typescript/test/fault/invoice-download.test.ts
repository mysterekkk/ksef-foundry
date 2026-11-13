import { afterEach, describe, expect, it } from 'vitest';
import {
  AbortException,
  ApiContext,
  ApiException,
  Crypto,
  Environment,
  HttpTransport,
  InvoiceService,
  NotFoundException,
  SensitiveString,
} from '../../src/index.js';
import { json, startServer, type LocalServer } from './local-server.js';

// fault-injection: real local HTTP server. NOT proof of KSeF compatibility (the live lifecycle test is).

const K = '5265877635-20261113-0100AAAAAAAA-AA';
const XML = '<Faktura>zażółć</Faktura>';
const NOT_YET = { title: 'Bad Request', status: 400, detail: 'x', traceId: 't', errors: [{ code: 21165, description: 'Faktura o podanym numerze KSeF nie jest jeszcze dostępna.' }] };
const servers: LocalServer[] = [];
afterEach(async () => {
  await Promise.all(servers.splice(0).map((s) => s.close()));
});

async function setup(h: Parameters<typeof startServer>[0]): Promise<{ svc: InvoiceService; server: LocalServer }> {
  const server = await startServer(h);
  servers.push(server);
  const http = new HttpTransport({ environment: Environment.custom(server.baseUrl) });
  const ctx = new ApiContext(http, { getAccessToken: () => Promise.resolve(new SensitiveString('t')), invalidate: () => Promise.resolve() });
  return { svc: new InvoiceService(ctx), server };
}
function ok(res: Parameters<Parameters<typeof startServer>[0]>[1]): void {
  res.writeHead(200, { 'content-type': 'application/xml', 'x-ms-meta-hash': Crypto.sha256Base64(XML) });
  res.end(XML);
}

describe('fault-injection: invoice "not yet available" (21165 / 406)', () => {
  it('retries 21165 twice, then returns the invoice with a matching hash', async () => {
    const { svc, server } = await setup((_q, res, _b, n) => (n <= 2 ? json(res, 400, NOT_YET) : ok(res)));
    const inv = await svc.getByKsefNumber(K, { pollIntervalMs: 50 });
    expect(inv.xml).toBe(XML);
    expect(inv.hashMatches).toBe(true);
    expect(server.hits).toHaveLength(3);
  });

  it('retries a bare 406', async () => {
    const { svc, server } = await setup((_q, res, _b, n) => {
      if (n === 1) {
        res.writeHead(406);
        res.end();
      } else ok(res);
    });
    expect((await svc.getByKsefNumber(K, { pollIntervalMs: 50 })).xml).toBe(XML);
    expect(server.hits).toHaveLength(2);
  });

  it('permanent 21165 is rethrown after the deadline (bounded)', async () => {
    const { svc, server } = await setup((_q, res) => json(res, 400, NOT_YET));
    const t0 = Date.now();
    const err = await svc.getByKsefNumber(K, { availabilityTimeoutMs: 600, pollIntervalMs: 100 }).catch((e: unknown) => e);
    const took = Date.now() - t0;
    expect(err).toBeInstanceOf(ApiException);
    expect((err as ApiException).ksefCode).toBe(21165);
    expect(took).toBeGreaterThanOrEqual(400);
    expect(took).toBeLessThan(2500);
    expect(server.hits.length).toBeGreaterThanOrEqual(3);
    expect(server.hits.length).toBeLessThanOrEqual(8);
  });

  it('other errors (404 / 21164) are not retried', async () => {
    const { svc, server } = await setup((_q, res) => json(res, 404, { title: 'Not Found', status: 404, detail: 'x', errors: [{ code: 21164, description: 'nie istnieje' }] }));
    await expect(svc.getByKsefNumber(K, { pollIntervalMs: 50 })).rejects.toBeInstanceOf(NotFoundException);
    expect(server.hits).toHaveLength(1);
  });

  it('an AbortSignal stops the wait promptly with AbortException', async () => {
    const { svc, server } = await setup((_q, res) => json(res, 400, NOT_YET));
    const ac = new AbortController();
    setTimeout(() => ac.abort(), 150);
    const t0 = Date.now();
    const err = await svc.getByKsefNumber(K, { signal: ac.signal, pollIntervalMs: 1000, availabilityTimeoutMs: 15_000 }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(AbortException);
    expect(Date.now() - t0).toBeLessThan(1000);
    expect(server.hits).toHaveLength(1);
  });

  it('keeps the plain (ksefNumber, AbortSignal) call form', async () => {
    const { svc } = await setup((_q, res) => ok(res));
    expect((await svc.getByKsefNumber(K, new AbortController().signal)).xml).toBe(XML);
  });
});
