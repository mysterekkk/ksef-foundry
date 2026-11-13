import 'reflect-metadata';
import type { Server } from 'node:http';
import { Controller, Get, Inject, Injectable, Module, type INestApplication } from '@nestjs/common';
import { ConfigModule, ConfigService } from '@nestjs/config';
import { Test } from '@nestjs/testing';
import {
  Context,
  Environment,
  InMemoryTokenStorage,
  KsefClient,
  KsefTokenCredentials,
  generateTestNip,
  type TokenPair,
  type TokenStorage,
} from '@luroweb/ksef-foundry';
import request from 'supertest';
import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest';
import {
  InjectKsef,
  InjectKsefService,
  KSEF_CLIENT,
  KsefClientRegistry,
  KsefModule,
  KsefService,
  getKsefClientToken,
  type KsefModuleOptions,
} from '../src/index.js';
import { startAuthServer, type AuthServer } from './support/auth-server.js';
import { CaptureLogger } from './support/capture-logger.js';

const httpServer = (app: INestApplication): Server => app.getHttpServer() as Server;
const NIP_A = generateTestNip();
const NIP_B = generateTestNip();
const TOKEN_A = 'secret-ksef-token-AAAA-1111';
const TOKEN_B = 'secret-ksef-token-BBBB-2222';

let auth: AuthServer;
const apps: INestApplication[] = [];

beforeAll(async () => {
  auth = await startAuthServer();
});
afterAll(async () => {
  await auth.close();
});
afterEach(async () => {
  while (apps.length > 0) await apps.pop()?.close();
});

function opts(token: string, nip: string, extra: Partial<KsefModuleOptions> = {}): KsefModuleOptions {
  return {
    environment: Environment.custom(auth.server.baseUrl),
    credentials: new KsefTokenCredentials(token, Context.nip(nip)),
    ...extra,
  };
}

/** Records every storage call; stores like the default in-memory storage. */
class RecordingStorage implements TokenStorage {
  readonly inner = new InMemoryTokenStorage();
  readonly calls: string[] = [];
  readonly puts: { key: string; pair: TokenPair }[] = [];
  get(key: string): Promise<TokenPair | undefined> {
    this.calls.push(`get:${key}`);
    return this.inner.get(key);
  }
  put(key: string, pair: TokenPair, ttl: number): Promise<void> {
    this.calls.push(`put:${key}`);
    this.puts.push({ key, pair });
    return this.inner.put(key, pair, ttl);
  }
  delete(key: string): Promise<void> {
    this.calls.push(`delete:${key}`);
    return this.inner.delete(key);
  }
}

