import 'reflect-metadata';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { INestApplication } from '@nestjs/common';
import { Fa3InvoiceBuilder, generateTestNip } from '@luroweb/ksef-foundry';
import request from 'supertest';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { createApp } from '../../../examples/nestjs/src/app.js';
import { startAuthServer, type AuthServer } from './support/auth-server.js';
import { json } from './support/local-server.js';

const NIP = generateTestNip();
const SECRET = 'example-app-secret-ksef-token-5d4c';
const here = dirname(fileURLToPath(import.meta.url));
const exampleDir = resolve(here, '../../../examples/nestjs');
const httpServer = (app: INestApplication): Server => app.getHttpServer() as Server;

let auth: AuthServer;
let tmp: string;

beforeAll(async () => {
  auth = await startAuthServer();
  tmp = mkdtempSync(join(tmpdir(), 'ksef-nest-example-'));
});
afterAll(async () => {
  await auth.close();
  rmSync(tmp, { recursive: true, force: true });
});

function setEnv(extra: Record<string, string> = {}): void {
  for (const k of ['KSEF_ENVIRONMENT', 'KSEF_BASE_URL', 'KSEF_CONTEXT_NIP', 'KSEF_TOKEN', 'KSEF_TOKEN_CACHE_FILE', 'KSEF_EXAMPLE_API_TOKEN']) Reflect.deleteProperty(process.env, k);
  Object.assign(process.env, { KSEF_ENVIRONMENT: 'test', KSEF_BASE_URL: auth.server.baseUrl, KSEF_CONTEXT_NIP: NIP, KSEF_TOKEN: SECRET, ...extra });
}

