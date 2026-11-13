import { inspect } from 'node:util';
import { describe, expect, it } from 'vitest';
import {
  ConfigurationException,
  Context,
  Environment,
  HttpTransport,
  RateLimitException,
  SensitiveString,
  ValidationException,
  AuthorizationException,
  GoneException,
  NotFoundException,
  AuthenticationException,
  ServerException,
  backoffDelayMs,
  createApiException,
  defaultRetryPolicy,
  generateTestNip,
  generateTestPesel,
  parseRetryAfter,
  redact,
  redactHeaders,
  assertSafeInvoiceXml,
  toUtcIso,
  buildAuthTokenRequest,
} from '../../src/index.js';

describe('SensitiveString', () => {
  const s = new SensitiveString('super-secret-token');
  it('never leaks through toString/JSON/inspect/template', () => {
    expect(String(s)).toBe('[REDACTED]');
    expect(s.toString()).toBe('[REDACTED]');
    expect(JSON.stringify({ s })).toBe('{"s":"[REDACTED]"}');
    expect(inspect(s)).toBe('[REDACTED]');
    expect(inspect({ nested: { s } })).not.toContain('super-secret');
    expect(s.reveal()).toBe('super-secret-token');
  });
  it('redacts secret-looking keys and headers', () => {
    const r = redact({ accessToken: 'a', ok: 1, nested: [{ Authorization: 'Bearer x' }], s });
    expect(JSON.stringify(r)).not.toMatch(/Bearer x|"a"|super-secret/);
    expect(redactHeaders({ Authorization: 'Bearer zzz', Accept: 'json' })).toEqual({
      Authorization: '[REDACTED]',
      Accept: 'json',
    });
  });
});

describe('Environment', () => {
  it('has the official base URLs', () => {
    expect(Environment.TEST.baseUrl).toBe('https://api-test.ksef.mf.gov.pl/v2');
    expect(Environment.DEMO.baseUrl).toBe('https://api-demo.ksef.mf.gov.pl/v2');
    expect(Environment.PRODUCTION.baseUrl).toBe('https://api.ksef.mf.gov.pl/v2');
  });
  it('custom: https or loopback http only', () => {
    expect(Environment.custom('http://127.0.0.1:8080/v2/').baseUrl).toBe('http://127.0.0.1:8080/v2');
    expect(Environment.custom('http://localhost:1/x').host).toBe('localhost:1');
    expect(() => Environment.custom('http://example.com/v2')).toThrow(ConfigurationException);
    expect(() => Environment.custom('ftp://example.com')).toThrow(ConfigurationException);
    expect(() => Environment.custom('not a url')).toThrow(ConfigurationException);
    expect(() => Environment.custom('https://user:pw@example.com')).toThrow(ConfigurationException);
    expect(Environment.custom('https://example.com/v2').name).toBe('CUSTOM');
  });
});

describe('URL building', () => {
  const http = new HttpTransport({ environment: Environment.TEST });
  it('builds paths and query strings', () => {
    expect(http.buildUrl('/sessions', { pageSize: 10, statuses: ['A', 'B'], skip: undefined })).toBe(
      'https://api-test.ksef.mf.gov.pl/v2/sessions?pageSize=10&statuses=A&statuses=B',
    );
  });
  it.each(['https://evil.example/x', '//evil.example/x', 'sessions', '/a\\b', '/x://y'])('refuses %s', (p) => {
    expect(() => http.buildUrl(p)).toThrow(ConfigurationException);
  });
});

describe('retry maths', () => {
  it('parses Retry-After seconds and HTTP-date', () => {
    expect(parseRetryAfter('30')).toBe(30);
    expect(parseRetryAfter(' 0 ')).toBe(0);
    const now = Date.parse('2026-01-01T00:00:00Z');
    expect(parseRetryAfter('Thu, 01 Jan 2026 00:00:45 GMT', now)).toBe(45);
    expect(parseRetryAfter('Wed, 31 Dec 2025 23:00:00 GMT', now)).toBe(0);
    expect(parseRetryAfter('soon')).toBeUndefined();
    expect(parseRetryAfter(null)).toBeUndefined();
    expect(parseRetryAfter('')).toBeUndefined();
  });
  it('backoff is exponential, jittered and capped', () => {
    const p = { ...defaultRetryPolicy, baseDelayMs: 100, maxDelayMs: 1000 };
    expect(backoffDelayMs(1, p, () => 1)).toBe(100);
    expect(backoffDelayMs(1, p, () => 0)).toBe(50);
    expect(backoffDelayMs(3, p, () => 1)).toBe(400);
    expect(backoffDelayMs(10, p, () => 1)).toBe(1000);
  });
});

