<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Http;

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use LuroWeb\KsefFoundry\Http\Psr7\Request;
use LuroWeb\KsefFoundry\Http\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Hardened adapter around Guzzle (opt-in via KsefClientBuilder::discoverHttpClient()). Every call is sent with
 * redirects disabled, TLS verification on, TLS >= 1.2, an enforced per-request time limit (the transport passes the
 * remaining overall deadline) and a response size cap; failures are re-thrown as sanitised HttpClientError (no
 * request, no headers, no response body).
 */
final class GuzzleHttpClient implements TimeoutAwareClient
{
    public function __construct(
        private readonly GuzzleClientInterface $guzzle,
        private readonly float $timeout = 30.0,
        private readonly float $connectTimeout = 10.0,
        private readonly int $maxResponseBytes = 67_108_864,
    ) {
    }

    public static function hardened(float $timeout = 30.0): self
    {
        return new self(new \GuzzleHttp\Client(), $timeout);
    }

    public function sendRequest(#[\SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        return $this->sendWithTimeout($request, $this->timeout);
    }

    public function sendWithTimeout(#[\SensitiveParameter] RequestInterface $request, float $timeoutSeconds): ResponseInterface
    {
        $options = [
            'allow_redirects' => false,
            'http_errors' => false,
            'verify' => true,
            'timeout' => $timeoutSeconds,
            'connect_timeout' => min($this->connectTimeout, $timeoutSeconds),
            'decode_content' => true,
            'curl' => [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2],
        ];
        $safe = new Request($request->getMethod(), $request->getUri());
        try {
            $resp = $this->guzzle->send($request, $options);
            $stream = $resp->getBody();
            $content = '';
            while (!$stream->eof()) {
                $content .= $stream->read(65536);
                if (strlen($content) > $this->maxResponseBytes) {
                    throw new HttpClientError('HTTP transport error (response exceeds the size cap).', $safe);
                }
            }
        } catch (HttpClientError $e) {
            throw $e;
        } catch (GuzzleException | \RuntimeException $e) {
            $ctx = $e instanceof \GuzzleHttp\Exception\RequestException || $e instanceof \GuzzleHttp\Exception\ConnectException
                ? $e->getHandlerContext()
                : [];
            $errno = isset($ctx['errno']) && is_int($ctx['errno']) ? $ctx['errno'] : null;
            throw new HttpClientError(
                sprintf('HTTP transport error (guzzle %s, curl errno %s)', (new \ReflectionClass($e))->getShortName(), $errno ?? '?'),
                $safe,
                $errno === CURLE_OPERATION_TIMEDOUT,
            );
        }
        $headers = [];
        foreach ($resp->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values(array_map('strval', $values));
        }

        return new Response($resp->getStatusCode(), $headers, $content, $resp->getReasonPhrase());
    }
}
