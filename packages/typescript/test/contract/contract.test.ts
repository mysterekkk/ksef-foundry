import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import type { components } from '../../src/generated/openapi.js';
import {
  ApiContext,
  AuthSessionService,
  Environment,
  HttpTransport,
  InvoiceQueryService,
  InvoiceSessionService,
  KsefTokenService,
  LimitsService,
  PublicKeyProvider,
  SensitiveString,
  TestData,
  type AuthSessionPage,
  type ContextLimits,
  type GeneratedKsefToken,
  type InvoiceMetadataPage,
  type KsefTokenPage,
  type KsefTokenStatusResponse,
  type SessionInvoice,
  type SessionInvoicesPage,
  type SessionListPage,
  type SessionStatus,
  type TokenInfo,
  type AuthenticationChallenge,
  type AuthenticationInit,
  type AuthenticationTokens,
  type SubjectLimits,
  type RateLimits,
} from '../../src/index.js';
import { json, startServer, type LocalServer } from '../fault/local-server.js';
import { requestExample, requestSchema, responseExample, schemaOf, validate } from './openapi.js';

type S = components['schemas'];
const REF = 'ABCDEFGH-AU-0123456789-0123456789-AB';

// ---- compile-time conformance: our curated DTO keys == generated OpenAPI keys ------------------
// eslint-disable-next-line @typescript-eslint/no-unnecessary-type-parameters
type Equal<A, B> = (<T>() => T extends A ? 1 : 2) extends <T>() => T extends B ? 1 : 2 ? true : false;
type Assert<T extends true> = T;
type Check_sessionStatus = Assert<Equal<keyof SessionStatus, keyof S['SessionStatusResponse']>>;
type Check_sessionInvoice = Assert<Equal<keyof SessionInvoice, keyof S['SessionInvoiceStatusResponse']>>;
type Check_sessionInvoices = Assert<Equal<keyof SessionInvoicesPage, keyof S['SessionInvoicesResponse']>>;
type Check_sessionList = Assert<Equal<keyof SessionListPage, keyof S['SessionsQueryResponse']>>;
type Check_tokenStatus = Assert<Equal<keyof KsefTokenStatusResponse, keyof S['TokenStatusResponse']>>;
type Check_tokenPage = Assert<Equal<keyof KsefTokenPage, keyof S['QueryTokensResponse']>>;
type Check_generated = Assert<keyof S['GenerateTokenResponse'] extends keyof GeneratedKsefToken ? true : false>;
type Check_authSessions = Assert<Equal<keyof AuthSessionPage, keyof S['AuthenticationListResponse']>>;
type Check_metadataPage = Assert<Equal<keyof InvoiceMetadataPage, keyof S['QueryInvoicesMetadataResponse']>>;
type Check_contextLimits = Assert<Equal<keyof ContextLimits, keyof S['EffectiveContextLimits']>>;
type Check_subjectLimits = Assert<Equal<keyof SubjectLimits, keyof S['EffectiveSubjectLimits']>>;
type Check_tokenInfo = Assert<Equal<keyof TokenInfo, keyof S['TokenInfo']>>;
type Check_challenge = Assert<Equal<keyof AuthenticationChallenge, keyof S['AuthenticationChallengeResponse']>>;
type Check_init = Assert<Equal<keyof AuthenticationInit, keyof S['AuthenticationInitResponse']>>;
type Check_tokens = Assert<Equal<keyof AuthenticationTokens, keyof S['AuthenticationTokensResponse']>>;
export type Conformance = [Check_sessionStatus, Check_sessionInvoice, Check_sessionInvoices, Check_sessionList, Check_tokenStatus, Check_tokenPage, Check_generated, Check_authSessions, Check_metadataPage, Check_contextLimits, Check_subjectLimits, Check_tokenInfo, Check_challenge, Check_init, Check_tokens];

