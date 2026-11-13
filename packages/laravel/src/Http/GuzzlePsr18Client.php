<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface as GuzzleClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 wrapper over Guzzle with the options the SDK contract demands, fixed and not overridable:
 * redirects are never followed, TLS verification is on (TLS >= 1.2 when curl handler is used),
 * HTTP errors are returned as responses (the SDK maps them), timeouts are enforced.
 *
 * Not enforced (unlike the built-in curl client): maximum response size. Documented limitation.
 */
final class GuzzlePsr18Client implements ClientInterface
{
    private readonly GuzzleClient $guzzle;

    public function __construct(float $timeout = 30.0, float $connectTimeout = 10.0, ?GuzzleClient $guzzle = null)
    {
        $this->guzzle = $guzzle ?? new Client(self::options($timeout, $connectTimeout));
    }

    /** @return array<string, mixed> */
    public static function options(float $timeout, float $connectTimeout): array
    {
        $options = [
            'allow_redirects' => false,
            'verify' => true,
            'http_errors' => false,
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'decode_content' => true,
        ];
        if (defined('CURLOPT_SSLVERSION') && defined('CURL_SSLVERSION_TLSv1_2')) {
            $options['curl'] = [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2];
        }

        return $options;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // Per-request options are pinned again so a custom Guzzle instance cannot weaken them.
        return $this->guzzle->send($request, [
            'allow_redirects' => false,
            'verify' => true,
            'http_errors' => false,
        ]);
    }
}
