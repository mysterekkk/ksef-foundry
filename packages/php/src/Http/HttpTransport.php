<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Http;

use LuroWeb\KsefFoundry\Environment;
use LuroWeb\KsefFoundry\Exception\ApiException;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Exception\NetworkException;
use LuroWeb\KsefFoundry\Exception\TimeoutException;
use LuroWeb\KsefFoundry\Support\Redactor;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Low-level transport: URL building, headers, deadlines, retry policy (contract section 6),
 * error mapping (section 7) and redacted PSR-3 logging. Never follows redirects, never calls
 * a host other than the environment host or an explicitly allow-listed one.
 */
final class HttpTransport
{
    private readonly LoggerInterface $logger;
    private readonly \Closure $sleeper;
    private readonly ErrorMapper $errors;

    /**
     * @param (\Closure(float): void)|null $sleeper injectable for deterministic tests of back-off
     */
    public function __construct(
        private readonly Environment $environment,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly TransportOptions $options = new TransportOptions(),
        ?LoggerInterface $logger = null,
        ?\Closure $sleeper = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->sleeper = $sleeper ?? static function (float $s): void {
            if ($s > 0) {
                usleep((int) round($s * 1_000_000));
            }
        };
        $this->errors = new ErrorMapper();
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    /**
     * Sends the request and returns the 2xx response, or throws a mapped exception.
     */
    public function send(#[\SensitiveParameter] HttpRequest $request): HttpResponse
    {
        $url = $this->buildUrl($request);
        $policy = $this->options->retry;
        $deadline = microtime(true) + $this->options->overallDeadline;
        $attempt = 0;
        $redactor = new Redactor();

        while (true) {
            ++$attempt;
            $started = microtime(true);
            try {
                $response = $this->dispatch($request, $url, max(0.5, min($this->options->requestTimeout, $deadline - $started)));
            } catch (NetworkException $e) {
                $this->log('warning', 'ksef.http.network_error', $request, ['attempt' => $attempt, 'error' => $redactor->redactString($e->getMessage())]);
                if ($attempt < $policy->maxAttempts && $policy->shouldRetryNetworkError($request->idempotent)) {
                    $delay = $policy->delaySeconds($attempt);
                    if (microtime(true) + $delay < $deadline) {
                        ($this->sleeper)($delay);
                        continue;
                    }
                }
                throw $e;
            }

            $this->log('debug', 'ksef.http.response', $request, [
                'attempt' => $attempt,
                'status' => $response->status,
                'ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            if ($response->status >= 200 && $response->status < 300) {
                return $response;
            }

            $error = $response->status >= 300 && $response->status < 400
                ? new ApiException(
                    sprintf('KSeF API answered with redirect %d; redirects are never followed.', $response->status),
                    $response->status
                )
                : $this->errors->map($response);

            if ($attempt < $policy->maxAttempts && $policy->shouldRetryStatus($response->status, $request->idempotent)) {
                $retryAfter = RetryPolicy::parseRetryAfter($response->header('Retry-After'));
                $delay = $policy->delaySeconds($attempt, $retryAfter);
                if (microtime(true) + $delay < $deadline) {
                    $this->log('info', 'ksef.http.retry', $request, ['attempt' => $attempt, 'status' => $response->status, 'delay' => $delay]);
                    ($this->sleeper)($delay);
                    continue;
                }
            }
            throw $error;
        }
    }

    private function dispatch(#[\SensitiveParameter] HttpRequest $request, string $url, float $timeout): HttpResponse
    {
        $psr = $this->requestFactory->createRequest($request->method, $url)
            ->withHeader('Accept', $request->accept)
            ->withHeader('User-Agent', $this->options->userAgent)
            ->withHeader('X-Error-Format', 'problem-details');
        foreach ($request->headers as $n => $v) {
            $psr = $psr->withHeader($n, $v);
        }
        if ($request->bearer !== null) {
            $psr = $psr->withHeader('Authorization', 'Bearer ' . $request->bearer->reveal());
        }
        if ($request->body !== null) {
            $psr = $psr->withHeader('Content-Type', $request->contentType)
                ->withBody($this->streamFactory->createStream($request->body));
        }

        try {
            $resp = $this->client instanceof TimeoutAwareClient
                ? $this->client->sendWithTimeout($psr, $request->timeout ?? $timeout)
                : $this->client->sendRequest($psr);
        } catch (HttpClientError $e) {
            // The raw client exception (and its PSR-7 request with the Authorization header) is NEVER chained.
            $msg = (new Redactor())->redactString($e->getMessage());

            throw $e->isTimeout() ? new TimeoutException($msg) : new NetworkException($msg);
        } catch (ClientExceptionInterface $e) {
            $timedOut = stripos($e->getMessage(), 'timed out') !== false || stripos($e->getMessage(), 'timeout') !== false;
            $msg = 'HTTP transport error (' . $e::class . '): ' . (new Redactor())->redactString($e->getMessage());

            throw $timedOut ? new TimeoutException($msg) : new NetworkException($msg);
        }

        $headers = [];
        foreach ($resp->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = array_values(array_map('strval', $values));
        }
        $body = $resp->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return new HttpResponse($resp->getStatusCode(), $headers, $body->getContents());
    }

    private function buildUrl(HttpRequest $request): string
    {
        $path = $request->path;
        if (preg_match('#^([a-z][a-z0-9+.\-]*:)?//#i', $path) === 1 || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $path) === 1) {
            return $this->checkAbsolute($path);
        }
        if ($path === '' || $path[0] !== '/' || str_contains($path, '..') || str_contains($path, "\r") || str_contains($path, "\n")) {
            throw new ConfigurationException('API path must be a clean relative path starting with "/".');
        }
        $url = $this->environment->baseUrl . $path;
        $pairs = [];
        foreach ($request->query as $k => $v) {
            if ($v === null) {
                continue;
            }
            foreach (is_array($v) ? $v : [$v] as $item) {
                $pairs[] = rawurlencode($k) . '=' . rawurlencode(is_bool($item) ? ($item ? 'true' : 'false') : (string) $item);
            }
        }

        return $pairs === [] ? $url : $url . '?' . implode('&', $pairs);
    }

    private function checkAbsolute(string $url): string
    {
        $p = parse_url($url);
        $host = strtolower(trim($p['host'] ?? '', '[]'));
        $scheme = strtolower($p['scheme'] ?? '');
        $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
        $envPort = parse_url($this->environment->baseUrl, PHP_URL_PORT)
            ?? (parse_url($this->environment->baseUrl, PHP_URL_SCHEME) === 'https' ? 443 : 80);
        // Allowed authorities: the environment's own host:port, plus allow-listed "host" (default port) or "host:port".
        $allowed = [$this->environment->host() . ':' . $envPort];
        foreach ($this->options->allowedHosts as $h) {
            $h = strtolower($h);
            $allowed[] = str_contains($h, ':') ? $h : $h . ':' . ($scheme === 'https' ? 443 : 80);
        }
        $loopback = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
        if (
            $host === '' || !in_array($host . ':' . $port, $allowed, true) || isset($p['user'])
            || ($scheme !== 'https' && !($scheme === 'http' && $loopback))
        ) {
            throw new ConfigurationException(sprintf(
                'Refusing to call absolute URL: authority "%s:%s" is not the environment host or allow-listed.',
                $host,
                $port
            ));
        }

        return $url;
    }

    /** @param array<string, scalar|null> $context */
    private function log(string $level, string $message, HttpRequest $request, array $context): void
    {
        // Only method + path. Never headers, query values, request or response bodies.
        $this->logger->log($level, $message, ['method' => $request->method, 'path' => $request->path] + $context);
    }
}
