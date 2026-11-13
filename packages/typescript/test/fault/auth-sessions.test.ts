import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import {
  AbortException,
  ConfigurationException,
  Fa3InvoiceBuilder,
  FormCodes,
  InvoiceValidationException,
  XmlValidationException,
  AuthenticationException,
  Context,
  Environment,
  InMemoryTokenStorage,
  InvoiceRejectedException,
  KsefClient,
  KsefTokenCredentials,
  NetworkException,
  SessionException,
  UnknownOutcomeException,
  ValidationException,
} from '../../src/index.js';
import { requestSchema, validate } from '../contract/openapi.js';
import { startProtocolServer, type ProtocolServer } from './protocol-server.js';

// fault-injection / protocol fixture: real local HTTP server + real crypto. NOT proof of KSeF compatibility.

const NIP_A = '5265877635';
const NIP_B = '1132191444';
let ps: ProtocolServer;
afterEach(async () => {
  await ps.close();
});

async function setup(): Promise<void> {
  ps = await startProtocolServer();
}
function client(token: string, nip: string, extra: Partial<Parameters<typeof KsefClient.create>[0]> = {}): KsefClient {
  return KsefClient.create({
    environment: Environment.custom(ps.server.baseUrl),
    credentials: new KsefTokenCredentials(token, Context.nip(nip)),
    retry: { maxAttempts: 2, baseDelayMs: 5, maxDelayMs: 10 },
    ...extra,
  });
}

