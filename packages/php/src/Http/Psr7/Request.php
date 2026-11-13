<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Http\Psr7;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

final class Request implements RequestInterface
{
    use MessageTrait;

    private ?string $target = null;

    public function __construct(private string $method, private UriInterface $uri)
    {
        $this->body = new Stream();
        if ($uri->getHost() !== '') {
            $this->initHeaders(['Host' => $this->hostHeader($uri)]);
        }
    }

    private function hostHeader(UriInterface $uri): string
    {
        return $uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : '');
    }

    public function getRequestTarget(): string
    {
        if ($this->target !== null) {
            return $this->target;
        }
        $t = $this->uri->getPath() === '' ? '/' : $this->uri->getPath();

        return $this->uri->getQuery() !== '' ? $t . '?' . $this->uri->getQuery() : $t;
    }

    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        $c = clone $this;
        $c->target = $requestTarget;

        return $c;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): RequestInterface
    {
        $c = clone $this;
        $c->method = $method;

        return $c;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        $c = clone $this;
        $c->uri = $uri;
        if ((!$preserveHost || !$c->hasHeader('Host')) && $uri->getHost() !== '') {
            $c = $c->withHeader('Host', $this->hostHeader($uri));
        }

        return $c;
    }
}