describe('KsefModule wiring', () => {
  it('forRoot provides the client, the service and the registry; default environment is TEST', async () => {
    const mod = await Test.createTestingModule({
      imports: [KsefModule.forRoot({ credentials: new KsefTokenCredentials(TOKEN_A, Context.nip(NIP_A)) })],
    }).compile();
    const client = mod.get<KsefClient>(KSEF_CLIENT);
    const service = mod.get(KsefService);
    expect(client).toBeInstanceOf(KsefClient);
    expect(client.environment).toBe(Environment.TEST);
    expect(service.client).toBe(client);
    expect(mod.get(KsefClientRegistry).get('default')).toBe(client);
    await mod.close();
  });

  it('PRODUCTION only when explicitly selected', async () => {
    const mod = await Test.createTestingModule({
      imports: [KsefModule.forRoot({ environment: Environment.PRODUCTION, credentials: new KsefTokenCredentials(TOKEN_A, Context.nip(NIP_A)) })],
    }).compile();
    expect(mod.get<KsefClient>(KSEF_CLIENT).environment).toBe(Environment.PRODUCTION);
    await mod.close();
  });

  it('forRootAsync with a real ConfigService (useFactory)', async () => {
    const mod = await Test.createTestingModule({
      imports: [
        ConfigModule.forRoot({
          ignoreEnvFile: true,
          isGlobal: true,
          load: [() => ({ KSEF_BASE_URL: auth.server.baseUrl, KSEF_CONTEXT_NIP: NIP_A, KSEF_TOKEN: TOKEN_A })],
        }),
        KsefModule.forRootAsync({
          imports: [ConfigModule],
          inject: [ConfigService],
          useFactory: (config: ConfigService): KsefModuleOptions => ({
            environment: Environment.custom(config.getOrThrow<string>('KSEF_BASE_URL')),
            credentials: new KsefTokenCredentials(config.getOrThrow<string>('KSEF_TOKEN'), Context.nip(config.getOrThrow<string>('KSEF_CONTEXT_NIP'))),
          }),
        }),
      ],
    }).compile();
    const service = mod.get(KsefService);
    const limits = await service.limits.context();
    expect(limits.onlineSession.maxInvoices).toBe(10000);
    expect(auth.loginContexts).toContain(`Nip:${NIP_A}`);
    await mod.close();
  });

  it('forRootAsync with useClass and useExisting', async () => {
    @Injectable()
    class OptionsFactory {
      createKsefOptions(): KsefModuleOptions {
        return opts(TOKEN_A, NIP_A);
      }
    }
    const viaClass = await Test.createTestingModule({
      imports: [KsefModule.forRootAsync({ useClass: OptionsFactory })],
    }).compile();
    expect(viaClass.get(KsefService).client.environment.name).toBe('CUSTOM');
    await viaClass.close();

    @Module({ providers: [OptionsFactory], exports: [OptionsFactory] })
    class FactoryModule {}
    const viaExisting = await Test.createTestingModule({
      imports: [KsefModule.forRootAsync({ imports: [FactoryModule], useExisting: OptionsFactory })],
    }).compile();
    expect((await viaExisting.get(KsefService).limits.context()).onlineSession.maxInvoices).toBe(10000);
    await viaExisting.close();
  });

  it('isGlobal makes the providers visible in unrelated modules', async () => {
    @Injectable()
    class Consumer {
      constructor(@InjectKsef() readonly client: KsefClient) {}
    }
    @Module({ providers: [Consumer] })
    class FeatureModule {}
    const mod = await Test.createTestingModule({
      imports: [KsefModule.forRoot({ ...opts(TOKEN_A, NIP_A), isGlobal: true }), FeatureModule],
    }).compile();
    expect(mod.get(Consumer).client).toBe(mod.get<KsefClient>(KSEF_CLIENT));
    await mod.close();
  });
});

describe('singleton clients', () => {
  it('serves every HTTP request and every injection with the very same KsefClient and one login', async () => {
    const seen: KsefClient[] = [];
    @Controller('probe')
    class ProbeController {
      constructor(
        @InjectKsef() private readonly client: KsefClient,
        @Inject(KsefService) private readonly service: KsefService,
      ) {}
      @Get()
      async probe(): Promise<{ ok: boolean; sameInstance: boolean }> {
        seen.push(this.client);
        const limits = await this.service.limits.context();
        return { ok: limits.onlineSession.maxInvoices > 0, sameInstance: this.client === this.service.client };
      }
    }
    @Module({ imports: [KsefModule.forRoot(opts(TOKEN_A, NIP_A))], controllers: [ProbeController] })
    class AppModule {}
    const mod = await Test.createTestingModule({ imports: [AppModule] }).compile();
    const app = mod.createNestApplication();
    apps.push(app);
    await app.init();
    const before = auth.tokenLogins.get(TOKEN_A) ?? 0;
    const results = await Promise.all([1, 2, 3, 4, 5].map(() => request(httpServer(app)).get('/probe')));
    for (const r of results) expect(r.body).toEqual({ ok: true, sameInstance: true });
    expect(new Set(seen).size).toBe(1);
    expect(seen[0]).toBe(mod.get<KsefClient>(KSEF_CLIENT));
    expect((auth.tokenLogins.get(TOKEN_A) ?? 0) - before).toBe(1); // single-flight login shared by all requests
  });
});