describe('AuthenticationManager (token flow, real crypto)', () => {
  it('logs in: challenge -> RSA-OAEP(token|timestampMs) -> poll -> redeem; token is decrypted correctly by the server', async () => {
    await setup();
    const c = client('my-ksef-token', NIP_A);
    const jwt = await c.getAccessToken();
    expect(jwt.reveal()).toMatch(/^ACCESS-[0-9a-f]{8}@5265877635-/);
    expect(ps.counters).toMatchObject({ challenge: 1, login: 1, redeem: 1, refresh: 0 });
    expect(ps.loginContexts).toEqual(['Nip:5265877635']);
    // cached: no second login
    await c.getAccessToken();
    expect(ps.counters.login).toBe(1);
  });

  it('single-flight: 25 concurrent callers share one login', async () => {
    await setup();
    const c = client('tok', NIP_A);
    const results = await Promise.all(Array.from({ length: 25 }, () => c.getAccessToken()));
    expect(new Set(results.map((r) => r.reveal())).size).toBe(1);
    expect(ps.counters).toMatchObject({ challenge: 1, login: 1, redeem: 1 });
  });

  it('tenant isolation: different token or different context never share JWTs or cache entries', async () => {
    await setup();
    const storage = new InMemoryTokenStorage();
    const a = client('tok-A', NIP_A, { storage });
    const a2 = client('tok-A', NIP_B, { storage }); // same token, other context
    const b = client('tok-B', NIP_A, { storage }); // other token, same context
    const [ja, ja2, jb] = await Promise.all([a.getAccessToken(), a2.getAccessToken(), b.getAccessToken()]);
    expect(new Set([ja.reveal(), ja2.reveal(), jb.reveal()]).size).toBe(3);
    expect(ps.counters.login).toBe(3);
    expect(storage.size).toBe(3);
    const keys = new Set([a.authentication.tenantKey(a.credentials), a2.authentication.tenantKey(a2.credentials), b.authentication.tenantKey(b.credentials)]);
    expect(keys.size).toBe(3);
    // the server sees the right bearer per tenant
    const who = async (c: KsefClient): Promise<string> => ((await c.limits.context()) as unknown as { whoami: string }).whoami;
    expect(await who(a)).toBe(ja.reveal());
    expect(await who(a2)).toBe(ja2.reveal());
    // identical credentials in two client instances sharing storage reuse the cached pair
    const aAgain = client('tok-A', NIP_A, { storage });
    await aAgain.getAccessToken();
    expect(ps.counters.login).toBe(3);
    // tenant key never contains the secret
    expect(a.authentication.tenantKey(a.credentials)).not.toContain('tok-A');
    expect(storage.size).toBe(3);
  });

  it('refreshes (not re-logs-in) when the access token is about to expire', async () => {
    await setup();
    ps.accessTtlMs = 1500;
    const c = client('tok', NIP_A, { expirySkewSeconds: 1 });
    const first = await c.getAccessToken();
    await new Promise((r) => setTimeout(r, 700));
    const second = await c.getAccessToken();
    expect(second.reveal()).not.toBe(first.reveal());
    expect(ps.counters).toMatchObject({ login: 1, refresh: 1 });
  });

  it('re-logs in once when the refresh token is rejected (401)', async () => {
    await setup();
    ps.accessTtlMs = 1500;
    const c = client('tok', NIP_A, { expirySkewSeconds: 1 });
    await c.getAccessToken();
    ps.refreshFails = true;
    await new Promise((r) => setTimeout(r, 700));
    const again = await c.getAccessToken();
    expect(again.reveal()).toMatch(/^ACCESS-/);
    expect(ps.counters).toMatchObject({ login: 2, refresh: 1 });
  });

  it('a 401 on an API call drops the cache and retries once with a fresh login', async () => {
    await setup();
    const c = client('tok', NIP_A);
    await c.getAccessToken();
    ps.reject401Once = true;
    const lim = await c.limits.context();
    expect(lim.onlineSession.maxInvoices).toBe(10000);
    expect(ps.counters.login).toBe(2);
  });

  it('error 21470: refetches the public keys once and retries once', async () => {
    await setup();
    const c = client('tok', NIP_A);
    await c.publicKeys.getKey('KsefTokenEncryption'); // warm cache with key #1
    expect(ps.counters.keys).toBe(1);
    await ps.rotateTokenKey(); // server now only accepts key #2
    const jwt = await c.getAccessToken();
    expect(jwt.reveal()).toMatch(/^ACCESS-/);
    expect(ps.counters.keys).toBe(2);
    expect(ps.counters.login).toBe(1);
  });

  it('keeps the long-lived token out of storage, errors and diagnostics', async () => {
    await setup();
    const storage = new InMemoryTokenStorage();
    const secret = 'VERY-SECRET-LONG-LIVED-KSEF-TOKEN';
    const c = client(secret, NIP_A, { storage });
    await c.getAccessToken();
    const pair = await storage.get(c.authentication.tenantKey(c.credentials));
    expect(JSON.stringify(pair)).not.toContain(secret);
    expect(JSON.stringify(c.credentials)).not.toContain(secret);
    expect(JSON.stringify(c)).not.toContain(secret);
  });

  it('caller abort rejects with AbortException without cancelling the shared login for others', async () => {
    await setup();
    const c = client('tok', NIP_A);
    const ac = new AbortController();
    const aborted = c.getAccessToken(ac.signal);
    const other = c.getAccessToken();
    ac.abort();
    await expect(aborted).rejects.toBeInstanceOf(AbortException);
    await expect(other).resolves.toBeDefined();
    expect(ps.counters.login).toBe(1);
  });

  it('login against a dead server surfaces NetworkException', async () => {
    await setup();
    const c = client('tok', NIP_A);
    await ps.close();
    await expect(c.getAccessToken()).rejects.toBeInstanceOf(NetworkException);
    ps = await startProtocolServer(); // so afterEach can close something
  });

  it('an HTTP 401 maps to AuthenticationException', async () => {
    await setup();
    const c = client('tok', NIP_A);
    // Unknown reference makes the status poll answer 401 -> AuthenticationException
    await c.authentication.invalidate(c.credentials);
    const err = await c.http
      .request({ method: 'GET', path: '/auth/AUTHREF-999', bearer: 'TEMP-x' })
      .catch((e: unknown) => e);
    expect(err).toBeInstanceOf(AuthenticationException);
  });
});

// A real, XSD-valid FA(3) document: sessions validate against the bundled XSD by default.
let INVOICE = '';
beforeAll(async () => {
  INVOICE = await Fa3InvoiceBuilder.sampleXml(NIP_A, 'FV/FAULT/1');
});

