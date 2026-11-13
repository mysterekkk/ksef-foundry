import 'reflect-metadata';
import type { Server } from 'node:http';
import { Body, Controller, Get, Inject, Module, Post, type INestApplication } from '@nestjs/common';
import { APP_FILTER } from '@nestjs/core';
import { Test } from '@nestjs/testing';
import {
  Context,
  Environment,
  Fa3InvoiceBuilder,
  InvoiceRejectedException,
  KsefTokenCredentials,
  generateTestNip,
} from '@luroweb/ksef-foundry';
import request from 'supertest';
import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest';
import { KSEF_EXCEPTION_FILTER_OPTIONS, KsefExceptionFilter, KsefModule, KsefService } from '../src/index.js';
import { startAuthServer, type AuthServer } from './support/auth-server.js';
import { CaptureLogger } from './support/capture-logger.js';
import { json } from './support/local-server.js';

const NIP = generateTestNip();
const SECRET_TOKEN = 'filter-secret-ksef-token-9f8e7d';
const LEAK = 'SERVER-INTERNAL-LEAK-MARKER';

let auth: AuthServer;
beforeAll(async () => {
  auth = await startAuthServer();
});
afterAll(async () => {
  await auth.close();
});
afterEach(() => {
  auth.onAuthenticated = undefined;
});

const httpServer = (app: INestApplication): Server => app.getHttpServer() as Server;

@Controller()
class FaultController {
  constructor(@Inject(KsefService) private readonly ksef: KsefService) {}

  @Get('rate-limit')
  rateLimit(): Promise<unknown> {
    return this.ksef.limits.context();
  }
  @Get('forbidden')
  forbidden(): Promise<unknown> {
    return this.ksef.limits.subject();
  }
  @Get('bad-request')
  badRequest(): Promise<unknown> {
    return this.ksef.limits.rateLimits();
  }
  @Get('unauthorized')
  unauthorized(): Promise<unknown> {
    return this.ksef.authSessions.list();
  }
  @Get('upstream-500')
  upstream(): Promise<unknown> {
    return this.ksef.tokens.list();
  }
  @Get('timeout')
  timeout(): Promise<unknown> {
    return this.ksef.downloadInvoice('1234567890-20261113-AAAAAAAAAAAA-AA');
  }
  @Get('reset')
  reset(): Promise<unknown> {
    return this.ksef.getInvoiceStatus('20261113-SO-RESETAAAAA-BBBBBBBBBB-01', '20261113-EE-INVOICE001-BBBBBBBBBB-02');
  }
  @Get('rejected')
  rejected(): never {
    throw new InvoiceRejectedException({ statusCode: 440, description: 'Duplikat faktury', details: ['dup'], invoiceReferenceNumber: 'I1', sessionReferenceNumber: 'S1' });
  }
  @Post('submit')
  async submit(@Body() body: { xml: string }): Promise<unknown> {
    return this.ksef.submitInvoice(body.xml, { reconcileAttempts: 1, reconcileDelayMs: 10 });
  }
}

type Mode = 'global instance' | 'APP_FILTER';

async function boot(mode: Mode, capture: CaptureLogger, expose: boolean): Promise<INestApplication> {
  @Module({
    imports: [
      KsefModule.forRoot({
        environment: Environment.custom(auth.server.baseUrl),
        credentials: new KsefTokenCredentials(SECRET_TOKEN, Context.nip(NIP)),
        retry: { maxAttempts: 1 },
        timeoutMs: 400,
        logger: 'nest',
      }),
    ],
    controllers: [FaultController],
    providers: mode === 'APP_FILTER' ? [{ provide: KSEF_EXCEPTION_FILTER_OPTIONS, useValue: { exposeUpstreamDetails: expose } }, { provide: APP_FILTER, useClass: KsefExceptionFilter }] : [],
  })
  class AppModule {}
  const mod = await Test.createTestingModule({ imports: [AppModule] }).setLogger(capture).compile();
  const app = mod.createNestApplication();
  if (mode === 'global instance') app.useGlobalFilters(new KsefExceptionFilter({ exposeUpstreamDetails: expose }));
  await app.init();
  return app;
}

function problem(res: import('node:http').ServerResponse, status: number, body: Record<string, unknown>, headers: Record<string, string> = {}): true {
  json(res, status, { title: 'problem', status, ...body }, { ...headers, 'content-type': 'application/problem+json' });
  return true;
}