describe('multi-tenant (named clients)', () => {
  it('keeps clients, token cache entries and logins isolated per name', async () => {
    const storage = new RecordingStorage();
    const mod = await Test.createTestingModule({
      imports: [
        KsefModule.forRoot({ ...opts(TOKEN_A, NIP_A, { storage }), name: 'tenant-a' }),
        KsefModule.forRoot({ ...opts(TOKEN_B, NIP_B, { storage }), name: 'tenant-b' }),
      ],
    }).compile();
    const registry = mod.get(KsefClientRegistry);
    expect(registry.names().sort()).toEqual(['tenant-a', 'tenant-b']);
    const a = registry.get('tenant-a');
    const b = registry.get('tenant-b');
    expect(a).not.toBe(b);
    expect(mod.get(getKsefClientToken('tenant-a'), { strict: false })).toBe(a);

    const [la, lb] = await Promise.all([a.limits.context(), b.limits.context()]);
    expect((la as unknown as Record<string, unknown>)['whoami']).not.toBe((lb as unknown as Record<string, unknown>)['whoami']);
    expect(auth.loginContexts).toEqual(expect.arrayContaining([`Nip:${NIP_A}`, `Nip:${NIP_B}`]));
    const keys = new Set(storage.puts.map((p) => p.key));
    expect(keys.size).toBe(2); // one cache entry per tenant, never shared
    for (const k of keys) {
      expect(k).not.toContain(TOKEN_A);
      expect(k).not.toContain(TOKEN_B);
    }
    // a second call per tenant reuses its own cached pair (no new login)
    const logins = auth.counters.login;
    await a.limits.context();
    await b.limits.context();
    expect(auth.counters.login).toBe(logins);
    await mod.close();
  });

  it('InjectKsefService(name), registry.register at runtime, duplicates and unknown names', async () => {
    @Injectable()
    class TenantConsumer {
      constructor(@InjectKsefService('t1') readonly svc: KsefService) {}
    }
    const mod = await Test.createTestingModule({
      imports: [KsefModule.forRoot({ ...opts(TOKEN_A, NIP_A), name: 't1', isGlobal: true }), KsefModule.forFeature(['t1'])],
      providers: [TenantConsumer],
    }).compile();
    const registry = mod.get(KsefClientRegistry);
    expect(mod.get(TenantConsumer).svc).toBe(registry.service('t1'));
    expect(registry.service('t1').name).toBe('t1');
    const dyn = registry.register('dyn', opts(TOKEN_B, NIP_B));
    expect(registry.get('dyn')).toBe(dyn.client);
    expect((await dyn.limits.context()).onlineSession.maxInvoices).toBe(10000);
    expect(() => registry.register('dyn', opts(TOKEN_B, NIP_B))).toThrow(/already registered/);
    expect(() => registry.get('nope')).toThrow(/No KSeF client registered/);
    await mod.close();
    expect(dyn.shutdownSignal.aborted).toBe(true); // dynamic tenants are closed with the app
  });
});

describe('custom TokenStorage and logging', () => {
  it('uses the injected storage and never hands it the long-lived KSeF token', async () => {
    const storage = new RecordingStorage();
    const mod = await Test.createTestingModule({ imports: [KsefModule.forRoot(opts(TOKEN_A, NIP_A, { storage }))] }).compile();
    await mod.get(KsefService).limits.context();
    expect(storage.calls.some((c) => c.startsWith('get:'))).toBe(true);
    expect(storage.puts).toHaveLength(1);
    expect(JSON.stringify(storage.puts.map((p) => p.pair.toStorage()))).not.toContain(TOKEN_A);
    expect(storage.puts[0]?.pair.accessToken).toMatch(/^ACCESS-/);
    await mod.close();
  });

  it('logger "nest" writes redacted SDK events to the Nest Logger; no secret ever appears', async () => {
    const capture = new CaptureLogger();
    const mod = await Test.createTestingModule({ imports: [KsefModule.forRoot(opts(TOKEN_A, NIP_A, { logger: 'nest' }))] })
      .setLogger(capture)
      .compile();
    await mod.get(KsefService).limits.context();
    await mod.close();
    expect(capture.text).toContain('ksef.http.request');
    expect(capture.text).toContain('[REDACTED]');
    expect(capture.text).not.toContain(TOKEN_A);
    expect(capture.text).not.toMatch(/ACCESS-|REFRESH-|TEMP-/);
    expect(capture.text).not.toMatch(/Bearer\s+\S/);
  });
});
