import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { validateXML } from 'xmllint-wasm';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import {
  AuthorizationException,
  CertificateCredentials,
  Context,
  Crypto,
  Environment,
  Fa3InvoiceBuilder,
  InvoiceRejectedException,
  KsefClient,
  KsefTokenCredentials,
  SensitiveString,
  TestCertificateFactory,
  generateTestNip,
  generateTestPesel,
  type SubmissionResult,
} from '../../src/index.js';

/**
 * LIVE TEST invoice lifecycle. Opt-in (KSEF_LIVE_TEST=1), TEST environment only, synthetic taxpayer.
 * Volume is kept low on purpose: 2 accepted invoices + 1 duplicate attempt. Not run during the
 * 16:00-18:00 Europe/Warsaw maintenance window.
 */
const enabled = process.env['KSEF_LIVE_TEST'] === '1';

function warsawHour(): number {
  return Number(new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Warsaw', hour: '2-digit', hour12: false }).format(new Date()));
}
const maintenance = warsawHour() >= 16 && warsawHour() < 18;
if (enabled && maintenance) console.log('SKIPPED (KSeF TEST maintenance window 16:00-18:00 Europe/Warsaw)');

const KSEF_NUMBER = /^([1-9](\d[1-9]|[1-9]\d)\d{7})-(20[2-9][0-9]|2[1-9]\d{2}|[3-9]\d{3})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])-([0-9A-F]{6})-?([0-9A-F]{6})-([0-9A-F]{2})$/;
const UPO_XSD = readFileSync(fileURLToPath(new URL('../../../../schemas/xsd/upo/upo-v4-3.xsd', import.meta.url)));

/**
 * Known doc-vs-reality gaps of the TEST UPO against upo-v4-3.xsd (see docs/dev/findings-ts.md):
 * 1. NazwaPodmiotuPrzyjmujacego is "Ministerstwo Finansów - środowisko testowe (TE)" but the XSD fixes "Ministerstwo Finansów".
 * 2. The UPO carries a ds:Signature (XAdES by MF, as documented) that the XSD does not declare.
 * Everything else must validate.
 */
const KNOWN_UPO_GAPS = [/NazwaPodmiotuPrzyjmujacego.*fixed value constraint/, /xmldsig#}Signature.*not expected/];

async function validateUpo(xml: string): Promise<{ unexpected: string[]; known: number; signed: boolean }> {
  const r = await validateXML({
    xml: [{ fileName: 'upo.xml', contents: xml }],
    schema: { fileName: 'upo-v4-3.xsd', contents: UPO_XSD },
    modifyArguments: (a) => ['--nonet', ...a],
  });
  const messages = r.errors.map((e) => e.message);
  const unexpected = messages.filter((m) => !KNOWN_UPO_GAPS.some((re) => re.test(m)));
  return { unexpected, known: messages.length - unexpected.length, signed: /<(w+:)?Signature[ >]/.test(xml) };
}

async function eventually<T>(fn: () => Promise<T>, attempts = 10, delayMs = 2000): Promise<T> {
  for (let i = 1; ; i++) {
    try {
      return await fn();
    } catch (e) {
      const retry = e instanceof AuthorizationException && e.authStatusCode === 415;
      if (!retry || i >= attempts) throw e;
      await new Promise((r) => setTimeout(r, delayMs));
    }
  }
}
const sleep = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));

