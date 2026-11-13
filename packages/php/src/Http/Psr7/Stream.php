<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Http\Psr7;

use Psr\Http\Message\StreamInterface;

/** Minimal PSR-7 stream over php://temp. */
final class Stream implements StreamInterface
{
    /** @var resource|null */
    private $resource;

    public function __construct(string $content = '')
    {
        $r = fopen('php://temp', 'r+');
        if ($r === false) {
            throw new \RuntimeException('Cannot open php://temp.');
        }
        fwrite($r, $content);
        rewind($r);
        $this->resource = $r;
    }

    /** @return resource */
    private function res()
    {
        if ($this->resource === null) {
            throw new \RuntimeException('Stream is detached.');
        }

        return $this->resource;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['size' => $this->getSize(), 'content' => '[not shown]'];
    }

    public function __toString(): string
    {
        try {
            if ($this->resource === null) {
                return '';
            }
            $this->rewind();

            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->resource !== null) {
            fclose($this->resource);
            $this->resource = null;
        }
    }

    public function detach()
    {
        $r = $this->resource;
        $this->resource = null;

        return $r;
    }

    public function getSize(): ?int
    {
        if ($this->resource === null) {
            return null;
        }
        $st = fstat($this->resource);

        return $st === false ? null : $st['size'];
    }

    public function tell(): int
    {
        $p = ftell($this->res());
        if ($p === false) {
            throw new \RuntimeException('tell failed.');
        }

        return $p;
    }

    public function eof(): bool
    {
        return $this->resource === null || feof($this->resource);
    }

    public function isSeekable(): bool
    {
        return $this->resource !== null;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (fseek($this->res(), $offset, $whence) !== 0) {
            throw new \RuntimeException('seek failed.');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->resource !== null;
    }

    public function write(string $string): int
    {
        $n = fwrite($this->res(), $string);
        if ($n === false) {
            throw new \RuntimeException('write failed.');
        }

        return $n;
    }

    public function isReadable(): bool
    {
        return $this->resource !== null;
    }

    public function read(int $length): string
    {
        if ($length < 1) {
            return '';
        }
        $d = fread($this->res(), $length);
        if ($d === false) {
            throw new \RuntimeException('read failed.');
        }

        return $d;
    }

    public function getContents(): string
    {
        $c = stream_get_contents($this->res());
        if ($c === false) {
            throw new \RuntimeException('read failed.');
        }

        return $c;
    }

    public function getMetadata(?string $key = null): mixed
    {
        if ($this->resource === null) {
            return $key === null ? [] : null;
        }
        $m = stream_get_meta_data($this->resource);

        return $key === null ? $m : ($m[$key] ?? null);
    }
}
