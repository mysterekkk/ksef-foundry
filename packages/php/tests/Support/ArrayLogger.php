<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Support;

use Psr\Log\AbstractLogger;

/** Real PSR-3 logger that records everything it is given (used to prove nothing secret is logged). */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    public function dump(): string
    {
        return json_encode($this->records, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
    }
}