describe('examples/nestjs booted in-process against the local protocol server', () => {
  let app: INestApplication;
  const cacheFile = (): string => join(tmp, 'cache.json');

  beforeAll(async () => {
    setEnv({ KSEF_TOKEN_CACHE_FILE: cacheFile() });
    app = await createApp();
    await app.init();
  });
  afterAll(async () => {
    auth.onAuthenticated = undefined;
    await app.close();
  });

  it('GET /health and GET /limits go through the real SDK + login', async () => {
    const h = await request(httpServer(app)).get('/health');
    expect(h.status).toBe(200);
    expect(h.body).toMatchObject({ ksef: { status: 'up' } });
    const l = await request(httpServer(app)).get('/limits');
    expect(l.status).toBe(200);
    expect((l.body as { context: { onlineSession: { maxInvoices: number } } }).context.onlineSession.maxInvoices).toBe(10000);
  });

  it('custom TokenStorage: JWT pair persisted to a file (no KSeF token inside) and reused by a second app instance', async () => {
    const file = readFileSync(cacheFile(), 'utf8');
    expect(file).toContain('ACCESS-');
    expect(file).not.toContain(SECRET);
    const logins = auth.counters.login;
    const second = await createApp();
    await second.init();
    const r = await request(httpServer(second)).get('/limits');
    expect(r.status).toBe(200);
    expect(auth.counters.login).toBe(logins); // pair came from the file
    await second.close();
  });

  it('POST /invoices/validate runs the real XSD', async () => {
    const xml = await Fa3InvoiceBuilder.sampleXml(NIP, 'FV/EXAMPLE/1');
    const ok = await request(httpServer(app)).post('/invoices/validate').set('Content-Type', 'application/xml').send(xml);
    expect(ok.status).toBe(200);
    expect(ok.body).toMatchObject({ valid: true, issues: [] });
    const bad = await request(httpServer(app)).post('/invoices/validate').set('Content-Type', 'application/xml').send('<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"/>');
    expect(bad.status).toBe(200);
    expect((bad.body as { valid: boolean }).valid).toBe(false);
    const empty = await request(httpServer(app)).post('/invoices/validate');
    expect(empty.status).toBe(400);
  });

  it('POST /invoices/build turns the official JSON model into the byte-exact expected FA(3) XML; bad models give 422', async () => {
    const fx = resolve(exampleDir, '../../fixtures/fa3');
    const model = JSON.parse(readFileSync(join(fx, '01-single-line-23.input.json'), 'utf8')) as unknown;
    const expected = readFileSync(join(fx, '01-single-line-23.expected.xml'), 'utf8');
    const ok = await request(httpServer(app)).post('/invoices/build').send(model as object);
    expect(ok.status).toBe(200);
    expect(ok.headers['content-type']).toContain('application/xml');
    expect(ok.text).toBe(expected);
    const bad = await request(httpServer(app)).post('/invoices/build').send({ ...(model as object), lines: [] });
    expect(bad.status).toBe(422);
    expect(bad.body).toMatchObject({ code: 'KSEF_INVOICE_MODEL_INVALID' });
    expect((await request(httpServer(app)).post('/invoices/build').send({ nonsense: true })).status).toBe(422);
    expect((await request(httpServer(app)).post('/invoices/build').set('Content-Type', 'application/xml').send('<x/>')).status).toBe(400);
  });

  it('POST /invoices accepts the JSON model too: a broken model never reaches KSeF', async () => {
    const before = auth.server.hits.length;
    const r = await request(httpServer(app)).post('/invoices').send({ seller: { nip: '123' } });
    expect(r.status).toBe(422);
    expect(auth.server.hits.length).toBe(before);
    expect((await request(httpServer(app)).post('/invoices')).status).toBe(400);
  });

  it('GET /invoices validates pagination params and forwards them', async () => {
    expect((await request(httpServer(app)).get('/invoices?pageSize=5')).status).toBe(400);
    expect((await request(httpServer(app)).get('/invoices?subjectType=nobody')).status).toBe(400);
    expect((await request(httpServer(app)).get('/invoices?from=2020-01-01T00:00:00Z&to=2026-01-01T00:00:00Z')).status).toBe(400);
    let seen = '';
    auth.onAuthenticated = (path, method, res) => {
      if (method === 'POST' && path === '/invoices/query/metadata') {
        seen = auth.server.hits.at(-1)?.url ?? '';
        json(res, 200, { hasMore: false, isTruncated: false, invoices: [] });
        return true;
      }
      return false;
    };
    const r = await request(httpServer(app)).get('/invoices?subjectType=buyer&pageSize=20&pageOffset=2&sortOrder=Asc');
    expect(r.status).toBe(200);
    expect(r.body).toMatchObject({ hasMore: false, invoices: [] });
    expect(seen).toContain('pageSize=20');
    expect(seen).toContain('pageOffset=2');
    expect(seen).toContain('sortOrder=Asc');
    expect(JSON.parse(auth.server.hits.at(-1)?.body ?? '{}')).toMatchObject({ subjectType: 'Subject2' });
  });

  it('GET /invoices/:ksefNumber returns XML; GET /demo/forbidden shows the 403 mapping', async () => {
    auth.onAuthenticated = (path, _m, res) => {
      if (path.startsWith('/invoices/ksef/')) {
        res.writeHead(200, { 'content-type': 'application/xml' });
        res.end('<Faktura/>');
        return true;
      }
      if (path === '/tokens') {
        json(res, 403, { title: 'Forbidden', status: 403, reasonCode: 'MissingPermission', security: { requiredAnyOfPermissions: ['CredentialsManage'] } }, { 'content-type': 'application/problem+json' });
        return true;
      }
      return false;
    };
    const x = await request(httpServer(app)).get('/invoices/1234567890-20261113-AAAAAAAAAAAA-AA');
    expect(x.status).toBe(200);
    expect(x.headers['content-type']).toContain('application/xml');
    expect(x.text).toBe('<Faktura/>');
    const f = await request(httpServer(app)).get('/demo/forbidden');
    expect(f.status).toBe(403);
    expect(f.body).toMatchObject({ code: 'KSEF_FORBIDDEN', ksef: { category: 'authorization' } });
    expect(f.text).not.toMatch(/MissingPermission|CredentialsManage/); // upstream details are not forwarded by default
    auth.onAuthenticated = undefined;
  });

  it('route params are validated by pipes: malformed KSeF/reference numbers never reach KSeF', async () => {
    auth.server.hits.length = 0;
    const bad = [
      '/invoices/not-a-ksef-number',
      '/invoices/1234567890-20261113-aaaaaaaaaaaa-aa', // lowercase hex
      '/invoices/status/..%2F..%2Fadmin/valid-ref-1',
      '/invoices/status/valid-ref-1/a%2Fb',
      '/upo/ab/invoices/valid-ref-1',
      '/upo/valid-ref-1/ksef/not-a-number',
      '/upo/valid-ref-1/session/%2e%2e',
    ];
    for (const p of bad) {
      const r = await request(httpServer(app)).get(p);
      expect(r.status, p).toBe(400);
    }
    expect(auth.server.hits).toHaveLength(0);
  });

  it('GET /demo/rate-limit is capped at 5 and runs sequentially', async () => {
    expect((await request(httpServer(app)).get('/demo/rate-limit?burst=6')).status).toBe(400);
    expect((await request(httpServer(app)).get('/demo/rate-limit?burst=100')).status).toBe(400);
    const before = auth.server.hits.filter((h) => h.url.includes('/rate-limits')).length;
    const r = await request(httpServer(app)).get('/demo/rate-limit?burst=2');
    expect(r.status, r.text).toBe(200);
    expect(r.body).toEqual({ requested: 2, succeeded: 2 });
    expect(auth.server.hits.filter((h) => h.url.includes('/rate-limits')).length - before).toBe(2);
  });

  it('optional bearer-token guard protects POST /invoices and /demo/* when KSEF_EXAMPLE_API_TOKEN is set', async () => {
    const API_TOKEN = 'example-api-token-for-guard-test';
    setEnv({ KSEF_EXAMPLE_API_TOKEN: API_TOKEN });
    const guarded = await createApp();
    await guarded.init();
    try {
      const before = auth.server.hits.length;
      const noAuth = await request(httpServer(guarded)).post('/invoices').send({ seller: { nip: '123' } });
      const wrong = await request(httpServer(guarded)).post('/invoices').set('Authorization', 'Bearer wrong').send({ seller: { nip: '123' } });
      const basic = await request(httpServer(guarded)).post('/invoices').set('Authorization', `Basic ${API_TOKEN}`).send({ seller: { nip: '123' } });
      const demo = await request(httpServer(guarded)).get('/demo/forbidden');
      for (const r of [noAuth, wrong, basic, demo]) expect(r.status).toBe(401);
      expect(auth.server.hits.length).toBe(before); // rejected before anything left the process
      const ok = await request(httpServer(guarded)).post('/invoices').set('Authorization', `Bearer ${API_TOKEN}`).send({ seller: { nip: '123' } });
      expect(ok.status).toBe(422); // passed the guard, stopped by the builder
      expect((await request(httpServer(guarded)).get('/limits')).status).toBe(200); // read routes stay open
    } finally {
      await guarded.close();
      Reflect.deleteProperty(process.env, 'KSEF_EXAMPLE_API_TOKEN');
    }
  });

  it('refuses to start against anything but the TEST environment', async () => {
    setEnv({ KSEF_ENVIRONMENT: 'production' });
    await expect(createApp()).rejects.toThrow(/only supports KSEF_ENVIRONMENT=test/);
    setEnv({ KSEF_TOKEN_CACHE_FILE: cacheFile() });
  });
});

