<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Http;

use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Http\Psr7\Request;
use LuroWeb\KsefFoundry\Http\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Zero-dependency PSR-18 client on ext-curl.
 *
 * TLS verification is always on, minimum TLS 1.2, redirects are NEVER followed (a 3xx is returned
 * as-is and treated as an error by the transport), only http/https protocols are allowed.
 */
final class CurlHttpClient implements TimeoutAwareClient
{
    public function __construct(
        private readonly float $connectTimeout = 10.0,
        private readonly float $timeout = 30.0,
        private readonly ?string $caBundlePath = null,
        private readonly int $maxResponseBytes = 67_108_864,
    ) {
        if (!extension_loaded('curl')) {
            throw new ConfigurationException('The built-in HTTP client needs ext-curl; install it or inject a PSR-18 client.');
        }
    }

    public function sendRequest(#[\SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        return $this->sendWithTimeout($request, $this->timeout);
    }

    public function sendWithTimeout(#[\SensitiveParameter] RequestInterface $request, float $timeoutSeconds): ResponseInterface
    {
        $ch = curl_init();
        $headerLines = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (strtolower($name) === 'host') {
                continue;
            }
            $headerLines[] = $name . ': ' . implode(', ', $values);
        }
        $headerLines[] = 'Expect:';
        $body = (string) $request->getBody();
        $method = strtoupper($request->getMethod());
        $respHeaders = [];
        $status = 0;
        $reason = '';
        $received = 0;
        $max = $this->maxResponseBytes;
        $buffer = '';

        /** @var array<int, mixed> $opts */
        $opts = [
            CURLOPT_URL => (string) $request->getUri(),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($this->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => max(1, (int) ceil($timeoutSeconds * 1000)),
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders, &$status, &$reason): int {
                $len = strlen($line);
                $t = trim($line);
                if (preg_match('#^HTTP/\S+\s+(\d{3})\s*(.*)$#', $t, $m) === 1) {
                    $status = (int) $m[1];
                    $reason = $m[2];
                    $respHeaders = [];
                } elseif (str_contains($t, ':')) {
                    [$n, $v] = explode(':', $t, 2);
                    $respHeaders[trim($n)][] = trim($v);
                }

                return $len;
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$buffer, &$received, $max): int {
                $received += strlen($chunk);
                if ($received > $max) {
                    return 0;
                }
                $buffer .= $chunk;

                return strlen($chunk);
            },
        ];
        if ($this->caBundlePath !== null) {
            $opts[CURLOPT_CAINFO] = $this->caBundlePath;
        } elseif (
            (string) ini_get('curl.cainfo') === '' && (string) ini_get('openssl.cafile') === ''
            && defined('CURLSSLOPT_NATIVE_CA')
        ) {
            // No CA bundle configured in php.ini (typical on Windows): use the OS trust store. Verification stays ON.
            $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        if ($body !== '' || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }
        foreach ($opts as $option => $value) {
            curl_setopt($ch, $option, $value);
        }

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($ok === false || $errno !== 0) {
            $timeout = $errno === CURLE_OPERATION_TIMEDOUT;
            // Message is built from the curl error only; it never contains headers or body.
            // The exception carries a sanitised request (method + URI only): never the headers or the body.
            throw new HttpClientError(
                sprintf('HTTP transport error (curl %d): %s', $errno, $error),
                new Request($request->getMethod(), $request->getUri()),
                $timeout
            );
        }

        return new Response($status, $respHeaders, $buffer, $reason);
    }
}
