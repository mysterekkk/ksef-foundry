<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Support;

use LuroWeb\KsefFoundry\Support\Redactor;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * PSR-3 bridge to the Laravel logger (any channel). The SDK already logs only method/path/status;
 * this decorator additionally runs message and context through the SDK Redactor (defence in depth).
 */
final class RedactingLogger extends AbstractLogger
{
    private readonly Redactor $redactor;

    public function __construct(private readonly LoggerInterface $inner)
    {
        $this->redactor = new Redactor();
    }

    /** @param array<mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->inner->log(
            $level,
            $this->redactor->redactString((string) $message),
            $this->redactor->redactArray($context),
        );
    }
}
