<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Http\Psr7;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/** Built-in PSR-17 factories (used when no other implementation is injected). */
final class Factory implements RequestFactoryInterface, StreamFactoryInterface, UriFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request($method, $uri instanceof UriInterface ? $uri : new Uri($uri));
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return new Stream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        $c = file_get_contents($filename);
        if ($c === false) {
            throw new \RuntimeException('Cannot read file.');
        }

        return new Stream($c);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        $c = stream_get_contents($resource);

        return new Stream($c === false ? '' : $c);
    }

    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }
}
