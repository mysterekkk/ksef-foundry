import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import {
  AuthorizationException,
  CertificateCredentials,
  Context,
  Environment,
  FormCodes,
  KsefClient,
  KsefTokenCredentials,
  SensitiveString,
  TestCertificateFactory,
  generateTestNip,
  generateTestPesel,
  type GeneratedKsefToken,
} from '../../src/index.js';

/**
 * LIVE TEST scenario (contract section 9). Opt-in: KSEF_LIVE_TEST=1. Talks ONLY to
 * https://api-test.ksef.mf.gov.pl using synthetic, random NIP/PESEL. No secrets needed.
 */
const enabled = process.env['KSEF_LIVE_TEST'] === '1';
if (!enabled) console.log('SKIPPED (live TEST disabled)');

async function eventually<T>(fn: () => Promise<T>, attempts = 8, delayMs = 2000): Promise<T> {
  for (let i = 1; ; i++) {
    try {
      return await fn();
    } catch (e) {
      const noPerm = e instanceof AuthorizationException && e.authStatusCode === 415;
      if (!noPerm || i >= attempts) throw e;
      await new Promise((r) => setTimeout(r, delayMs));
    }
  }
}

describe.skipIf(!enabled)('LIVE TEST api-test.ksef.mf.gov.pl', () => {
  const env = Environment.TEST;
  const nip = generateTestNip();
  const pesel = generateTestPesel();
  let xadesClient: KsefClient;
  let tokenClient: KsefClient;
  let refreshClient: KsefClient;
  let generated: GeneratedKsefToken;

  beforeAll(async () => {
    expect(env.baseUrl).toBe('https://api-test.ksef.mf.gov.pl/v2'); // refuse anything but TEST
    const cert = await TestCertificateFactory.createPersonal({
      serialNumberPrefix: 'PNOPL',
      identifier: pesel,
      givenName: 'Jan',
      surname: 'Testowy',
    });
    xadesClient = KsefClient.create({
      environment: env,
      credentials: new CertificateCredentials({
        certificatePem: cert.certificatePem,
        privateKeyPem: cert.privateKeyPem,
        context: Context.nip(nip),
      }),
    });
  });

  afterAll(async () => {
    await xadesClient.testData.removePerson(nip).catch(() => undefined);
  });

  it('provisions a synthetic taxpayer (POST /testdata/person)', async () => {
    await xadesClient.testData.createPerson({ nip, pesel, description: 'ksef-foundry live test JDG' });
  });

  it('fetches a challenge', async () => {
    const res = await xadesClient.http.request<{ challenge: string; timestampMs: number }>({
      method: 'POST',
      path: '/auth/challenge',
      idempotent: true,
    });
    expect(res.data.challenge).toMatch(/^\d{8}-CR-[A-F0-9]{10}-[A-F0-9]{10}-[A-F0-9]{2}$/);
    expect(typeof res.data.timestampMs).toBe('number');
  });

  it('validates published public keys (publicKeyId = sha256(SPKI))', async () => {
    const a = await xadesClient.publicKeys.getKey('KsefTokenEncryption');
    const b = await xadesClient.publicKeys.getKey('SymmetricKeyEncryption');
    expect(a.publicKeyId).toHaveLength(44);
    expect(b.publicKeyId).not.toBe(a.publicKeyId);
  });

  it('XAdES login with a self-signed certificate (challenge -> sign -> poll -> redeem)', async () => {
    // /testdata/person permissions propagate asynchronously: 415 (no permissions) is retried for a while.
    const token = await eventually(() => xadesClient.getAccessToken());
    expect(token.reveal().split('.')).toHaveLength(3);
    expect(String(token)).toBe('[REDACTED]');
  });

  it('XAdES login with an ECDSA P-256 self-signed certificate (R||S)', async () => {
    const ecNip = generateTestNip();
    const ecPesel = generateTestPesel();
    const cert = await TestCertificateFactory.createPersonal({
      serialNumberPrefix: 'PNOPL',
      identifier: ecPesel,
      keyType: 'ec',
    });
    const ecClient = KsefClient.create({
      environment: env,
      credentials: new CertificateCredentials({
        certificatePem: cert.certificatePem,
        privateKeyPem: cert.privateKeyPem,
        context: Context.nip(ecNip),
      }),
    });
    try {
      await ecClient.testData.createPerson({ nip: ecNip, pesel: ecPesel, description: 'ksef-foundry live test EC JDG' });
      const token = await eventually(() => ecClient.getAccessToken());
      expect(token.reveal().split('.')).toHaveLength(3);
    } finally {
      await ecClient.logout().catch(() => undefined);
      await ecClient.testData.removePerson(ecNip).catch(() => undefined);
    }
  });

  it('reads limits', async () => {
    const ctx = await xadesClient.limits.context();
    expect(ctx.onlineSession.maxInvoices).toBeGreaterThan(0);
    const rl = await xadesClient.limits.rateLimits();
    expect(Object.keys(rl).length).toBeGreaterThan(5);
    await xadesClient.limits.subject();
  });

  it('creates a KSeF token (POST /tokens) and waits until Active', async () => {
    generated = await xadesClient.tokens.generate({
      permissions: ['InvoiceRead', 'InvoiceWrite'],
      description: 'ksef-foundry live test token',
    });
    expect(generated.referenceNumber).toHaveLength(36);
    const st = await xadesClient.tokens.awaitActive(generated.referenceNumber);
    expect(st.status).toBe('Active');
    const listed: string[] = [];
    for await (const t of xadesClient.tokens.iterate({ pageSize: 10 })) listed.push(t.referenceNumber);
    expect(listed).toContain(generated.referenceNumber);
  });

  it('KSeF token login (encrypt token|timestamp RSA-OAEP-SHA256 -> /auth/ksef-token)', async () => {
    tokenClient = KsefClient.create({
      environment: env,
      credentials: new KsefTokenCredentials(new SensitiveString(generated.token), Context.nip(nip)),
    });
    const t = await tokenClient.getAccessToken();
    expect(t.reveal().split('.')).toHaveLength(3);
  });

  it('refreshes the access token with the refresh token', async () => {
    // A huge skew makes every cached access token look "about to expire": the refresh path must be used.
    refreshClient = KsefClient.create({
      environment: env,
      expirySkewSeconds: 3000,
      credentials: new KsefTokenCredentials(new SensitiveString(generated.token), Context.nip(nip)),
    });
    // access tokens live ~15 min; with skew 3000 s the first pair is already stale, so the second call refreshes
    const pair1 = await refreshClient.authentication.getTokenPair(refreshClient.credentials);
    await new Promise((r) => setTimeout(r, 1200)); // JWT iat has 1 s resolution
    const pair2 = await refreshClient.authentication.getTokenPair(refreshClient.credentials);
    expect(pair2.refreshToken).toBe(pair1.refreshToken);
    expect(pair2.accessToken).not.toBe(pair1.accessToken); // refreshed, not re-logged-in
    await refreshClient.limits.context(); // new access token is accepted
  });

  it('queries invoice metadata, sessions, auth sessions', async () => {
    const page = await tokenClient.invoiceQuery.queryMetadata(
      {
        subjectType: 'Subject1',
        dateRange: { dateType: 'PermanentStorage', from: new Date(Date.now() - 30 * 86_400_000), to: new Date() },
      },
      { pageSize: 10, sortOrder: 'Asc' },
    );
    expect(page.invoices).toEqual([]);
    expect(page.hasMore).toBe(false);
    let n = 0;
    for await (const _ of tokenClient.invoiceQuery.iterateMetadata({
      subjectType: 'Subject2',
      dateRange: { dateType: 'Issue', from: new Date(Date.now() - 150 * 86_400_000) },
    })) n++;
    expect(n).toBe(0);
    const sessions = await tokenClient.sessions.listSessions({ sessionType: 'Online', pageSize: 10 });
    expect(Array.isArray(sessions.sessions)).toBe(true);
    const auth = await tokenClient.authSessions.list({ pageSize: 10 });
    expect(auth.items.some((i) => i.isCurrent === true)).toBe(true);
  });

  it('opens an online session (RSA-OAEP of the AES key), reads its status and closes it', async () => {
    const session = await tokenClient.sessions.openOnline({ formCode: FormCodes.FA3 });
    expect(session.referenceNumber).toHaveLength(36);
    expect(JSON.stringify(session)).not.toMatch(/key/i);
    const st = await tokenClient.sessions.getStatus(session.referenceNumber);
    expect(st.status.code).toBe(100);
    await tokenClient.sessions.close(session);
    const closed = await tokenClient.sessions.getStatus(session.referenceNumber);
    expect([100, 170, 200, 440]).toContain(closed.status.code);
  });

  it('revokes the KSeF token and the authentication sessions', async () => {
    await xadesClient.tokens.revoke(generated.referenceNumber);
    await tokenClient.logout();
    await refreshClient.authSessions.revokeCurrent().catch(() => undefined);
    await xadesClient.logout();
  });
});