describe('DTO key sets match the generated OpenAPI types', () => {
  it('are asserted at compile time by `type Check_*` above (enforced by tsc / npm run typecheck)', () => {
    const probe: Conformance | undefined = undefined;
    expect(probe).toBeUndefined();
  });
});

// ---- official examples are valid against their schemas (validates the validator too) ------------
const RESPONSES: [string, string, string, string][] = [
  ['/auth/challenge', 'post', '200', 'AuthenticationChallengeResponse'],
  ['/auth/ksef-token', 'post', '202', 'AuthenticationInitResponse'],
  ['/auth/xades-signature', 'post', '202', 'AuthenticationInitResponse'],
  ['/auth/{referenceNumber}', 'get', '200', 'AuthenticationOperationStatusResponse'],
  ['/auth/token/redeem', 'post', '200', 'AuthenticationTokensResponse'],
  ['/auth/token/refresh', 'post', '200', 'AuthenticationTokenRefreshResponse'],
  ['/auth/sessions', 'get', '200', 'AuthenticationListResponse'],
  ['/tokens', 'post', '202', 'GenerateTokenResponse'],
  ['/tokens', 'get', '200', 'QueryTokensResponse'],
  ['/tokens/{referenceNumber}', 'get', '200', 'TokenStatusResponse'],
  ['/sessions/online', 'post', '201', 'OpenOnlineSessionResponse'],
  ['/sessions/online/{referenceNumber}/invoices', 'post', '202', 'SendInvoiceResponse'],
  ['/sessions', 'get', '200', 'SessionsQueryResponse'],
  ['/sessions/{referenceNumber}', 'get', '200', 'SessionStatusResponse'],
  ['/sessions/{referenceNumber}/invoices', 'get', '200', 'SessionInvoicesResponse'],
  ['/sessions/{referenceNumber}/invoices/failed', 'get', '200', 'SessionInvoicesResponse'],
  ['/sessions/{referenceNumber}/invoices/{invoiceReferenceNumber}', 'get', '200', 'SessionInvoiceStatusResponse'],
  ['/invoices/query/metadata', 'post', '200', 'QueryInvoicesMetadataResponse'],
  ['/limits/context', 'get', '200', 'EffectiveContextLimits'],
  ['/limits/subject', 'get', '200', 'EffectiveSubjectLimits'],
  ['/rate-limits', 'get', '200', 'EffectiveApiRateLimits'],
];

/** Official examples that omit fields the same document marks as required (recorded in docs/dev/findings-ts.md). */
const KNOWN_EXAMPLE_GAPS: Record<string, string[]> = {
  'get /auth/{referenceNumber} 200': ['$.authenticationMethodInfo: required but missing'],
  'get /sessions/{referenceNumber}/invoices/{invoiceReferenceNumber} 200': ['$.invoiceHash: required but missing'],
};

describe('official OpenAPI examples', () => {
  it.each(RESPONSES)('%s %s %s example validates against %s', (path, method, status, schema) => {
    const expected = KNOWN_EXAMPLE_GAPS[`${method} ${path} ${status}`] ?? [];
    expect(validate(responseExample(path, method, status), schemaOf(schema))).toEqual(expected);
  });

  it('the validator really rejects bad data', () => {
    expect(validate({ challenge: 'x' }, schemaOf('AuthenticationChallengeResponse')).length).toBeGreaterThan(0);
    expect(validate({ code: 'a', description: 1 }, schemaOf('StatusInfo')).length).toBeGreaterThan(0);
  });

  it('the public keys example parses as certificate entries', () => {
    const ex = responseExample('/security/public-key-certificates', 'get', '200') as { usage: string[] }[];
    expect(ex.length).toBeGreaterThan(0);
    expect(ex[0]?.usage[0]).toMatch(/Encryption$/);
  });
});

