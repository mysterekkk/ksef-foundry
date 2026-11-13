<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Http\Psr7;

use Psr\Http\Message\StreamInterface;

/** @internal */
trait MessageTrait
{
    private string $protocol = '1.1';

    /** @var array<string, list<string>> */
    private array $headers = [];

    /** @var array<string, string> lower-case => original name */
    private array $names = [];

    private StreamInterface $body;

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'headers' => (new \LuroWeb\KsefFoundry\Support\Redactor())->redactHeaders($this->headers),
            'body' => '[' . ($this->body->getSize() ?? 0) . ' bytes, not shown]',
        ];
    }

    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    public function withProtocolVersion(string $version): static
    {
        $c = clone $this;
        $c->protocol = $version;

        return $c;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->names[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        $k = $this->names[strtolower($name)] ?? null;

        return $k === null ? [] : $this->headers[$k];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): static
    {
        $c = clone $this;
        $c->unsetHeader($name);
        $c->names[strtolower($name)] = $name;
        $c->headers[$name] = $this->normalise($value);

        return $c;
    }

    public function withAddedHeader(string $name, $value): static
    {
        $c = clone $this;
        $existing = $c->names[strtolower($name)] ?? null;
        if ($existing === null) {
            $c->names[strtolower($name)] = $name;
            $c->headers[$name] = $this->normalise($value);
        } else {
            $c->headers[$existing] = array_merge($c->headers[$existing], $this->normalise($value));
        }

        return $c;
    }

    public function withoutHeader(string $name): static
    {
        $c = clone $this;
        $c->unsetHeader($name);

        return $c;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): static
    {
        $c = clone $this;
        $c->body = $body;

        return $c;
    }

    private function unsetHeader(string $name): void
    {
        $k = $this->names[strtolower($name)] ?? null;
        if ($k !== null) {
            unset($this->headers[$k], $this->names[strtolower($name)]);
        }
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalise(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [$value] as $v) {
            $out[] = trim(is_scalar($v) ? (string) $v : '', " \t");
        }

        return $out;
    }

    /** @param array<string, mixed> $headers */
    private function initHeaders(array $headers): void
    {
        foreach ($headers as $n => $v) {
            $this->names[strtolower((string) $n)] = (string) $n;
            $this->headers[(string) $n] = $this->normalise($v);
        }
    }
}
