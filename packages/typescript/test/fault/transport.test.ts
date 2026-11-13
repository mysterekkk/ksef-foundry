import { afterEach, describe, expect, it } from 'vitest';
import {
  AbortException,
  ApiException,
  ConfigurationException,
  Environment,
  HttpTransport,
  NetworkException,
  RateLimitException,
  ServerException,
  TimeoutException,
  UnknownOutcomeException,
  ValidationException,
  type Logger,
} from '../../src/index.js';
import { json, startServer, type LocalServer } from './local-server.js';

// fault-injection: real local HTTP server, real fetch. NOT proof of KSeF compatibility.

const servers: LocalServer[] = [];
afterEach(async () => {
  await Promise.all(servers.splice(0).map((s) => s.close()));
});
async function serve(h: Parameters<typeof startServer>[0]): Promise<LocalServer> {
  const s = await startServer(h);
  servers.push(s);
  return s;
}
function transport(s: LocalServer, extra: Partial<ConstructorParameters<typeof HttpTransport>[0]> = {}): HttpTransport {
  return new HttpTransport({
    environment: Environment.custom(s.baseUrl),
    retry: { maxAttempts: 3, baseDelayMs: 10, maxDelayMs: 20, maxRetryAfterSeconds: 5 },
    timeoutMs: 2000,
    ...extra,
  });
}

describe('fault-injection: 429 Retry-After', () => {
  it('honours Retry-After (seconds) on an idempotent GET', async () => {
    const s = await serve((_q, res, _b, n) => {
      if (n === 1) json(res, 429, { status: { code: 429, description: 'Too Many Requests', details: ['wait'] } }, { 'retry-after': '1' });
      else json(res, 200, { ok: true });
    });
    const t0 = Date.now();
    const r = await transport(s).request({ method: 'GET', path: '/x' });
    expect(r.data).toEqual({ ok: true });
    expect(s.hits).toHaveLength(2);
    expect(Date.now() - t0).toBeGreaterThanOrEqual(950);
  });

  it('also retries a non-idempotent POST on 429 (request not processed)', async () => {
    const s = await serve((_q, res, _b, n) => {
      if (n === 1) json(res, 429, { title: 'Too Many Requests', status: 429, detail: 'x' }, { 'retry-after': '0' });
      else json(res, 200, { ok: 1 });
    });
    await transport(s).request({ method: 'POST', path: '/x', json: { a: 1 } });
    expect(s.hits).toHaveLength(2);
    expect(s.hits[1]?.body).toBe('{"a":1}');
  });

  it('surfaces RateLimitException when Retry-After exceeds the cap (no waiting)', async () => {
    const s = await serve((_q, res) => {
      json(res, 429, { title: 'Too Many Requests', status: 429, detail: 'slow down', traceId: 'tr' }, { 'retry-after': '3600' });
    });
    const t0 = Date.now();
    const err = await transport(s).request({ method: 'GET', path: '/x' }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(RateLimitException);
    expect((err as RateLimitException).retryAfterSeconds).toBe(3600);
    expect((err as RateLimitException).traceId).toBe('tr');
    expect(Date.now() - t0).toBeLessThan(1500);
    expect(s.hits).toHaveLength(1);
  });

  it('parses an HTTP-date Retry-After', async () => {
    const s = await serve((_q, res, _b, n) => {
      if (n === 1) json(res, 429, {}, { 'retry-after': new Date(Date.now() + 1500).toUTCString() });
      else json(res, 200, { ok: true });
    });
    const r = await transport(s).request({ method: 'GET', path: '/x' });
    expect(r.status).toBe(200);
  });

  it('gives up after maxAttempts on persistent 429', async () => {
    const s = await serve((_q, res) => json(res, 429, {}, { 'retry-after': '0' }));
    await expect(transport(s).request({ method: 'GET', path: '/x' })).rejects.toBeInstanceOf(RateLimitException);
    expect(s.hits).toHaveLength(3);
  });
});

describe('fault-injection: 5xx', () => {
  it('does not retry 500 (GET)', async () => {
    const s = await serve((_q, res) => json(res, 500, { title: 'boom', status: 500, detail: 'x' }));
    await expect(transport(s).request({ method: 'GET', path: '/x' })).rejects.toBeInstanceOf(ServerException);
    expect(s.hits).toHaveLength(1);
  });

  it('retries 503 -> 200 on an idempotent GET', async () => {
    const s = await serve((_q, res, _b, n) => (n === 1 ? json(res, 503, {}) : json(res, 200, { ok: true })));
    const r = await transport(s).request({ method: 'GET', path: '/x' });
    expect(r.status).toBe(200);
    expect(s.hits).toHaveLength(2);
  });

  it('never retries 502/503/504 on a non-idempotent POST', async () => {
    for (const status of [502, 503, 504]) {
      const s = await serve((_q, res) => json(res, status, {}));
      const err = await transport(s).request({ method: 'POST', path: '/x', json: {} }).catch((e: unknown) => e);
      expect(err).toBeInstanceOf(ServerException);
      expect(s.hits).toHaveLength(1);
    }
  });

  it('ambiguous POST failure becomes UnknownOutcomeException carrying hash and session', async () => {
    const s = await serve((_q, res) => json(res, 500, {}));
    const err = await transport(s)
      .request({
        method: 'POST',
        path: '/x',
        json: {},
        unknownOutcome: { operation: 'sendInvoice', invoiceHash: 'HASH', sessionReferenceNumber: 'SESS' },
      })
      .catch((e: unknown) => e);
    expect(err).toBeInstanceOf(UnknownOutcomeException);
    const u = err as UnknownOutcomeException;
    expect([u.invoiceHash, u.sessionReferenceNumber, u.operation]).toEqual(['HASH', 'SESS', 'sendInvoice']);
    expect(u.cause).toBeInstanceOf(ServerException);
    expect(s.hits).toHaveLength(1);
  });

  it('a clean 400 on send is NOT ambiguous (surfaces as ValidationException)', async () => {
    const s = await serve((_q, res) => json(res, 400, { title: 'Bad Request', status: 400, detail: 'x', errors: [{ code: 21405, description: 'v' }] }));
    const err = await transport(s)
      .request({ method: 'POST', path: '/x', json: {}, unknownOutcome: { operation: 'sendInvoice' } })
      .catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ValidationException);
    expect((err as ValidationException).ksefCode).toBe(21405);
  });
});

