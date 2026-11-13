<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Session;

final class FormCode
{
    public function __construct(
        public readonly string $systemCode,
        public readonly string $schemaVersion,
        public readonly string $value,
    ) {
    }

    /** FA(3), exactly as in the OpenAPI examples: "FA (3)", "1-0E", "FA". */
    public static function fa3(): self
    {
        return new self('FA (3)', '1-0E', 'FA');
    }

    /** @return array{systemCode: string, schemaVersion: string, value: string} */
    public function toArray(): array
    {
        return ['systemCode' => $this->systemCode, 'schemaVersion' => $this->schemaVersion, 'value' => $this->value];
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            \LuroWeb\KsefFoundry\Support\Arr::string($a, 'systemCode'),
            \LuroWeb\KsefFoundry\Support\Arr::string($a, 'schemaVersion'),
            \LuroWeb\KsefFoundry\Support\Arr::string($a, 'value'),
        );
    }
}