// ---- round trip through the real HTTP stack and our services ------------------------------------
describe('services decode official examples over real HTTP (round trip, additive fields tolerated)', () => {
  let server: LocalServer;
  let ctx: ApiContext;
  const routes: [RegExp, string, string, string][] = [
    [/^\/v2\/tokens$/, 'GET', '/tokens', '200'],
    [/^\/v2\/tokens\/[^/]+$/, 'GET', '/tokens/{referenceNumber}', '200'],
    [/^\/v2\/auth\/sessions$/, 'GET', '/auth/sessions', '200'],
    [/^\/v2\/sessions$/, 'GET', '/sessions', '200'],
    [/^\/v2\/sessions\/[^/]+$/, 'GET', '/sessions/{referenceNumber}', '200'],
    [/^\/v2\/sessions\/[^/]+\/invoices$/, 'GET', '/sessions/{referenceNumber}/invoices', '200'],
    [/^\/v2\/sessions\/[^/]+\/invoices\/failed$/, 'GET', '/sessions/{referenceNumber}/invoices/failed', '200'],
    [/^\/v2\/sessions\/[^/]+\/invoices\/[^/]+$/, 'GET', '/sessions/{referenceNumber}/invoices/{invoiceReferenceNumber}', '200'],
    [/^\/v2\/invoices\/query\/metadata$/, 'POST', '/invoices/query/metadata', '200'],
    [/^\/v2\/limits\/context$/, 'GET', '/limits/context', '200'],
    [/^\/v2\/limits\/subject$/, 'GET', '/limits/subject', '200'],
    [/^\/v2\/rate-limits$/, 'GET', '/rate-limits', '200'],
    [/^\/v2\/tokens$/, 'POST', '/tokens', '202'],
  ];

  beforeAll(async () => {
    server = await startServer((req, res) => {
      const path = (req.url ?? '').split('?')[0] ?? '';
      const r = routes.find(([re, m]) => re.test(path) && m === req.method);
      if (r === undefined) return json(res, 404, {});
      const ex = responseExample(r[2], r[1].toLowerCase(), r[3]);
      // additive unknown field must not break decoding
      json(res, r[1] === 'POST' && r[3] === '202' ? 202 : 200, typeof ex === 'object' && ex !== null && !Array.isArray(ex) ? { ...ex, futureField: 'x' } : ex);
    });
    const http = new HttpTransport({ environment: Environment.custom(server.baseUrl) });
    ctx = new ApiContext(http, { getAccessToken: () => Promise.resolve(new SensitiveString('t')), invalidate: () => Promise.resolve() });
  });
  afterAll(async () => {
    await server.close();
  });

  const strip = (v: unknown): unknown => {
    const o = { ...(v as Record<string, unknown>) };
    delete o['futureField'];
    return o;
  };

  it('tokens', async () => {
    const svc = new KsefTokenService(ctx);
    expect(strip(await svc.list())).toEqual(responseExample('/tokens', 'get', '200'));
    expect(strip(await svc.get(REF))).toEqual(responseExample('/tokens/{referenceNumber}', 'get', '200'));
    const gen = await svc.generate({ permissions: ['InvoiceRead'], description: 'long enough description' });
    expect({ referenceNumber: gen.referenceNumber, token: gen.token }).toEqual(responseExample('/tokens', 'post', '202'));
  });
  it('auth sessions', async () => {
    expect(strip(await new AuthSessionService(ctx).list())).toEqual(responseExample('/auth/sessions', 'get', '200'));
  });
  it('sessions', async () => {
    const svc = new InvoiceSessionService(ctx, new PublicKeyProvider(ctx.http));
    expect(strip(await svc.listSessions({ sessionType: 'Online' }))).toEqual(responseExample('/sessions', 'get', '200'));
    expect(strip(await svc.getStatus(REF))).toEqual(responseExample('/sessions/{referenceNumber}', 'get', '200'));
    expect(strip(await svc.listSessionInvoices(REF))).toEqual(responseExample('/sessions/{referenceNumber}/invoices', 'get', '200'));
    expect(strip(await svc.listFailedInvoices(REF))).toEqual(responseExample('/sessions/{referenceNumber}/invoices/failed', 'get', '200'));
    expect(strip(await svc.getInvoiceStatus(REF, REF))).toEqual(
      responseExample('/sessions/{referenceNumber}/invoices/{invoiceReferenceNumber}', 'get', '200'),
    );
  });
  it('metadata query', async () => {
    const page = await new InvoiceQueryService(ctx).queryMetadata({
      subjectType: 'Subject1',
      dateRange: { dateType: 'Issue', from: new Date(Date.now() - 86_400_000) },
    });
    expect(strip(page)).toEqual(responseExample('/invoices/query/metadata', 'post', '200'));
  });
  it('limits', async () => {
    const svc = new LimitsService(ctx);
    expect(strip(await svc.context())).toEqual(responseExample('/limits/context', 'get', '200'));
    expect(strip(await svc.subject())).toEqual(responseExample('/limits/subject', 'get', '200'));
    const rl: RateLimits = await svc.rateLimits();
    expect(strip(rl)).toEqual(responseExample('/rate-limits', 'get', '200'));
  });
});