describe.each<[Mode, boolean]>([['global instance', false], ['APP_FILTER', false], ['global instance', true], ['APP_FILTER', true]])('KsefExceptionFilter via real HTTP (fault-injection server) - %s, exposeUpstreamDetails=%s', (mode, expose) => {
  let app: INestApplication;
  const capture = new CaptureLogger();
  const bodies: string[] = [];

  beforeAll(async () => {
    app = await boot(mode, capture, expose);
    auth.onAuthenticated = undefined;
  });
  afterAll(async () => {
    await app.close();
    // secrets must be nowhere: not in any response, not in anything logged by Nest
    const everything = `${bodies.join('\n')}\n${capture.text}`;
    expect(everything).not.toContain(SECRET_TOKEN);
    expect(everything).not.toContain(LEAK);
    expect(everything).not.toMatch(/ACCESS-|REFRESH-|TEMP-/);
    expect(everything).not.toMatch(/Bearer\s+[A-Za-z0-9]/);
    expect(everything).not.toMatch(/Error:\s+at |\n\s+at /); // no stack traces
  });

  async function get(path: string): Promise<request.Response> {
    const r = await request(httpServer(app)).get(path);
    bodies.push(r.text, JSON.stringify(r.headers));
    return r;
  }

  it('429 + Retry-After', async () => {
    auth.onAuthenticated = (path, _m, res) => (path === '/limits/context' ? problem(res, 429, { detail: LEAK }, { 'retry-after': '7' }) : false);
    const r = await get('/rate-limit');
    expect(r.status).toBe(429);
    expect(r.headers['retry-after']).toBe('7');
    expect(r.body).toMatchObject({ statusCode: 429, code: 'KSEF_RATE_LIMITED', ksef: { category: 'rate_limit', retryAfterSeconds: 7 } });
    if (expose) expect(r.body).toMatchObject({ ksef: { retryable: true } });
    else expect(Object.keys((r.body as { ksef: object }).ksef).sort()).toEqual(['category', 'retryAfterSeconds']);
  });

  it('403 problem+json keeps reasonCode and required permissions, nothing else', async () => {
    auth.onAuthenticated = (path, _m, res) =>
      path === '/limits/subject'
        ? problem(res, 403, {
            detail: LEAK,
            reasonCode: 'MissingPermission',
            traceId: 'trace-403',
            security: { requiredAnyOfPermissions: ['SubjectDetailsRead'], token: LEAK },
          })
        : false;
    const r = await get('/forbidden');
    expect(r.status).toBe(403);
    expect(r.body).toMatchObject({ code: 'KSEF_FORBIDDEN', ksef: { category: 'authorization', traceId: 'trace-403' } });
    if (expose) {
      expect(r.body).toMatchObject({ ksef: { reasonCode: 'MissingPermission' } });
      expect(JSON.stringify(r.body)).toContain('SubjectDetailsRead');
    } else {
      expect(Object.keys((r.body as { ksef: object }).ksef).sort()).toEqual(['category', 'traceId']);
      expect(JSON.stringify(r.body)).not.toMatch(/SubjectDetailsRead|MissingPermission/);
    }
  });

  it('400 validation errors are surfaced as KSEF_VALIDATION_FAILED with KSeF codes', async () => {
    auth.onAuthenticated = (path, _m, res) =>
      path === '/rate-limits' ? problem(res, 400, { errors: [{ code: 21405, description: 'Bledne zapytanie', details: ['pole X'] }] }) : false;
    const r = await get('/bad-request');
    expect(r.status).toBe(400);
    expect(r.body).toMatchObject({ code: 'KSEF_VALIDATION_FAILED', ksef: { category: 'validation' } });
    if (expose) expect(r.body).toMatchObject({ ksef: { ksefCode: 21405, ksefCodes: [21405], details: ['pole X'] } });
    else expect(JSON.stringify(r.body)).not.toMatch(/21405|pole X/);
  });

  it('authentication failure upstream -> 502 (not 401)', async () => {
    auth.onAuthenticated = (path, _m, res) => (path === '/auth/sessions' ? problem(res, 401, { detail: LEAK }) : false);
    const r = await get('/unauthorized');
    expect(r.status).toBe(502);
    expect(r.body).toMatchObject({ code: 'KSEF_AUTHENTICATION_FAILED' });
  });

  it('upstream 500 -> 502 without leaking the upstream body', async () => {
    auth.onAuthenticated = (path, _m, res) => (path === '/tokens' ? problem(res, 500, { detail: LEAK, title: LEAK }) : false);
    const r = await get('/upstream-500');
    expect(r.status).toBe(502);
    expect(r.body).toMatchObject({ code: 'KSEF_UPSTREAM_ERROR' });
  });

  it('timeout -> 504, connection reset -> 502', async () => {
    auth.onAuthenticated = (path) => path.startsWith('/invoices/ksef/'); // never answers
    const t = await get('/timeout');
    expect(t.status).toBe(504);
    expect(t.body).toMatchObject({ code: 'KSEF_TIMEOUT' });
    auth.onAuthenticated = (path, _m, res) => {
      if (path.startsWith('/sessions/20261113-SO-RESETAAAAA-BBBBBBBBBB-01')) {
        res.destroy();
        return true;
      }
      return false;
    };
    const n = await get('/reset');
    expect(n.status).toBe(502);
    expect(n.body).toMatchObject({ code: 'KSEF_NETWORK_ERROR' });
  });

  it('invoice rejected -> 422', async () => {
    const r = await get('/rejected');
    expect(r.status).toBe(422);
    expect(r.body).toMatchObject({ code: 'KSEF_INVOICE_REJECTED', ksef: { category: 'invoice_rejected' } });
    if (expose) expect(r.body).toMatchObject({ ksef: { statusCode: 440 } });
    else expect(JSON.stringify(r.body)).not.toMatch(/440|Duplikat|dup/);
  });

  it('invalid FA(3) XML (real XSD) -> 422 with validation errors', async () => {
    // the SDK validates BEFORE login/open session: no session is opened and nothing is sent
    const before = auth.server.hits.length;
    let sends = 0;
    auth.onAuthenticated = (path, method, res) => {
      if (method === 'POST' && path === '/sessions/online') return json(res, 201, { referenceNumber: '20261113-SO-XSDAAAAAAA-BBBBBBBBBB-03', validUntil: new Date(Date.now() + 3_600_000).toISOString() }), true;
      if (method === 'POST' && path.endsWith('/invoices')) sends++;
      if (method === 'POST' && path === '/sessions/online/20261113-SO-XSDAAAAAAA-BBBBBBBBBB-03/close') return res.writeHead(204), res.end(), true;
      return false;
    };
    const r = await request(httpServer(app)).post('/submit').send({ xml: '<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"/>' });
    bodies.push(r.text);
    expect(sends).toBe(0);
    expect(auth.server.hits.length).toBe(before); // not even a login
    expect(r.status).toBe(422);
    expect(r.body).toMatchObject({ code: 'KSEF_XML_INVALID' });
    expect((r.body as { validationErrors: unknown[] }).validationErrors.length).toBeGreaterThan(0);
  });

  it('ambiguous send (connection reset after the POST) -> 409 with reconcile hint, never resent', async () => {
    const xml = await Fa3InvoiceBuilder.sampleXml(NIP, `FV/${String(Date.now())}`);
    let sends = 0;
    auth.onAuthenticated = (path, method, res) => {
      if (method === 'POST' && path === '/sessions/online') return json(res, 201, { referenceNumber: '20261113-SO-AMBAAAAAAA-BBBBBBBBBB-04', validUntil: new Date(Date.now() + 3_600_000).toISOString() }), true;
      if (method === 'POST' && path === '/sessions/online/20261113-SO-AMBAAAAAAA-BBBBBBBBBB-04/invoices') {
        sends++;
        res.destroy();
        return true;
      }
      if (method === 'GET' && path.startsWith('/sessions/20261113-SO-AMBAAAAAAA-BBBBBBBBBB-04/invoices')) return json(res, 200, { invoices: [] }), true;
      if (method === 'POST' && path === '/sessions/online/20261113-SO-AMBAAAAAAA-BBBBBBBBBB-04/close') return res.writeHead(204), res.end(), true;
      return false;
    };
    const r = await request(httpServer(app)).post('/submit').send({ xml });
    bodies.push(r.text);
    expect(r.status).toBe(409);
    expect(r.body).toMatchObject({ code: 'KSEF_UNKNOWN_OUTCOME', reconcile: { sessionReferenceNumber: '20261113-SO-AMBAAAAAAA-BBBBBBBBBB-04' } });
    expect((r.body as { reconcile: { invoiceHash: string } }).reconcile.invoiceHash).toMatch(/^[A-Za-z0-9+/]{43}=$/);
    expect(sends).toBe(1);
  });
});