describe('error mapping', () => {
  it('maps statuses to the hierarchy', () => {
    const m = (s: number) => createApiException({ statusCode: s, body: undefined });
    expect(m(400)).toBeInstanceOf(ValidationException);
    expect(m(401)).toBeInstanceOf(AuthenticationException);
    expect(m(403)).toBeInstanceOf(AuthorizationException);
    expect(m(404)).toBeInstanceOf(NotFoundException);
    expect(m(410)).toBeInstanceOf(GoneException);
    expect(m(429)).toBeInstanceOf(RateLimitException);
    expect(m(503)).toBeInstanceOf(ServerException);
    expect(m(503).retryable).toBe(true);
    expect(m(500).retryable).toBe(false);
    expect(m(400).retryable).toBe(false);
  });
  it('parses problem+json', () => {
    const e = createApiException({
      statusCode: 400,
      body: {
        title: 'Bad Request',
        status: 400,
        detail: 'x',
        traceId: 'T-1',
        errors: [{ code: 21470, description: 'Bad key', details: ['d1'] }, { code: 21405, description: 'v' }],
      },
    });
    expect(e.ksefCode).toBe(21470);
    expect(e.ksefCodes).toEqual([21470, 21405]);
    expect(e.traceId).toBe('T-1');
    expect(e.details).toEqual(['d1']);
    expect(e.message).toContain('Bad key');
    expect(e.name).toBe('ValidationException');
    expect(e instanceof Error && e instanceof ValidationException).toBe(true);
  });
  it('parses the legacy ExceptionResponse', () => {
    const e = createApiException({
      statusCode: 400,
      body: { exception: { referenceNumber: 'REF', exceptionDetailList: [{ exceptionCode: 21111, exceptionDescription: 'Legacy', details: ['dd'] }] } },
    });
    expect(e.ksefCode).toBe(21111);
    expect(e.traceId).toBe('REF');
    expect(e.details).toEqual(['dd']);
  });
  it('parses the legacy 429 body and keeps Retry-After', () => {
    const e = createApiException({
      statusCode: 429,
      body: { status: { code: 429, description: 'Too Many Requests', details: ['Spróbuj ponownie po 30 sekundach.'] } },
      retryAfterSeconds: 30,
    });
    expect(e).toBeInstanceOf(RateLimitException);
    expect(e.retryAfterSeconds).toBe(30);
    expect(e.message).toContain('30');
  });
  it('403 exposes reasonCode and diagnostics are secret free', () => {
    const e = createApiException({
      statusCode: 403,
      body: { title: 'Forbidden', status: 403, detail: 'no', reasonCode: 'missing-permissions', security: { requiredAnyOfPermissions: ['InvoiceRead'] } },
    });
    expect(e).toBeInstanceOf(AuthorizationException);
    expect((e as AuthorizationException).reasonCode).toBe('missing-permissions');
    const d = JSON.stringify(e.diagnostics());
    expect(d).toContain('missing-permissions');
    expect(d).not.toMatch(/Bearer|eyJ/);
  });
  it('tolerates garbage bodies', () => {
    expect(createApiException({ statusCode: 500, body: 'oops' }).message).toBe('HTTP 500');
    expect(createApiException({ statusCode: 502, body: [1, 2] }).statusCode).toBe(502);
  });
});

describe('synthetic identifiers', () => {
  it('NIP is checksum-valid and matches the OpenAPI pattern', () => {
    for (let i = 0; i < 200; i++) {
      const nip = generateTestNip();
      expect(nip).toMatch(/^[1-9]((\d[1-9])|([1-9]\d))\d{7}$/);
      const w = [6, 5, 7, 2, 3, 4, 5, 6, 7];
      const sum = w.reduce((a, x, k) => a + x * Number(nip[k]), 0);
      expect(sum % 11).toBe(Number(nip[9]));
      expect(Context.nip(nip).value).toBe(nip);
    }
    expect(() => Context.nip('0123456789')).toThrow(ConfigurationException);
  });
  it('PESEL is checksum-valid and matches the OpenAPI pattern', () => {
    const re = /^\d{2}(?:0[1-9]|1[0-2]|2[1-9]|3[0-2]|4[1-9]|5[0-2]|6[1-9]|7[0-2]|8[1-9]|9[0-2])\d{7}$/;
    for (let i = 0; i < 200; i++) {
      const p = generateTestPesel();
      expect(p).toMatch(re);
      const w = [1, 3, 7, 9, 1, 3, 7, 9, 1, 3, 1];
      expect(w.reduce((a, x, k) => a + x * Number(p[k]), 0) % 10).toBe(0);
    }
  });
});

describe('misc helpers', () => {
  it('rejects DOCTYPE/PI in invoices but allows the XML declaration', () => {
    expect(() => assertSafeInvoiceXml('<?xml version="1.0" encoding="UTF-8"?><Faktura/>')).not.toThrow();
    expect(() => assertSafeInvoiceXml('<!DOCTYPE x [<!ENTITY a "b">]><Faktura/>')).toThrow(ConfigurationException);
    expect(() => assertSafeInvoiceXml('<?xml version="1.0"?><?pi data?><Faktura/>')).toThrow(ConfigurationException);
  });
  it('formats UTC instants with explicit offset', () => {
    expect(toUtcIso(new Date('2026-01-03T14:45:00+01:00'))).toBe('2026-01-03T13:45:00.000+00:00');
    expect(() => toUtcIso('garbage')).toThrow(ConfigurationException);
  });
  it('builds a schema-shaped AuthTokenRequest', () => {
    const xml = buildAuthTokenRequest({
      challenge: '20250604-CR-461EA5B000-537A6BA15D-D7',
      context: Context.nip('5265877635'),
      subjectIdentifierType: 'certificateSubject',
      authorizationPolicy: { allowedIps: { ip4Addresses: ['192.168.0.1'], ip4Masks: ['10.0.0.0/8'] } },
    });
    expect(xml).toContain('xmlns="http://ksef.mf.gov.pl/auth/token/2.1"');
    expect(xml).toContain('<ContextIdentifier><Nip>5265877635</Nip></ContextIdentifier>');
    expect(xml).toMatch(/<Ip4Address>192\.168\.0\.1<\/Ip4Address><Ip4Mask>10\.0\.0\.0\/8<\/Ip4Mask>/);
    expect(() =>
      buildAuthTokenRequest({ challenge: 'bad', context: Context.nip('5265877635'), subjectIdentifierType: 'certificateSubject' }),
    ).toThrow(ConfigurationException);
  });
});
