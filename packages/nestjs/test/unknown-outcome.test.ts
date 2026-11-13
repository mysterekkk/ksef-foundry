import 'reflect-metadata';
import type { Server } from 'node:http';
import { Body, Controller, Inject, Module, Post, type INestApplication } from '@nestjs/common';
import { APP_FILTER } from '@nestjs/core';
import { Test } from '@nestjs/testing';
import { Context, Environment, Fa3InvoiceBuilder, KsefTokenCredentials, generateTestNip } from '@luroweb/ksef-foundry';
import request from 'supertest';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { KsefExceptionFilter, KsefModule, KsefService } from '../src/index.js';
import { startAuthServer, type AuthServer } from './support/auth-server.js';
import { json } from './support/local-server.js';

/**
 * The core throws UnknownOutcomeException for ambiguous sends: dropped connection, malformed 2xx answers
 * and aborts while the request is in flight. Every one of them must reach the API client as 409 with a
 * reconcile hint (never a 5xx that invites a blind resend). REAL local server, real HTTP, real crypto.
 */
const NIP = generateTestNip();
const httpServer = (app: INestApplication): Server => app.getHttpServer() as Server;

let auth: AuthServer;
let app: INestApplication;

@Controller()
class SubmitController {
  constructor(@Inject(KsefService) private readonly ksef: KsefService) {}

  @Post('submit')
  submit(@Body() body: { xml: string }): Promise<unknown> {
    return this.ksef.submitInvoice(body.xml, { reconcileAttempts: 1, reconcileDelayMs: 10 });
  }

  /** Caller aborts 300 ms after the invoice POST reached the server. */
  @Post('submit-abort')
  async submitAbort(@Body() body: { xml: string }): Promise<unknown> {
    const ctl = new AbortController();
    const watcher = setInterval(() => {
      if (auth.server.hits.some((h) => h.method === 'POST' && h.url.endsWith('/invoices'))) {
        clearInterval(watcher);
        setTimeout(() => {
          ctl.abort();
        }, 300);
      }
    }, 20);
    try {
      return await this.ksef.submitInvoice(body.xml, { signal: ctl.signal, reconcileAttempts: 1, reconcileDelayMs: 10 });
    } finally {
      clearInterval(watcher);
    }
  }
}

beforeAll(async () => {
  auth = await startAuthServer();
  @Module({
    imports: [
      KsefModule.forRoot({
        environment: Environment.custom(auth.server.baseUrl),
        credentials: new KsefTokenCredentials('unknown-outcome-secret-token', Context.nip(NIP)),
        retry: { maxAttempts: 1 },
        timeoutMs: 5000,
      }),
    ],
    controllers: [SubmitController],
    providers: [{ provide: APP_FILTER, useClass: KsefExceptionFilter }],
  })
  class AppModule {}
  app = (await Test.createTestingModule({ imports: [AppModule] }).compile()).createNestApplication();
  await app.init();
});
afterAll(async () => {
  await app.close();
  await auth.close();
});

function sessionHooks(ref: string, onSend: (res: import('node:http').ServerResponse) => void): void {
  auth.server.hits.length = 0;
  auth.onAuthenticated = (path, method, res) => {
    if (method === 'POST' && path === '/sessions/online') return json(res, 201, { referenceNumber: ref, validUntil: new Date(Date.now() + 3_600_000).toISOString() }), true;
    if (method === 'POST' && path === `/sessions/online/${ref}/invoices`) return onSend(res), true;
    if (method === 'GET' && path.startsWith(`/sessions/${ref}/invoices`)) return json(res, 200, { invoices: [] }), true;
    if (method === 'POST' && path === `/sessions/online/${ref}/close`) return res.writeHead(204), res.end(), true;
    return false;
  };
}

describe('UnknownOutcomeException from the core -> 409 KSEF_UNKNOWN_OUTCOME (fault-injection)', () => {
  it('malformed 2xx answer to the invoice POST', async () => {
    sessionHooks('20261113-SO-MALAAAAAAA-BBBBBBBBBB-05', (res) => {
      res.writeHead(202, { 'content-type': 'application/json' });
      res.end('<html>this is not json</html>');
    });
    const xml = await Fa3InvoiceBuilder.sampleXml(NIP, `FV/MAL/${String(Date.now())}`);
    const r = await request(httpServer(app)).post('/submit').send({ xml });
    expect(r.status, r.text).toBe(409);
    expect(r.body).toMatchObject({ code: 'KSEF_UNKNOWN_OUTCOME', reconcile: { sessionReferenceNumber: '20261113-SO-MALAAAAAAA-BBBBBBBBBB-05' } });
    expect((r.body as { reconcile: { invoiceHash: string } }).reconcile.invoiceHash).toMatch(/^[A-Za-z0-9+/]{43}=$/);
    expect(r.text).not.toContain('not json');
  });

  it('malformed 2xx answer to the session open', async () => {
    auth.server.hits.length = 0;
    auth.onAuthenticated = (path, method, res) => {
      if (method === 'POST' && path === '/sessions/online') {
        res.writeHead(201, { 'content-type': 'application/json' });
        res.end('{"referenceNumber": ');
        return true;
      }
      return false;
    };
    const xml = await Fa3InvoiceBuilder.sampleXml(NIP, `FV/MAL2/${String(Date.now())}`);
    const r = await request(httpServer(app)).post('/submit').send({ xml });
    expect(r.status, r.text).toBe(409);
    expect(r.body).toMatchObject({ code: 'KSEF_UNKNOWN_OUTCOME' });
  });

  it('caller abort while the invoice POST is in flight', async () => {
    sessionHooks('20261113-SO-ABTAAAAAAA-BBBBBBBBBB-06', () => {
      /* never answers: the request stays in flight until the caller aborts */
    });
    const xml = await Fa3InvoiceBuilder.sampleXml(NIP, `FV/ABT/${String(Date.now())}`);
    const r = await request(httpServer(app)).post('/submit-abort').send({ xml });
    expect(r.status, r.text).toBe(409);
    expect(r.body).toMatchObject({ code: 'KSEF_UNKNOWN_OUTCOME', reconcile: { sessionReferenceNumber: '20261113-SO-ABTAAAAAAA-BBBBBBBBBB-06' } });
    expect(auth.server.hits.filter((h) => h.method === 'POST' && h.url.endsWith('/invoices'))).toHaveLength(1); // never resent
    auth.onAuthenticated = undefined;
  });
});
