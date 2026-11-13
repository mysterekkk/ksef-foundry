import { Console } from 'node:console';
import { inspect } from 'node:util';
import { PassThrough } from 'node:stream';
import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import {
  AbortException,
  Context,
  Environment,
  Fa3InvoiceBuilder,
  GeneratedKsefToken,
  KsefClient,
  KsefTokenCredentials,
  TokenPair,
  UnknownOutcomeException,
} from '../../src/index.js';
import { startProtocolServer, type ProtocolServer } from './protocol-server.js';

// fault-injection: real local HTTP server. NOT proof of KSeF compatibility.

const NIP = '5265877635';
let ps: ProtocolServer | undefined;
afterEach(async () => {
  await ps?.close();
  ps = undefined;
});
let xml = '';
beforeAll(async () => {
  xml = await Fa3InvoiceBuilder.sampleXml(NIP, 'FV/HARDEN/1');
});

async function setup(extra: Partial<Parameters<typeof KsefClient.create>[0]> = {}): Promise<{ ps: ProtocolServer; c: KsefClient }> {
  ps = await startProtocolServer();
  const c = KsefClient.create({
    environment: Environment.custom(ps.server.baseUrl),
    credentials: new KsefTokenCredentials('tok', Context.nip(NIP)),
    retry: { maxAttempts: 2, baseDelayMs: 5, maxDelayMs: 10 },
    timeoutMs: 3000,
    ...extra,
  });
  return { ps, c };
}

describe('M3: garbled / empty / wrong-shape 2xx answers to a send are an UNKNOWN outcome', () => {
  it.each(['garbled-202', 'truncated-202', 'empty-202', 'wrong-shape-202'] as const)('send answer %s -> UnknownOutcomeException with hash + session', async (mode) => {
    const { ps: p, c } = await setup();
    const s = await c.sessions.openOnline();
    p.sendMode = mode;
    const err = await c.sessions.sendInvoice(s, xml).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(UnknownOutcomeException);
    const u = err as UnknownOutcomeException;
    expect(u.invoiceHash).toBe(p.received[0]?.hash); // the server really got the invoice
    expect(u.sessionReferenceNumber).toBe(s.referenceNumber);
    expect(u.operation).toBe('sendInvoice');
    expect(u.cause).toBeInstanceOf(Error);
    expect(p.counters.send).toBe(1); // never auto-retried
    // and reconcile finds it, so the caller does not resend
    expect((await c.sessions.reconcile(s, u.invoiceHash ?? ''))?.invoiceHash).toBe(u.invoiceHash);
  });

  it.each(['garbled', 'empty', 'wrong-shape'] as const)('open-session answer %s -> UnknownOutcomeException (no TypeError)', async (mode) => {
    const { ps: p, c } = await setup();
    await c.getAccessToken();
    p.openMode = mode;
    const err = await c.sessions.openOnline().catch((e: unknown) => e);
    expect(err).toBeInstanceOf(UnknownOutcomeException);
    expect((err as UnknownOutcomeException).operation).toBe('openOnlineSession');
    expect(p.counters.open).toBe(1);
  });

  it('InvoiceSender.submit surfaces the unknown outcome after reconciling (invoice present -> continues)', async () => {
    const { ps: p, c } = await setup();
    p.sendMode = 'garbled-202';
    const r = await c.sender.submit(xml, { reconcileDelayMs: 10, await: { pollIntervalMs: 10 } });
    expect(r.ksefNumber).toMatch(/^5265877635-/);
    expect(p.counters.send).toBe(1);
  });

  it('the exception is a KsefException subclass that adapters can map to HTTP 409 by class', async () => {
    const { ps: p, c } = await setup();
    const s = await c.sessions.openOnline();
    p.sendMode = 'empty-202';
    const err = await c.sessions.sendInvoice(s, xml).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(UnknownOutcomeException);
    expect((err as UnknownOutcomeException).category).toBe('unknown_outcome');
    expect((err as UnknownOutcomeException).name).toBe('UnknownOutcomeException');
  });

});

