import 'reflect-metadata';
import { Test } from '@nestjs/testing';
import {
  AbortException,
  Context,
  Environment,
  KsefTokenCredentials,
  generateTestNip,
} from '@luroweb/ksef-foundry';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { KsefHealthIndicator, KsefModule, KsefService, type KsefModuleOptions } from '../src/index.js';
import { startAuthServer, type AuthServer } from './support/auth-server.js';
import { json, startServer } from './support/local-server.js';

const NIP = generateTestNip();
let auth: AuthServer;

beforeAll(async () => {
  auth = await startAuthServer();
});
afterAll(async () => {
  await auth.close();
});

function opts(extra: Partial<KsefModuleOptions> = {}): KsefModuleOptions {
  return {
    environment: Environment.custom(auth.server.baseUrl),
    credentials: new KsefTokenCredentials('lifecycle-secret-token', Context.nip(NIP)),
    ...extra,
  };
}

/** Makes `/invoices/ksef/*` and `/limits/subject` hang forever (a real in-flight request). */
function hangOn(...fragments: string[]): void {
  auth.onAuthenticated = (path) => fragments.some((f) => path.includes(f));
}
const hits = (fragment: string): number => auth.server.hits.filter((h) => h.url.includes(fragment)).length;
async function until(cond: () => boolean, ms = 5000): Promise<void> {
  const end = Date.now() + ms;
  while (!cond()) {
    if (Date.now() > end) throw new Error('condition not reached');
    await new Promise((r) => setTimeout(r, 20));
  }
}

describe('shutdown', () => {
  it('aborts an in-flight KsefService call (AbortException, no retry) when the module is destroyed', async () => {
    hangOn('/invoices/ksef/');
    const mod = await Test.createTestingModule({ imports: [KsefModule.forRoot(opts())] }).compile();
    const service = mod.get(KsefService);
    const pending = service.downloadInvoice('1234567890-20261113-AAAAAAAAAAAA-AA');
    const outcome = pending.then(() => undefined, (e: unknown) => e);
    await until(() => hits('/invoices/ksef/') === 1);
    const started = Date.now();
    await mod.close();
    const err = await outcome;
    expect(err).toBeInstanceOf(AbortException);
    expect(Date.now() - started).toBeLessThan(2000);
    expect(hits('/invoices/ksef/')).toBe(1); // not retried
    expect(service.shutdownSignal.aborted).toBe(true);
    auth.onAuthenticated = undefined;
  });

  it('aborts a raw client call too (AbortException) and nothing reaches the server afterwards', async () => {
    hangOn('/limits/subject');
    const mod = await Test.createTestingModule({
      imports: [KsefModule.forRoot(opts({ retry: { maxAttempts: 3, baseDelayMs: 20, maxDelayMs: 40 } }))],
    }).compile();
    const client = mod.get(KsefService).client;
    const outcome = client.limits.subject().then(() => undefined, (e: unknown) => e);
    await until(() => hits('/limits/subject') === 1);
    await mod.close();
    const err = await outcome;
    expect(err).toBeInstanceOf(AbortException); // client-level signal: never retried
    expect(hits('/limits/subject')).toBe(1);
    // a call after shutdown fails immediately and sends nothing
    await expect(client.limits.subject()).rejects.toBeInstanceOf(AbortException);
    expect(hits('/limits/subject')).toBe(1);
    auth.onAuthenticated = undefined;
  });

  it('does not log out by default; logs out (DELETE /auth/sessions/current) only with logoutOnShutdown', async () => {
    auth.onAuthenticated = (path, method, res) => {
      if (method === 'DELETE' && path === '/auth/sessions/current') {
        res.writeHead(204);
        res.end();
        return true;
      }
      return false;
    };
    const plain = await Test.createTestingModule({ imports: [KsefModule.forRoot(opts())] }).compile();
    await plain.get(KsefService).limits.context();
    await plain.close();
    expect(hits('/auth/sessions/current')).toBe(0);

    const withLogout = await Test.createTestingModule({ imports: [KsefModule.forRoot(opts({ logoutOnShutdown: true }))] }).compile();
    await withLogout.get(KsefService).limits.context();
    await withLogout.close();
    expect(hits('/auth/sessions/current')).toBe(1);
    auth.onAuthenticated = undefined;
  });

  it('close() is idempotent', async () => {
    const mod = await Test.createTestingModule({ imports: [KsefModule.forRoot(opts())] }).compile();
    await mod.close();
    await mod.close();
  });
});

describe('KsefHealthIndicator', () => {
  it('reports up against a reachable server (public-key mode) and down + safe message otherwise', async () => {
    const mod = await Test.createTestingModule({ imports: [KsefModule.forRoot(opts())] }).compile();
    const health = mod.get(KsefHealthIndicator);
    const up = await health.check();
    expect(up['ksef']?.status).toBe('up');
    await mod.close();

    const dead = await startServer((_req, res) => json(res, 500, { title: 'boom', detail: 'internal secret detail' }));
    const deadMod = await Test.createTestingModule({
      imports: [KsefModule.forRoot({ ...opts({ retry: { maxAttempts: 1 } }), environment: Environment.custom(dead.baseUrl) })],
    }).compile();
    const down = await deadMod.get(KsefHealthIndicator).check('ksef-api');
    expect(down['ksef-api']?.status).toBe('down');
    expect(JSON.stringify(down)).not.toContain('secret');
    await deadMod.close();
    await dead.close();
  });

  it('rate-limits mode proves credentials (authenticated call)', async () => {
    auth.onAuthenticated = (path, _m, res) => {
      if (path === '/rate-limits') {
        json(res, 200, {});
        return true;
      }
      return false;
    };
    const mod = await Test.createTestingModule({ imports: [KsefModule.forRoot(opts())] }).compile();
    const indicator = new KsefHealthIndicator(mod.get(KsefService), { mode: 'rate-limits' });
    expect((await indicator.check())['ksef']?.status).toBe('up');
    await mod.close();
    auth.onAuthenticated = undefined;
  });
});