describe('npm run start path: built example as a real child process', () => {
  it('node dist/main.js serves /health and /limits and shuts down cleanly', async () => {
    const build = spawnSync('npm run build', { cwd: exampleDir, shell: true, encoding: 'utf8' });
    expect(build.status, build.stdout + build.stderr).toBe(0);
    const port = await new Promise<number>((res) => {
      const s = createServer().listen(0, '127.0.0.1', () => {
        const p = (s.address() as AddressInfo).port;
        s.close(() => {
          res(p);
        });
      });
    });
    const child = spawn(process.execPath, ['dist/main.js'], {
      cwd: exampleDir,
      env: { ...process.env, KSEF_ENVIRONMENT: 'test', KSEF_BASE_URL: auth.server.baseUrl, KSEF_CONTEXT_NIP: NIP, KSEF_TOKEN: SECRET, PORT: String(port) },
    });
    let out = '';
    child.stdout.on('data', (d: Buffer) => (out += d.toString()));
    child.stderr.on('data', (d: Buffer) => (out += d.toString()));
    try {
      const end = Date.now() + 20_000;
      while (!out.includes('listening') && Date.now() < end) await new Promise((r) => setTimeout(r, 100));
      expect(out).toContain('listening');
      const health = await fetch(`http://127.0.0.1:${String(port)}/health`);
      expect(await health.json()).toMatchObject({ ksef: { status: 'up' } });
      const limits = await fetch(`http://127.0.0.1:${String(port)}/limits`);
      expect(limits.status).toBe(200);
    } finally {
      child.kill();
    }
    expect(out).not.toContain(SECRET);
    expect(out).not.toMatch(/ACCESS-|REFRESH-/);
  });
});