describe('default FA(3) validation before sending', () => {
  it('rejects an invalid document with InvoiceValidationException (issue code + level) and never contacts the send endpoint', async () => {
    await setup();
    const c = client('tok', NIP_A);
    const s = await c.sessions.openOnline();
    const err = await c.sessions
      .sendInvoice(s, '<?xml version="1.0" encoding="UTF-8"?><Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"/>')
      .catch((e: unknown) => e);
    expect(err).toBeInstanceOf(InvoiceValidationException);
    expect(err).toBeInstanceOf(XmlValidationException);
    const v = err as InvoiceValidationException;
    expect(v.issues.some((i) => i.code === 'xsd')).toBe(true);
    expect(v.errors[0]?.code).toBe('xsd');
    expect(v.errors[0]?.level).toMatch(/error|fatal/);
    expect(ps.counters.send).toBe(0);
  });

  it('fatal pre-parse problems carry level fatal and their rule code', async () => {
    await setup();
    const c = client('tok', NIP_A);
    const s = await c.sessions.openOnline();
    const bom = new Uint8Array([0xef, 0xbb, 0xbf, ...Buffer.from(INVOICE)]);
    const err = (await c.sessions.sendInvoice(s, bom).catch((e: unknown) => e)) as InvoiceValidationException;
    expect(err).toBeInstanceOf(InvoiceValidationException);
    expect(err.errors[0]).toMatchObject({ code: 'bom', level: 'fatal' });
    expect(ps.counters.send).toBe(0);
  });

  it('validate:false skips validation, a function replaces it', async () => {
    await setup();
    const c = client('tok', NIP_A);
    const s = await c.sessions.openOnline();
    await c.sessions.sendInvoice(s, '<a/>', { validate: false });
    expect(ps.counters.send).toBe(1);
    let seen = '';
    await c.sessions.sendInvoice(s, '<b/>', { validate: (x) => void (seen = x) });
    expect(seen).toBe('<b/>');
  });

  it('non-FA(3) form codes are not validated by default; validate:true is refused', async () => {
    await setup();
    const c = client('tok', NIP_A);
    const s = await c.sessions.openOnline({ formCode: FormCodes.FA2 });
    await c.sessions.sendInvoice(s, '<a/>');
    await expect(c.sessions.sendInvoice(s, '<a/>', { validate: true })).rejects.toBeInstanceOf(ConfigurationException);
  });
});