describe.skipIf(!enabled || maintenance)('LIVE TEST invoice lifecycle (api-test.ksef.mf.gov.pl)', () => {
  const nip = generateTestNip();
  const pesel = generateTestPesel();
  const run = `${Date.now().toString(36)}${Math.floor(Math.random() * 1e4).toString(36)}`;
  const numberA = `FV/LIVE/${run}/1`;
  const numberB = `FV/LIVE/${run}/2`;
  let xadesClient: KsefClient;
  let tokenClient: KsefClient;
  let xmlA = '';
  let resultA: SubmissionResult;
  let resultB: SubmissionResult;

  beforeAll(async () => {
    expect(Environment.TEST.baseUrl).toBe('https://api-test.ksef.mf.gov.pl/v2');
    const cert = await TestCertificateFactory.createPersonal({ serialNumberPrefix: 'PNOPL', identifier: pesel, givenName: 'Jan', surname: 'Testowy' });
    xadesClient = KsefClient.create({
      environment: Environment.TEST,
      credentials: new CertificateCredentials({ certificatePem: cert.certificatePem, privateKeyPem: cert.privateKeyPem, context: Context.nip(nip) }),
    });
    await xadesClient.testData.createPerson({ nip, pesel, description: 'ksef-foundry live lifecycle JDG' });
    await eventually(() => xadesClient.getAccessToken());
    const gen = await xadesClient.tokens.generate({ permissions: ['InvoiceRead', 'InvoiceWrite'], description: 'ksef-foundry lifecycle token' });
    await xadesClient.tokens.awaitActive(gen.referenceNumber);
    tokenClient = KsefClient.create({
      environment: Environment.TEST,
      credentials: new KsefTokenCredentials(new SensitiveString(gen.token), Context.nip(nip)),
    });
    xmlA = await Fa3InvoiceBuilder.sampleXml(nip, numberA);
  });

  afterAll(async () => {
    await tokenClient.logout().catch(() => undefined);
    await xadesClient.logout().catch(() => undefined);
    await xadesClient.testData.removePerson(nip).catch(() => undefined);
  });

  it('XAdES login: submit() sends the sample invoice and waits for status 200', async () => {
    resultA = await xadesClient.sender.submit(xmlA, { await: { timeoutMs: 150_000 } });
    expect(resultA.ksefNumber).toMatch(KSEF_NUMBER);
    expect(resultA.ksefNumber.startsWith(`${nip}-`)).toBe(true);
    expect(resultA.invoice.status.code).toBe(200);
    expect(resultA.invoiceHash).toBe(Crypto.sha256Base64(Buffer.from(xmlA, 'utf8')));
    expect(resultA.invoice.invoiceHash).toBe(resultA.invoiceHash);
    // Download IMMEDIATELY after acceptance (no sleep): KSeF may still answer 21165 "not yet available", which the SDK retries.
    const t0 = Date.now();
    const inv = await xadesClient.invoices.getByKsefNumber(resultA.ksefNumber);
    console.log(`immediate download after status 200 took ${String(Date.now() - t0)} ms`);
    expect(inv.hashMatches).toBe(true);
    expect(inv.computedHash).toBe(resultA.invoiceHash);
  });

  it('token login: submit() a second invoice (different number)', async () => {
    const xmlB = await Fa3InvoiceBuilder.sampleXml(nip, numberB);
    resultB = await tokenClient.sender.submit(xmlB, { await: { timeoutMs: 150_000 } });
    expect(resultB.ksefNumber).toMatch(KSEF_NUMBER);
    expect(resultB.ksefNumber).not.toBe(resultA.ksefNumber);
    const t0 = Date.now();
    const inv = await tokenClient.invoices.getByKsefNumber(resultB.ksefNumber); // immediately, no sleep
    console.log(`immediate download (token login) took ${String(Date.now() - t0)} ms`);
    expect(inv.computedHash).toBe(resultB.invoiceHash);
  });

  it('GET /invoices/ksef/{n}: downloaded invoice hash equals the sent invoiceHash', async () => {
    const inv = await tokenClient.invoices.getByKsefNumber(resultA.ksefNumber);
    expect(inv.computedHash).toBe(resultA.invoiceHash);
    expect(inv.hashHeader).toBe(resultA.invoiceHash);
    expect(inv.hashMatches).toBe(true);
    expect(inv.xml).toContain(numberA);
  });

  it('invoice UPO (by reference and by KSeF number) validates against upo-v4-3.xsd (modulo 2 known TEST gaps)', async () => {
    const byRef = await xadesClient.upo.getInvoiceUpo(resultA.sessionReferenceNumber, resultA.invoiceReferenceNumber);
    const byNum = await xadesClient.upo.getInvoiceUpoByKsefNumber(resultA.sessionReferenceNumber, resultA.ksefNumber);
    for (const upo of [byRef, byNum]) {
      expect(upo).toContain(resultA.ksefNumber);
      const v = await validateUpo(upo);
      expect(v.unexpected).toEqual([]);
      expect(v.signed).toBe(true);
    }
  });

  it('session UPO (after close) validates against upo-v4-3.xsd', async () => {
    let pages: readonly { referenceNumber: string }[] = [];
    const deadline = Date.now() + 120_000;
    while (Date.now() < deadline) {
      const st = await xadesClient.sessions.getStatus(resultA.sessionReferenceNumber);
      pages = st.upo?.pages ?? [];
      if (pages.length > 0) {
        expect(st.invoiceCount).toBe(1);
        expect(st.successfulInvoiceCount).toBe(1);
        expect(st.failedInvoiceCount ?? 0).toBe(0); // the field is omitted when zero
        break;
      }
      await sleep(2000);
    }
    expect(pages.length).toBeGreaterThan(0);
    const upo = await xadesClient.upo.getSessionUpo(resultA.sessionReferenceNumber, pages[0]?.referenceNumber ?? '');
    expect(upo).toContain(resultA.ksefNumber);
    const v = await validateUpo(upo);
    expect(v.unexpected).toEqual([]);
    expect(v.signed).toBe(true);
  });

  it('metadata query finds the accepted invoice (may need indexing time)', async () => {
    const find = async (): Promise<string | undefined> => {
      for await (const m of tokenClient.invoiceQuery.iterateMetadata({
        subjectType: 'Subject1',
        dateRange: { dateType: 'Issue', from: new Date(Date.now() - 2 * 86_400_000), to: new Date(Date.now() + 86_400_000) },
      })) {
        if (m.ksefNumber === resultA.ksefNumber) {
          expect(m.invoiceNumber).toBe(numberA);
          expect(m.seller.nip).toBe(nip);
          expect(m.invoiceHash).toBe(resultA.invoiceHash);
          expect(m.formCode.value).toBe('FA');
          return m.ksefNumber;
        }
      }
      return undefined;
    };
    let found: string | undefined;
    for (let i = 0; i < 12 && found === undefined; i++) {
      found = await find();
      if (found === undefined) await sleep(5000);
    }
    expect(found).toBe(resultA.ksefNumber);
  });

  it('resending the SAME invoice number in a new session is rejected as duplicate (440)', async () => {
    const err = await xadesClient.sender.submit(xmlA, { await: { timeoutMs: 150_000 } }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(InvoiceRejectedException);
    const r = err as InvoiceRejectedException;
    expect(r.statusCode).toBe(440);
    expect(r.sessionReferenceNumber).not.toBe(resultA.sessionReferenceNumber);
    console.log('duplicate rejection: ', JSON.stringify({ code: r.statusCode, description: r.statusDescription, extensionKeys: Object.keys(r.statusExtensions) }));
    expect(r.statusExtensions['originalKsefNumber']).toBe(resultA.ksefNumber);
  });
});
