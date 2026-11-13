<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Http\Psr7;

use Psr\Http\Message\UriInterface;

/** Minimal PSR-7 URI used by the built-in client. */
final class Uri implements UriInterface
{
    private string $scheme = '';
    private string $userInfo = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            return;
        }
        $p = parse_url($uri);
        if ($p === false) {
            throw new \InvalidArgumentException('Invalid URI.');
        }
        $this->scheme = strtolower($p['scheme'] ?? '');
        $this->host = strtolower($p['host'] ?? '');
        $this->port = $p['port'] ?? null;
        $this->path = $p['path'] ?? '';
        $this->query = $p['query'] ?? '';
        $this->fragment = $p['fragment'] ?? '';
        $this->userInfo = isset($p['user']) ? $p['user'] . (isset($p['pass']) ? ':' . $p['pass'] : '') : '';
        if ($this->port !== null && $this->isDefaultPort()) {
            $this->port = null;
        }
    }

    private function isDefaultPort(): bool
    {
        return ($this->scheme === 'https' && $this->port === 443) || ($this->scheme === 'http' && $this->port === 80);
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }
        $host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;

        return ($this->userInfo !== '' ? $this->userInfo . '@' : '') . $host
            . ($this->port !== null ? ':' . $this->port : '');
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function withScheme(string $scheme): UriInterface
    {
        $c = clone $this;
        $c->scheme = strtolower($scheme);

        return $c;
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $c = clone $this;
        $c->userInfo = $user . ($password !== null && $password !== '' ? ':' . $password : '');

        return $c;
    }

    public function withHost(string $host): UriInterface
    {
        $c = clone $this;
        $c->host = strtolower($host);

        return $c;
    }

    public function withPort(?int $port): UriInterface
    {
        $c = clone $this;
        $c->port = $port;

        return $c;
    }

    public function withPath(string $path): UriInterface
    {
        $c = clone $this;
        $c->path = $path;

        return $c;
    }

    public function withQuery(string $query): UriInterface
    {
        $c = clone $this;
        $c->query = ltrim($query, '?');

        return $c;
    }

    public function withFragment(string $fragment): UriInterface
    {
        $c = clone $this;
        $c->fragment = ltrim($fragment, '#');

        return $c;
    }

    public function __toString(): string
    {
        $s = '';
        if ($this->scheme !== '') {
            $s .= $this->scheme . ':';
        }
        $a = $this->getAuthority();
        if ($a !== '') {
            $s .= '//' . $a;
        }
        $s .= $this->path;
        if ($this->query !== '') {
            $s .= '?' . $this->query;
        }
        if ($this->fragment !== '') {
            $s .= '#' . $this->fragment;
        }

        return $s;
    }
}
