import 'reflect-metadata';
import type { Server } from 'node:http';
import type { INestApplication } from '@nestjs/common';
import {
  CertificateCredentials,
  Context,
  Environment,
  Fa3InvoiceBuilder,
  KsefClient,
  TestCertificateFactory,
  generateTestNip,
  generateTestPesel,
} from '@luroweb/ksef-foundry';
import request from 'supertest';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { createApp } from '../../../../examples/nestjs/src/app.js';

/**
 * LIVE TEST scenario (contract section 9). Opt-in: KSEF_LIVE_TEST=1. Talks ONLY to
 * https://api-test.ksef.mf.gov.pl with a synthetic, random NIP/PESEL and a self-signed certificate.
 * The example app is booted unchanged (env vars only) and exercised through its controllers.
 */
const enabled = process.env['KSEF_LIVE_TEST'] === '1';
if (!enabled) console.log('SKIPPED (live TEST disabled)');

/** KSeF TEST maintenance window 16:00-18:00 Europe/Warsaw: no sends then (reported as SKIPPED). */
const warsawHour = Number(new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Warsaw', hour: '2-digit', hourCycle: 'h23' }).format(new Date()));
const maintenance = warsawHour >= 16 && warsawHour < 18;
if (enabled && maintenance) console.log('SKIPPED (KSeF maintenance window 16:00-18:00 Warsaw): send/download/UPO/duplicate steps');

const httpServer = (app: INestApplication): Server => app.getHttpServer() as Server;

/** Permissions of a freshly provisioned taxpayer propagate asynchronously (403 until then). */
async function eventually(fn: () => Promise<request.Response>, attempts = 10, delayMs = 2500, okStatus = 200): Promise<request.Response> {
  for (let i = 1; ; i++) {
    const r = await fn();
    if (r.status === okStatus || i >= attempts) return r;
    await new Promise((res) => setTimeout(res, delayMs));
  }
}

interface Sent {
  ksefNumber: string;
  invoiceReferenceNumber: string;
  sessionReferenceNumber: string;
  invoiceHash: string;
}

describe.skipIf(!enabled)('LIVE TEST: example NestJS app against api-test.ksef.mf.gov.pl', () => {
  const nip = generateTestNip();
  const pesel = generateTestPesel();
  let admin: KsefClient;
  let app: INestApplication;

  beforeAll(async () => {
    expect(Environment.TEST.baseUrl).toBe('https://api-test.ksef.mf.gov.pl/v2'); // refuse anything but TEST
    const cert = await TestCertificateFactory.createPersonal({ serialNumberPrefix: 'PNOPL', identifier: pesel, givenName: 'Jan', surname: 'Testowy' });
    admin = KsefClient.create({
      environment: Environment.TEST,
      credentials: new CertificateCredentials({ certificatePem: cert.certificatePem, privateKeyPem: cert.privateKeyPem, context: Context.nip(nip) }),
    });
    await admin.testData.createPerson({ nip, pesel, description: 'ksef-foundry nestjs live test JDG' });

    for (const k of ['KSEF_BASE_URL', 'KSEF_TOKEN', 'KSEF_TOKEN_CACHE_FILE']) Reflect.deleteProperty(process.env, k);
    Object.assign(process.env, {
      KSEF_ENVIRONMENT: 'test',
      KSEF_CONTEXT_NIP: nip,
      KSEF_CERTIFICATE_PEM: cert.certificatePem,
      KSEF_PRIVATE_KEY_PEM: cert.privateKeyPem.reveal(),
    });
    app = await createApp();
    await app.init();
  });

  afterAll(async () => {
    await app.close();
    await admin.testData.removePerson(nip).catch(() => undefined);
  });

  it('GET /limits authenticates with XAdES (certificate) and returns context + rate limits', async () => {
    const r = await eventually(() => request(httpServer(app)).get('/limits'));
    expect(r.status, r.text).toBe(200);
    const body = r.body as { context: { onlineSession: { maxInvoices: number } }; rate: Record<string, unknown> };
    expect(body.context.onlineSession.maxInvoices).toBeGreaterThan(0);
    expect(Object.keys(body.rate).length).toBeGreaterThan(5);
    expect(r.text).not.toMatch(/PRIVATE KEY|ACCESS|refreshToken/i);
  });

  it('GET /health reports up', async () => {
    const r = await request(httpServer(app)).get('/health');
    expect(r.status).toBe(200);
    expect(r.body).toMatchObject({ ksef: { status: 'up', environment: 'TEST' } });
  });

  it('GET /invoices queries the metadata of the synthetic taxpayer (empty but valid page)', async () => {
    const r = await request(httpServer(app)).get('/invoices?subjectType=seller&pageSize=10&pageOffset=0&sortOrder=Desc');
    expect(r.status, r.text).toBe(200);
    expect(Array.isArray((r.body as { invoices: unknown[] }).invoices)).toBe(true);
  });

  it('POST /invoices/validate accepts a builder-made FA(3) sample (real XSD)', async () => {
    const xml = await Fa3InvoiceBuilder.sampleXml(nip, `FV/LIVE/V/${String(Date.now())}`);
    const r = await request(httpServer(app)).post('/invoices/validate').set('Content-Type', 'application/xml').send(xml);
    expect(r.status).toBe(200);
    expect(r.body).toMatchObject({ valid: true });
  });

  describe.skipIf(maintenance)('send scenario (2 sessions in total, synthetic invoice)', () => {
    const number = `FV/LIVE/${String(Date.now())}`;
    let xml = '';
    let sent: Sent;

    it('POST /invoices sends a Fa3InvoiceBuilder.sample invoice and returns status 200 + KSeF number', async () => {
      xml = await Fa3InvoiceBuilder.sampleXml(nip, number);
      const r = await eventually(() => request(httpServer(app)).post('/invoices').set('Content-Type', 'application/xml').send(xml), 6, 4000, 201);
      expect(r.status, r.text).toBe(201);
      sent = r.body as Sent;
      expect(sent.ksefNumber).toMatch(/^\d{10}-\d{8}-[0-9A-F]{12}-[0-9A-F]{2}$/);
      expect(sent.invoiceReferenceNumber.length).toBeGreaterThan(10);
      expect(sent.sessionReferenceNumber.length).toBeGreaterThan(10);
      expect(r.text).not.toMatch(/PRIVATE KEY|refreshToken/i);
    });

    it('GET /invoices/:ksefNumber immediately afterwards returns the same invoice XML', async () => {
      const r = await request(httpServer(app)).get(`/invoices/${sent.ksefNumber}`);
      expect(r.status, r.text).toBe(200);
      expect(r.headers['content-type']).toContain('application/xml');
      expect(r.text).toContain(number);
      expect(r.text).toBe(xml);
    });

    it('GET /invoices/status/:sessionRef/:invoiceRef reports the accepted invoice', async () => {
      const r = await request(httpServer(app)).get(`/invoices/status/${sent.sessionReferenceNumber}/${sent.invoiceReferenceNumber}`);
      expect(r.status, r.text).toBe(200);
      expect(r.body).toMatchObject({ ksefNumber: sent.ksefNumber, status: { code: 200 } });
    });

    it('GET /upo/... downloads the UPO (by invoice reference and by KSeF number)', async () => {
      const byRef = await eventually(() => request(httpServer(app)).get(`/upo/${sent.sessionReferenceNumber}/invoices/${sent.invoiceReferenceNumber}`), 10, 3000);
      expect(byRef.status, byRef.text).toBe(200);
      expect(byRef.headers['content-type']).toContain('application/xml');
      expect(byRef.text).toContain(sent.ksefNumber);
      const byNumber = await request(httpServer(app)).get(`/upo/${sent.sessionReferenceNumber}/ksef/${sent.ksefNumber}`);
      expect(byNumber.status, byNumber.text).toBe(200);
    });

    it('re-sending the identical invoice is rejected by KSeF (duplicate) and mapped to 422 KSEF_INVOICE_REJECTED', async () => {
      const r = await request(httpServer(app)).post('/invoices').set('Content-Type', 'application/xml').send(xml);
      expect(r.status, r.text).toBe(422);
      expect(r.body).toMatchObject({ code: 'KSEF_INVOICE_REJECTED', ksef: { category: 'invoice_rejected' } });
      expect(r.text).not.toMatch(/440|Duplikat|duplicate/i); // upstream details are not forwarded by default
      expect(r.text).not.toMatch(/PRIVATE KEY|refreshToken|ACCESS/i);
    });
  });
});
