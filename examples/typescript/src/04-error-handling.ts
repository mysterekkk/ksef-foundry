import { createServer } from 'node:http';
import {
  ApiException,
  AuthorizationException,
  Context,
  Environment,
  KsefClient,
  KsefTokenCredentials,
  RateLimitException,
  generateTestNip,
} from '@luroweb/ksef-foundry';

// A REAL local HTTP server that answers like KSeF would for 429 and 403 (problem+json).
// The real KSeF cannot be forced to return these on demand, so this demonstrates the handling code path only.
const server = createServer((req, res) => {
  const problem = (status: number, extra: Record<string, unknown>, headers: Record<string, string> = {}): void => {
    res.writeHead(status, { 'content-type': 'application/problem+json', ...headers });
    res.end(JSON.stringify({ title: 'demo', status, instance: req.url, timestamp: new Date().toISOString(), traceId: 'demo-trace', ...extra }));
  };
  if (req.url?.startsWith('/v2/rate')) {
    problem(429, { detail: 'Przekroczono limit żądań. Spróbuj ponownie po 2 sekundach.' }, { 'retry-after': '2' });
  } else {
    problem(403, {
      detail: 'Brak uprawnień',
      reasonCode: 'missing-permissions',
      security: { requiredAnyOfPermissions: ['InvoiceWrite'], presentPermissions: ['InvoiceRead'] },
    });
  }
});
await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
const address = server.address();
const port = typeof address === 'object' && address !== null ? address.port : 0;

const client = KsefClient.create({
  environment: Environment.custom(`http://127.0.0.1:${String(port)}/v2`), // loopback only; unsafe for real data
  credentials: new KsefTokenCredentials('demo-token-not-real', Context.nip(generateTestNip())),
  retry: { maxAttempts: 1 },
});

try {
  for (const path of ['/rate', '/permissions-demo']) {
    try {
      await client.http.request({ method: 'GET', path, idempotent: true });
    } catch (e) {
      if (e instanceof RateLimitException) {
        console.log(`429 -> wait ${String(e.retryAfterSeconds)}s, then retry (retryable=${String(e.retryable)}, traceId=${String(e.traceId)})`);
      } else if (e instanceof AuthorizationException) {
        console.log('403 -> missing permissions:', JSON.stringify(e.diagnostics()));
      } else if (e instanceof ApiException) {
        console.log('other API error', e.statusCode, e.ksefCode);
      } else throw e;
    }
  }
} finally {
  server.close();
}
