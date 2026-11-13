<?php

declare(strict_types=1);

use LuroWeb\KsefFoundry\Auth\KsefTokenCredentials;
use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Environment;
use LuroWeb\KsefFoundry\Exception\ApiException;
use LuroWeb\KsefFoundry\Exception\AuthorizationException;
use LuroWeb\KsefFoundry\Exception\RateLimitException;
use LuroWeb\KsefFoundry\Http\HttpRequest;
use LuroWeb\KsefFoundry\Http\RetryPolicy;
use LuroWeb\KsefFoundry\Http\TransportOptions;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Support\SensitiveString;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;

require __DIR__ . '/../vendor/autoload.php';

// A REAL local HTTP server (PHP built-in server) that answers like KSeF would for 429 and 403 (problem+json).
// The real KSeF cannot be forced to return these on demand, so this demonstrates the handling code path only.
$router = tempnam(sys_get_temp_dir(), 'ksef-router') . '.php';
file_put_contents($router, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/problem+json');
$base = ['title' => 'demo', 'instance' => $path, 'timestamp' => gmdate('c'), 'traceId' => 'demo-trace'];
if (str_starts_with($path, '/v2/rate')) {
    http_response_code(429);
    header('Retry-After: 2');
    echo json_encode($base + ['status' => 429, 'detail' => 'Przekroczono limit żądań. Spróbuj ponownie po 2 sekundach.']);
} else {
    http_response_code(403);
    echo json_encode($base + [
        'status' => 403, 'detail' => 'Brak uprawnień', 'reasonCode' => 'missing-permissions',
        'security' => ['requiredAnyOfPermissions' => ['InvoiceWrite'], 'presentPermissions' => ['InvoiceRead']],
    ]);
}
PHP);

$port = random_int(20000, 40000);
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], [1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100_000);
}

try {
    $client = KsefClient::builder()
        ->environment(Environment::custom("http://127.0.0.1:{$port}/v2")) // loopback only; unsafe for real data
        ->credentials(new KsefTokenCredentials(new SensitiveString('demo-token-not-real'), ContextIdentifier::nip(SyntheticIdentity::nip())))
        ->transportOptions(new TransportOptions(retry: RetryPolicy::none()))
        ->build();

    foreach (['/rate', '/permissions-demo'] as $path) {
        try {
            $client->transport()->send(new HttpRequest('GET', $path, idempotent: true));
        } catch (RateLimitException $e) {
            echo "429 -> wait {$e->retryAfterSeconds()}s, then retry (retryable=" . var_export($e->retryable(), true) . ", traceId={$e->traceId()})\n";
        } catch (AuthorizationException $e) {
            echo '403 -> missing permissions: ' . json_encode($e->diagnostics(), JSON_UNESCAPED_UNICODE) . "\n";
        } catch (ApiException $e) {
            echo 'other API error ' . $e->statusCode() . ' ' . var_export($e->ksefCode(), true) . "\n";
        }
    }
} finally {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    @unlink($router);
}
