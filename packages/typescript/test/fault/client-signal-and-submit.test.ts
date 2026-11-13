import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import {
  AbortException,
  Context,
  Environment,
  Fa3InvoiceBuilder,
  InvoiceValidationException,
  KsefClient,
  KsefTokenCredentials,
  ConfigurationException,
} from '../../src/index.js';
import { json, startServer, type LocalServer } from './local-server.js';
import { startProtocolServer, type ProtocolServer } from './protocol-server.js';

// fault-injection: real local HTTP servers. NOT proof of KSeF compatibility.

const NIP = '5265877635';
let ps: ProtocolServer | undefined;
const servers: LocalServer[] = [];
afterEach(async () => {
  await ps?.close();
  ps = undefined;
  await Promise.all(servers.splice(0).map((s) => s.close()));
});

let validXml = '';
beforeAll(async () => {
  validXml = await Fa3InvoiceBuilder.sampleXml(NIP, 'FV/SIGNAL/1');
});

function client(baseUrl: string, extra: Partial<Parameters<typeof KsefClient.create>[0]> = {}): KsefClient {
  return KsefClient.create({
    environment: Environment.custom(baseUrl),
    credentials: new KsefTokenCredentials('tok', Context.nip(NIP)),
    retry: { maxAttempts: 3, baseDelayMs: 5, maxDelayMs: 10, maxRetryAfterSeconds: 10 },
    ...extra,
  });
}

describe('sender.submit validates BEFORE opening a session', () => {
  it('invalid FA(3) XML: zero requests reach the server (no login, no /sessions/online)', async () => {
    ps = await startProtocolServer();
    const c = client(ps.server.baseUrl);
    const err = await c.sender.submit('<?xml version="1.0" encoding="UTF-8"?><Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"/>').catch((e: unknown) => e);
    expect(err).toBeInstanceOf(InvoiceValidationException);
    expect((err as InvoiceValidationException).issues.some((i) => i.code === 'xsd')).toBe(true);
    expect(ps.server.hits).toHaveLength(0);
    expect(ps.counters.open).toBe(0);
  });

  it('oversized, BOM-prefixed, DOCTYPE and empty inputs also cost zero requests', async () => {
    ps = await startProtocolServer();
    const c = client(ps.server.baseUrl);
    const big = Buffer.alloc(3_100_000, 0x20);
    const cases: [string, string | Uint8Array, RegExp | undefined][] = [
      ['size', big, undefined],
      ['bom', new Uint8Array([0xef, 0xbb, 0xbf, ...Buffer.from(validXml)]), undefined],
      ['doctype', '<!DOCTYPE a [<!ENTITY x "y">]><a/>', /DOCTYPE/],
      ['empty', '', /empty/],
    ];
    for (const [label, input, re] of cases) {
      const err = await c.sender.submit(input).catch((e: unknown) => e);
      expect(err, label).toBeInstanceOf(Error);
      if (label === 'size') expect((err as InvoiceValidationException).errors[0]?.code).toBe('size');
      if (label === 'bom') expect((err as InvoiceValidationException).errors[0]?.code).toBe('bom');
      if (re !== undefined) {
        expect(err, label).toBeInstanceOf(ConfigurationException);
        expect((err as Error).message).toMatch(re);
      }
    }
    expect(ps.server.hits).toHaveLength(0);
  });

  it('a custom validator that throws also prevents any request; a valid invoice then opens exactly one session', async () => {
    ps = await startProtocolServer();
    const c = client(ps.server.baseUrl);
    await expect(
      c.sender.submit(validXml, {
        validate: () => {
          throw new Error('custom veto');
        },
      }),
    ).rejects.toThrow('custom veto');
    expect(ps.server.hits).toHaveLength(0);
    const r = await c.sender.submit(validXml, { await: { pollIntervalMs: 10 } });
    expect(r.ksefNumber).toMatch(/^5265877635-/);
    expect(ps.counters.open).toBe(1);
    expect(ps.counters.send).toBe(1); // validated once, not re-validated or re-sent
  });
});

describe('KsefClientOptions.signal', () => {
  it('an already-aborted client signal sends nothing', async () => {
    ps = await startProtocolServer();
    const c = client(ps.server.baseUrl, { signal: AbortSignal.abort() });
    await expect(c.getAccessToken()).rejects.toBeInstanceOf(AbortException);
    await expect(c.limits.context()).rejects.toBeInstanceOf(AbortException);
    expect(ps.server.hits).toHaveLength(0);
  });

  it('aborting mid-request cancels it and is never retried', async () => {
    const s = await startServer(() => undefined); // hangs
    servers.push(s);
    const ac = new AbortController();
    const c = client(s.baseUrl, { signal: ac.signal });
    setTimeout(() => ac.abort(), 150);
    const t0 = Date.now();
    const err = await c.http.request({ method: 'GET', path: '/x' }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(AbortException);
    expect(Date.now() - t0).toBeLessThan(1500);
    expect(s.hits).toHaveLength(1);
  });

  it('aborting during a Retry-After wait stops immediately (no further attempt)', async () => {
    const s = await startServer((_q, res) => json(res, 429, {}, { 'retry-after': '5' }));
    servers.push(s);
    const ac = new AbortController();
    const c = client(s.baseUrl, { signal: ac.signal });
    setTimeout(() => ac.abort(), 150);
    const t0 = Date.now();
    const err = await c.http.request({ method: 'GET', path: '/x' }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(AbortException);
    expect(Date.now() - t0).toBeLessThan(1500);
    expect(s.hits).toHaveLength(1);
  });

  it('aborting during a polling wait (awaitInvoice) stops the poll', async () => {
    ps = await startProtocolServer();
    const ac = new AbortController();
    const c = client(ps.server.baseUrl, { signal: ac.signal });
    const session = await c.sessions.openOnline();
    const sent = await c.sessions.sendInvoice(session, validXml);
    ps.invoiceStatusCode = 150;
    setTimeout(() => ac.abort(), 300);
    const t0 = Date.now();
    const err = await c.sessions.awaitInvoice(session.referenceNumber, sent.referenceNumber, { pollIntervalMs: 2000, timeoutMs: 60_000 }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(AbortException);
    expect(Date.now() - t0).toBeLessThan(1500);
  });

  it('the client signal combines with a per-call signal (either aborts)', async () => {
    const s = await startServer(() => undefined);
    servers.push(s);
    const clientAc = new AbortController();
    const callAc = new AbortController();
    const c = client(s.baseUrl, { signal: clientAc.signal });
    setTimeout(() => callAc.abort(), 100);
    const err = await c.http.request({ method: 'GET', path: '/x', signal: callAc.signal }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(AbortException);
    expect(clientAc.signal.aborted).toBe(false);
  });

  it('without a signal nothing changes (control)', async () => {
    ps = await startProtocolServer();
    const c = client(ps.server.baseUrl);
    expect((await c.getAccessToken()).reveal()).toMatch(/^ACCESS-/);
  });
});
