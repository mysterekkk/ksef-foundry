<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Http;

use LuroWeb\KsefFoundry\Exception\ApiException;
use LuroWeb\KsefFoundry\Exception\AuthenticationException;
use LuroWeb\KsefFoundry\Exception\AuthorizationException;
use LuroWeb\KsefFoundry\Exception\GoneException;
use LuroWeb\KsefFoundry\Exception\NotFoundException;
use LuroWeb\KsefFoundry\Exception\RateLimitException;
use LuroWeb\KsefFoundry\Exception\ServerException;
use LuroWeb\KsefFoundry\Exception\ValidationException;
use LuroWeb\KsefFoundry\Support\Arr;
use LuroWeb\KsefFoundry\Support\Redactor;

/**
 * Maps a non-2xx response to the exception hierarchy. Understands application/problem+json and the
 * legacy ExceptionResponse / TooManyRequestsResponse shapes. Never copies raw bodies into messages.
 */
final class ErrorMapper
{
    public function map(#[\SensitiveParameter] HttpResponse $response): ApiException
    {
        $status = $response->status;
        $data = $this->parse($response->body);
        $redactor = new Redactor();

        $codes = [];
        $details = [];
        $message = null;
        $traceId = null;

        if (isset($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $e) {
                if (!is_array($e)) {
                    continue;
                }
                if (isset($e['code']) && is_numeric($e['code'])) {
                    $codes[] = (int) $e['code'];
                }
                if (isset($e['description']) && is_string($e['description'])) {
                    $message ??= $e['description'];
                }
                foreach ((array) ($e['details'] ?? []) as $d) {
                    if (is_string($d)) {
                        $details[] = $d;
                    }
                }
            }
        }
        $legacy = Arr::arr(Arr::arr($data, 'exception'), 'exceptionDetailList');
        if ($legacy !== []) {
            foreach ($legacy as $e) {
                if (!is_array($e)) {
                    continue;
                }
                if (isset($e['exceptionCode']) && is_numeric($e['exceptionCode'])) {
                    $codes[] = (int) $e['exceptionCode'];
                }
                if (isset($e['exceptionDescription']) && is_string($e['exceptionDescription'])) {
                    $message ??= $e['exceptionDescription'];
                }
                foreach ((array) ($e['details'] ?? []) as $d) {
                    if (is_string($d)) {
                        $details[] = $d;
                    }
                }
            }
        }
        $legacy429 = $data['status'] ?? null;
        if (is_array($legacy429)) {
            foreach ((array) ($legacy429['details'] ?? []) as $d) {
                if (is_string($d)) {
                    $details[] = $d;
                }
            }
            if (isset($legacy429['description']) && is_string($legacy429['description'])) {
                $message ??= $legacy429['description'];
            }
        }
        if (isset($data['traceId']) && is_string($data['traceId'])) {
            $traceId = $data['traceId'];
        }
        if ($message === null) {
            foreach (['detail', 'title'] as $k) {
                if (isset($data[$k]) && is_string($data[$k]) && $data[$k] !== '') {
                    $message = $data[$k];
                    break;
                }
            }
        }
        $message ??= 'HTTP ' . $status;
        $message = $this->clip($redactor->redactString($message));
        $details = array_map(fn (string $d): string => $this->clip($redactor->redactString($d)), array_slice($details, 0, 20));
        $retryAfter = RetryPolicy::parseRetryAfter($response->header('Retry-After'));
        $problem = $redactor->redactArray($this->limit($data));
        if ($data === [] && $response->body !== '') {
            // Unparseable error body (not JSON): keep a short redacted prefix for diagnostics.
            $problem['rawBodyPrefix'] = $this->clip($redactor->redactString(substr($response->body, 0, 300)));
        }

        $full = sprintf('KSeF API error %d: %s', $status, $message);
        $args = [$full, $status, $codes, $details, $traceId, $problem, $retryAfter];

        return match (true) {
            $status === 400 => new ValidationException(...$args),
            $status === 401 => new AuthenticationException(...$args),
            $status === 403 => new AuthorizationException(...$args),
            $status === 404 => new NotFoundException(...$args),
            $status === 410 => new GoneException(...$args),
            $status === 429 => new RateLimitException(...$args),
            $status >= 500 => new ServerException(...$args),
            default => new ApiException(...$args),
        };
    }

    /** @return array<mixed> */
    private function parse(#[\SensitiveParameter] string $body): array
    {
        if ($body === '' || strlen($body) > 262_144) {
            return [];
        }
        $d = json_decode($body, true);

        return is_array($d) ? $d : [];
    }

    private function clip(string $s): string
    {
        return mb_strlen($s) > 500 ? mb_substr($s, 0, 500) . '...' : $s;
    }

    /**
     * @param array<mixed> $a
     * @return array<mixed>
     */
    private function limit(array $a, int $depth = 0): array
    {
        $out = [];
        foreach (array_slice($a, 0, 50, true) as $k => $v) {
            if (is_array($v)) {
                $out[$k] = $depth > 4 ? [] : $this->limit($v, $depth + 1);
            } elseif (is_string($v)) {
                $out[$k] = $this->clip($v);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