describe('InvoiceSessionService / InvoiceSender (real AES + RSA-OAEP, local server)', () => {
  it('open -> send: the server decrypts exactly the invoice that was sent; session key is per session', async () => {
    await setup();
    const c = client('tok', NIP_A);
    const s1 = await c.sessions.openOnline();
    const s2 = await c.sessions.openOnline();
    expect(s1.referenceNumber).not.toBe(s2.referenceNumber);
    const sent = await c.sessions.sendInvoice(s1, INVOICE);
    expect(ps.received).toHaveLength(1);
    expect(ps.received[0]?.xml).toBe(INVOICE);
    expect(sent.invoiceHash).toBe(ps.received[0]?.hash);
    expect(JSON.stringify(s1)).not.toMatch(/iv|key/i);
    await c.sessions.close(s1);
  });

  it('login, open-session and send request bodies conform to the OpenAPI request schemas', async () => {
    await setup();
    const c = client('tok', NIP_A);
    const s = await c.sessions.openOnline();
    await c.sessions.sendInvoice(s, INVOICE);
    const body = (url: string): unknown => JSON.parse(ps.server.hits.find((h) => h.url.endsWith(url))?.body ?? 'null');
    expect(validate(body('/auth/ksef-token'), requestSchema('/auth/ksef-token', 'post'))).toEqual([]);
    expect(validate(body('/sessions/online'), requestSchema('/sessions/online', 'post'))).toEqual([]);
    expect(validate(body('/invoices'), requestSchema('/sessions/online/{referenceNumber}/invoices', 'post'))).toEqual([]);
  });

  it('sendInvoice refuses DOCTYPE before touching the network', async () => {
    await setup();
    const c = client('tok', NIP_A);
    const s = await c.sessions.openOnline();
    await expect(c.sessions.sendInvoice(s, '<!DOCTYPE a><a/>')).rejects.toThrow(/DOCTYPE/);
    expect(ps.counters.send).toBe(0);
  });

  it('validate hook can veto a send', async () => {
    await setup();
    const c = client('tok', NIP_A);
    const s = await c.sessions.openOnline();
    await expect(
      c.sessions.sendInvoice(s, INVOICE, {
        validate: () => {
          throw new Error('xsd says no');
        },
      }),
    ).rejects.toThrow('xsd says no');
    expect(ps.counters.send).toBe(0);
  });

  it('ambiguous send (reset after processing): UnknownOutcomeException, NO resend, reconcile finds it', async () => {
    await setup();
    const c = client('tok', NIP_A);
    const s = await c.sessions.openOnline();
    ps.sendMode = 'process-then-reset';
    const err = await c.sessions.sendInvoice(s, INVOICE).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(UnknownOutcomeException);
    const u = err as UnknownOutcomeException;
    expect(u.sessionReferenceNumber).toBe(s.referenceNumber);
    expect(ps.counters.send).toBe(1); // never auto-retried
    const found = await c.sessions.reconcile(s, u.invoiceHash ?? '');
    expect(found?.invoiceHash).toBe(u.invoiceHash);
    expect(await c.sessions.reconcile(s, 'AAAA')).toBeUndefined();
  });

  it('InvoiceSender.submit reconciles after an ambiguous send and does not send twice', async () => {
    await setup();
    const c = client('tok', NIP_A);
    ps.sendMode = 'process-then-reset';
    const r = await c.sender.submit(INVOICE, { reconcileDelayMs: 10, await: { pollIntervalMs: 10 } });
    expect(r.ksefNumber).toBe('5265877635-20261113-0100AAAAAAAA-AA');
    expect(ps.counters.send).toBe(1);
    expect(ps.received).toHaveLength(1);
  });

  it('InvoiceSender.submit: invoice never reached the server -> UnknownOutcomeException, still no resend', async () => {
    await setup();
    const c = client('tok', NIP_A);
    ps.sendMode = 'reset-without-processing';
    const err = await c.sender.submit(INVOICE, { reconcileAttempts: 2, reconcileDelayMs: 10 }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(UnknownOutcomeException);
    expect(ps.counters.send).toBe(1);
  });

  it('final status >= 400 is InvoiceRejectedException (202 is never "accepted")', async () => {
    await setup();
    const c = client('tok', NIP_A);
    ps.invoiceStatusCode = 440;
    const err = await c.sender.submit(INVOICE, { await: { pollIntervalMs: 10 } }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(InvoiceRejectedException);
    expect((err as InvoiceRejectedException).statusCode).toBe(440);
    expect((err as InvoiceRejectedException).statusDetails).toEqual(['detail']);
  });

  it('awaitInvoice times out while the status stays 150', async () => {
    await setup();
    const c = client('tok', NIP_A);
    const s = await c.sessions.openOnline();
    const sent = await c.sessions.sendInvoice(s, INVOICE);
    ps.invoiceStatusCode = 150;
    await expect(c.sessions.awaitInvoice(s.referenceNumber, sent.referenceNumber, { timeoutMs: 300, pollIntervalMs: 50 })).rejects.toMatchObject({ name: 'TimeoutException' });
  });

  it('21184 on send becomes SessionException', async () => {
    await setup();
    const c = client('tok', NIP_A);
    const s = await c.sessions.openOnline();
    ps.sendMode = 'code-21184';
    const err = await c.sessions.sendInvoice(s, INVOICE).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(SessionException);
    expect((err as SessionException).ksefCodes).toEqual([21184]);
    expect((err as SessionException).cause).toBeInstanceOf(ValidationException);
  });

  it('opens a session after 21470 on the symmetric key (refetch once)', async () => {
    await setup();
    const c = client('tok', NIP_A);
    await c.getAccessToken();
    await c.publicKeys.getKey('SymmetricKeyEncryption'); // cached
    const before = ps.counters.keys;
    await ps.rotateSymmetricKey();
    const s = await c.sessions.openOnline();
    expect(s.referenceNumber).toMatch(/^SESSION-/);
    expect(ps.counters.keys).toBe(before + 1);
    expect(ps.counters.open).toBe(2); // first attempt rejected with 21470, second accepted
  });
});
