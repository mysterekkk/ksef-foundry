<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Http;

use LuroWeb\KsefFoundry\Support\Json;

final class HttpResponse
{
    /** @param array<string, list<string>> $headers lower-cased names */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        #[\SensitiveParameter] public readonly string $body,
    ) {
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'status' => $this->status,
            'headers' => (new \LuroWeb\KsefFoundry\Support\Redactor())->redactHeaders($this->headers),
            'body' => '[' . strlen($this->body) . ' bytes, not shown]',
        ];
    }

    public function header(string $name): ?string
    {
        $v = $this->headers[strtolower($name)] ?? null;

        return $v === null || $v === [] ? null : implode(', ', $v);
    }

    /** @return array<mixed> */
    public function json(): array
    {
        return Json::decodeArray($this->body);
    }
}