describe('L5: abort during a send is an UNKNOWN outcome', () => {
  it('abort mid-send -> UnknownOutcomeException (cause AbortException) with hash and session', async () => {
    const ac = new AbortController();
    const { ps: p, c } = await setup({ signal: ac.signal });
    const s = await c.sessions.openOnline();
    p.sendMode = 'hang';
    setTimeout(() => ac.abort(), 200);
    const err = await c.sessions.sendInvoice(s, xml).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(UnknownOutcomeException);
    const u = err as UnknownOutcomeException;
    expect(u.cause).toBeInstanceOf(AbortException);
    expect(u.invoiceHash).toMatch(/^[A-Za-z0-9+/]{43}=$/);
    expect(u.sessionReferenceNumber).toBe(s.referenceNumber);
    expect(p.counters.send).toBe(1);
  });

  it('per-call signal behaves the same; a signal aborted BEFORE the send is a plain AbortException (nothing was sent)', async () => {
    const { ps: p, c } = await setup();
    const s = await c.sessions.openOnline();
    p.sendMode = 'hang';
    const ac = new AbortController();
    setTimeout(() => ac.abort(), 150);
    const err = await c.sessions.sendInvoice(s, xml, { signal: ac.signal }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(UnknownOutcomeException);
    const before = p.counters.send;
    const err2 = await c.sessions.sendInvoice(s, xml, { signal: AbortSignal.abort() }).catch((e: unknown) => e);
    expect(err2).toBeInstanceOf(AbortException);
    expect(p.counters.send).toBe(before);
  });

  it('InvoiceSender.submit keeps the ORIGINAL UnknownOutcomeException when aborted mid-send / during reconcile; a later reconcile with a fresh signal works', async () => {
    const ac = new AbortController();
    const { ps: p, c } = await setup({ signal: ac.signal });
    await c.getAccessToken(); // warm up login + keys so the abort lands during the SEND
    await c.publicKeys.getKey('SymmetricKeyEncryption');
    p.sendMode = 'hang';
    setTimeout(() => ac.abort(), 400);
    const err = await c.sender.submit(xml, { reconcileDelayMs: 50 }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(UnknownOutcomeException);
    const u = err as UnknownOutcomeException;
    expect(u.invoiceHash).toMatch(/^[A-Za-z0-9+/]{43}=$/);
    expect(u.sessionReferenceNumber).toMatch(/^SESSION-/);
    expect(u.cause).toBeInstanceOf(AbortException);
    expect(p.counters.send).toBe(1);
    // reconcile later with a client that is NOT aborted (fresh signal): the server never processed the invoice
    const fresh = KsefClient.create({
      environment: Environment.custom(p.server.baseUrl),
      credentials: new KsefTokenCredentials('tok', Context.nip(NIP)),
    });
    expect(await fresh.sessions.reconcile(u.sessionReferenceNumber ?? '', u.invoiceHash ?? '')).toBeUndefined();
  });

  it('per-call signal aborted while submit is reconciling (invoice processed, answer lost) still reports UnknownOutcomeException', async () => {
    const { ps: p, c } = await setup();
    await c.getAccessToken();
    await c.publicKeys.getKey('SymmetricKeyEncryption');
    p.sendMode = 'process-then-reset';
    const ac = new AbortController();
    setTimeout(() => ac.abort(), 400); // aborts during the reconcile wait (reconcileDelayMs 2000)
    const err = await c.sender.submit(xml, { signal: ac.signal, reconcileDelayMs: 2000 }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(UnknownOutcomeException);
    expect((err as UnknownOutcomeException).invoiceHash).toBe(p.received[0]?.hash);
    expect(p.counters.send).toBe(1);
    expect((await c.sessions.reconcile((err as UnknownOutcomeException).sessionReferenceNumber ?? '', (err as UnknownOutcomeException).invoiceHash ?? ''))?.invoiceHash).toBe(
      p.received[0]?.hash,
    );
  });

  it('abort of a non-send request stays a plain AbortException', async () => {
    const ac = new AbortController();
    const { ps: p, c } = await setup();
    await c.getAccessToken();
    p.sendMode = 'ok';
    ac.abort();
    await expect(c.limits.context(ac.signal)).rejects.toBeInstanceOf(AbortException);
  });
});

describe('L2: tokens do not leak through console.log / util.inspect / JSON / String', () => {
  const ACCESS = 'eyJhbGciOiJIUzI1NiJ9.ACCESS-SECRET.sig';
  const REFRESH = 'eyJhbGciOiJIUzI1NiJ9.REFRESH-SECRET.sig';
  const KSEF = 'KSEF-LONG-LIVED-SECRET-TOKEN';

  function viaConsole(v: unknown): string {
    const out = new PassThrough();
    const chunks: Buffer[] = [];
    out.on('data', (b: Buffer) => chunks.push(b));
    new Console(out).log(v);
    new Console(out).log('%s %j %o', v, v, v);
    return Buffer.concat(chunks).toString('utf8');
  }
  function everything(v: unknown): string {
    return [inspect(v, { depth: 8, showHidden: true }), JSON.stringify(v), String(v), viaConsole(v)].join('\n');
  }

  it('TokenPair: old accessors work, representations are redacted, storage helpers round-trip', () => {
    const pair = new TokenPair({ accessToken: ACCESS, accessTokenExpiresAt: 1, refreshToken: REFRESH, refreshTokenExpiresAt: 2 });
    expect(pair.accessToken).toBe(ACCESS);
    expect(pair.refreshToken).toBe(REFRESH);
    const dump = everything(pair) + everything({ nested: { pair } });
    expect(dump).not.toContain('ACCESS-SECRET');
    expect(dump).not.toContain('REFRESH-SECRET');
    expect(dump).toContain('[REDACTED]');
    const stored = pair.toStorage();
    expect(JSON.parse(JSON.stringify(stored))).toEqual({ accessToken: ACCESS, accessTokenExpiresAt: 1, refreshToken: REFRESH, refreshTokenExpiresAt: 2 });
    expect(TokenPair.fromStorage(JSON.parse(JSON.stringify(stored))).accessToken).toBe(ACCESS);
    // JSON.stringify(pair) is redacted on purpose; restoring from it fails loudly instead of silently breaking auth
    expect(() => TokenPair.fromStorage(JSON.parse(JSON.stringify(pair)))).toThrow(/redacted/i);
    expect(() => TokenPair.fromStorage({ nope: 1 })).toThrow(/malformed/);
  });

  it('a pair obtained from the manager does not leak either', async () => {
    const { c } = await setup();
    const pair = await c.authentication.getTokenPair(c.credentials);
    const dump = everything(pair);
    expect(dump).not.toContain(pair.accessToken);
    expect(dump).not.toContain(pair.refreshToken);
    expect(pair.accessToken).toMatch(/^ACCESS-/);
  });

  it('a storage that persisted JSON.stringify(pair) (redacted) is dropped and the manager logs in again', async () => {
    const mem = new Map<string, unknown>();
    const storage = {
      get: (k: string) => Promise.resolve(mem.get(k) as undefined),
      put: (k: string, pair: TokenPair) => {
        mem.set(k, JSON.parse(JSON.stringify(pair))); // the mistake the redaction is designed to expose
        return Promise.resolve();
      },
      delete: (k: string) => {
        mem.delete(k);
        return Promise.resolve();
      },
    };
    const { ps: p, c } = await setup({ storage });
    await c.getAccessToken();
    await c.getAccessToken();
    expect(p.counters.login).toBe(2); // second call could not use the redacted entry
  });

  it('GeneratedKsefToken: .token / .secret() work, representations are redacted', () => {
    const g = new GeneratedKsefToken({ referenceNumber: 'REF', token: KSEF });
    expect(g.token).toBe(KSEF);
    expect(g.secret().reveal()).toBe(KSEF);
    const dump = everything(g) + everything([g]);
    expect(dump).not.toContain(KSEF);
    expect(dump).toContain('REDACTED');
    expect(JSON.parse(JSON.stringify(g))).toEqual({ referenceNumber: 'REF', token: '[REDACTED]' });
  });
});