// ---- requests produced by the SDK validate against the OpenAPI request schemas ------------------
describe('SDK requests conform to the OpenAPI request schemas', () => {
  it('official request examples validate (control)', () => {
    for (const [p, m] of [
      ['/auth/ksef-token', 'post'],
      ['/tokens', 'post'],
      ['/sessions/online', 'post'],
      ['/sessions/online/{referenceNumber}/invoices', 'post'],
      ['/invoices/query/metadata', 'post'],
      ['/testdata/person', 'post'],
    ] as const) {
      expect(validate(requestExample(p, m), requestSchema(p, m)), `${m} ${p}`).toEqual([]);
    }
  });

  it('tokens.generate / queryMetadata / testData.createPerson bodies', async () => {
    const server = await startServer((_q, res) => json(res, 200, { invoices: [], hasMore: false, isTruncated: false, referenceNumber: REF, token: 't' }));
    try {
      const http = new HttpTransport({ environment: Environment.custom(server.baseUrl) });
      const ctx = new ApiContext(http, { getAccessToken: () => Promise.resolve(new SensitiveString('t')), invalidate: () => Promise.resolve() });
      await new KsefTokenService(ctx).generate({ permissions: ['InvoiceRead', 'InvoiceWrite'], description: 'contract test token' });
      await new InvoiceQueryService(ctx).queryMetadata(
        {
          subjectType: 'Subject2',
          dateRange: { dateType: 'PermanentStorage', from: new Date('2026-01-01T00:00:00Z'), to: new Date('2026-02-01T10:00:00+01:00') },
          sellerNip: '5265877635',
          amount: { type: 'Brutto', from: 1.5 },
          currencyCodes: ['PLN'],
          invoiceTypes: ['Vat'],
        },
        { pageSize: 10 },
      );
      const bodies = server.hits.map((h) => JSON.parse(h.body) as unknown);
      expect(validate(bodies[0], requestSchema('/tokens', 'post'))).toEqual([]);
      expect(validate(bodies[1], requestSchema('/invoices/query/metadata', 'post'))).toEqual([]);
      expect((bodies[1] as { dateRange: { from: string; to: string } }).dateRange.to).toBe('2026-02-01T09:00:00.000+00:00');
      expect(server.hits[1]?.url).toContain('pageSize=10');
    } finally {
      await server.close();
    }
  });

  it('TestData refuses non-TEST environments (no request is made)', async () => {
    const server = await startServer((_q, res) => json(res, 200, {}));
    try {
      const http = new HttpTransport({ environment: Environment.custom(server.baseUrl) });
      const ctx = new ApiContext(http, { getAccessToken: () => Promise.resolve(new SensitiveString('t')), invalidate: () => Promise.resolve() });
      await expect(new TestData(ctx).createPerson({ nip: '5265877635', pesel: '44051401359' })).rejects.toThrow(/TEST/);
      expect(server.hits).toHaveLength(0);
    } finally {
      await server.close();
    }
  });
});