describe('fault-injection: timeouts, resets, truncation', () => {
  it('times out a hanging GET (retried up to maxAttempts) with TimeoutException', async () => {
    const s = await serve(() => undefined); // never answers
    const err = await transport(s, { timeoutMs: 150 }).request({ method: 'GET', path: '/x' }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(TimeoutException);
    expect(err).toBeInstanceOf(NetworkException);
    expect(s.hits).toHaveLength(3);
  });

  it('a timed-out POST is not retried and is reported as unknown outcome', async () => {
    const s = await serve(() => undefined);
    const err = await transport(s, { timeoutMs: 150 })
      .request({ method: 'POST', path: '/x', json: {}, unknownOutcome: { operation: 'sendInvoice', invoiceHash: 'H' } })
      .catch((e: unknown) => e);
    expect(err).toBeInstanceOf(UnknownOutcomeException);
    expect((err as UnknownOutcomeException).cause).toBeInstanceOf(TimeoutException);
    expect(s.hits).toHaveLength(1);
  });

  it('the overall deadline bounds all attempts', async () => {
    const s = await serve(() => undefined);
    const t0 = Date.now();
    const err = await transport(s, { timeoutMs: 400, deadlineMs: 500 }).request({ method: 'GET', path: '/x' }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(TimeoutException);
    expect(Date.now() - t0).toBeLessThan(1500);
  });

  it('connection reset: GET retried, POST not', async () => {
    const s = await serve((_q, res) => {
      res.destroy();
    });
    const err = await transport(s).request({ method: 'GET', path: '/x' }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(NetworkException);
    expect(s.hits).toHaveLength(3);
    const s2 = await serve((_q, res) => {
      res.destroy();
    });
    const err2 = await transport(s2).request({ method: 'POST', path: '/x', json: {} }).catch((e: unknown) => e);
    expect(err2).toBeInstanceOf(NetworkException);
    expect(s2.hits).toHaveLength(1);
  });

  it('truncated body is a NetworkException (retried for GET)', async () => {
    const s = await serve((_q, res, _b, n) => {
      if (n === 1) {
        res.writeHead(200, { 'content-type': 'application/json', 'content-length': '1000' });
        res.write('{"partial":');
        setTimeout(() => res.destroy(), 20);
      } else json(res, 200, { ok: true });
    });
    const r = await transport(s).request({ method: 'GET', path: '/x' });
    expect(r.data).toEqual({ ok: true });
    expect(s.hits).toHaveLength(2);
  });

  it('connection refused is a NetworkException', async () => {
    const s = await serve(() => undefined);
    const t = transport(s);
    await s.close();
    await expect(t.request({ method: 'GET', path: '/x' })).rejects.toBeInstanceOf(NetworkException);
  });

  it('invalid JSON in a 200 is an ApiException (invalid_response), not retried', async () => {
    const s = await serve((_q, res) => {
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end('not json');
    });
    const err = await transport(s).request({ method: 'GET', path: '/x' }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiException);
    expect((err as ApiException).category).toBe('invalid_response');
    expect(s.hits).toHaveLength(1);
  });
});

describe('fault-injection: abort', () => {
  it('abort during a request is surfaced as AbortException and never retried', async () => {
    const s = await serve(() => undefined);
    const ac = new AbortController();
    setTimeout(() => ac.abort(), 100);
    const err = await transport(s).request({ method: 'GET', path: '/x', signal: ac.signal }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(AbortException);
    expect(s.hits).toHaveLength(1);
  });

  it('abort during the Retry-After wait stops immediately', async () => {
    const s = await serve((_q, res) => json(res, 429, {}, { 'retry-after': '4' }));
    const ac = new AbortController();
    setTimeout(() => ac.abort(), 150);
    const t0 = Date.now();
    const err = await transport(s).request({ method: 'GET', path: '/x', signal: ac.signal }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(AbortException);
    expect(Date.now() - t0).toBeLessThan(1500);
    expect(s.hits).toHaveLength(1);
  });

  it('an already-aborted signal does not hit the server', async () => {
    const s = await serve((_q, res) => json(res, 200, {}));
    const err = await transport(s).request({ method: 'GET', path: '/x', signal: AbortSignal.abort() }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(AbortException);
    expect(s.hits).toHaveLength(0);
  });
});

describe('fault-injection: redirects and URL safety', () => {
  it('refuses a cross-host redirect and never contacts the other host', async () => {
    const other = await serve((_q, res) => json(res, 200, { leaked: true }));
    const s = await serve((_q, res) => {
      res.writeHead(302, { location: `http://localhost:${new URL(other.origin).port}/steal` });
      res.end();
    });
    const err = await transport(s).request({ method: 'GET', path: '/x', bearer: 'SECRET-JWT' }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiException);
    expect((err as ApiException).category).toBe('redirect_refused');
    expect(other.hits).toHaveLength(0);
  });

  it('refuses redirects of non-GET requests even on the same host', async () => {
    const s = await serve((_q, res) => {
      res.writeHead(307, { location: '/v2/y' });
      res.end();
    });
    const err = await transport(s).request({ method: 'POST', path: '/x', json: {} }).catch((e: unknown) => e);
    expect((err as ApiException).category).toBe('redirect_refused');
    expect(s.hits).toHaveLength(1);
  });

  it('follows a same-host GET redirect', async () => {
    const s = await serve((q, res) => {
      if (q.url === '/v2/x') {
        res.writeHead(302, { location: '/v2/y' });
        res.end();
      } else json(res, 200, { at: q.url });
    });
    const r = await transport(s).request<{ at: string }>({ method: 'GET', path: '/x' });
    expect(r.data.at).toBe('/v2/y');
  });

  it('refuses absolute URLs without touching the network', async () => {
    const s = await serve((_q, res) => json(res, 200, {}));
    await expect(transport(s).request({ method: 'GET', path: `${s.origin}/v2/x` })).rejects.toBeInstanceOf(ConfigurationException);
    await expect(transport(s).request({ method: 'GET', path: '//evil.test/x' })).rejects.toBeInstanceOf(ConfigurationException);
    expect(s.hits).toHaveLength(0);
  });
});

describe('fault-injection: wire format, error bodies and redaction', () => {
  it('sends bearer, JSON content type, problem-details opt-in and a user agent', async () => {
    const s = await serve((_q, res) => json(res, 200, {}));
    await transport(s).request({ method: 'POST', path: '/x', json: { k: 'v' }, bearer: 'JWT-VALUE' });
    const h = s.hits[0]?.headers;
    expect(h?.['authorization']).toBe('Bearer JWT-VALUE');
    expect(h?.['content-type']).toBe('application/json');
    expect(h?.['x-error-format']).toBe('problem-details');
    expect(h?.['user-agent']).toMatch(/ksef-foundry/);
  });

  it('maps a legacy ExceptionResponse body', async () => {
    const s = await serve((_q, res) =>
      json(res, 400, { exception: { referenceNumber: 'R1', exceptionDetailList: [{ exceptionCode: 21405, exceptionDescription: 'Błąd walidacji', details: ['pole'] }] } }),
    );
    const err = (await transport(s).request({ method: 'GET', path: '/x' }).catch((e: unknown) => e)) as ValidationException;
    expect(err).toBeInstanceOf(ValidationException);
    expect(err.ksefCode).toBe(21405);
    expect(err.traceId).toBe('R1');
  });

  it('the logger and diagnostics never contain the bearer token', async () => {
    const lines: string[] = [];
    const logger: Logger = { log: (level, event, data) => void lines.push(JSON.stringify({ level, event, data })) };
    const s = await serve((_q, res, _b, n) => (n === 1 ? json(res, 503, {}) : json(res, 400, { title: 'x', status: 400, detail: 'd' })));
    const err = await transport(s, { logger }).request({ method: 'GET', path: '/x', bearer: 'TOP-SECRET-JWT' }).catch((e: unknown) => e);
    const all = lines.join('\n') + JSON.stringify((err as ApiException).diagnostics()) + String((err as Error).stack);
    expect(lines.length).toBeGreaterThan(0);
    expect(all).not.toContain('TOP-SECRET-JWT');
    expect(all).toContain('[REDACTED]');
  });

  it('reads bytes and text bodies', async () => {
    const s = await serve((_q, res) => {
      res.writeHead(200, { 'content-type': 'application/xml' });
      res.end('<a>zażółć</a>');
    });
    const t = transport(s);
    expect((await t.request<string>({ method: 'GET', path: '/x', responseType: 'text' })).data).toBe('<a>zażółć</a>');
    expect(Buffer.from((await t.request<Uint8Array>({ method: 'GET', path: '/x', responseType: 'bytes' })).data).toString()).toBe('<a>zażółć</a>');
  });
});
