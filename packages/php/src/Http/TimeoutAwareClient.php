<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client whose per-request time limit can be set by the transport. The transport passes
 * min(requestTimeout, remaining overall deadline), so a hung request can never outlive the deadline.
 * Plain PSR-18 clients cannot do this and are therefore only accepted when supplied explicitly.
 */
interface TimeoutAwareClient extends ClientInterface
{
    public function sendWithTimeout(RequestInterface $request, float $timeoutSeconds): ResponseInterface;
}
