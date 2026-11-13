<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Invoice;

use LuroWeb\KsefFoundry\Exception\XmlValidationException;

/**
 * Raised by {@see XmlValidator::assertValid()} and by the builder's XSD self-check.
 * Extends the SDK's XmlValidationException (contract section 7); `issues()` keeps the rule codes.
 */
class InvoiceValidationException extends XmlValidationException
{
    /** @param list<ValidationIssue> $issues */
    public function __construct(string $message, private readonly array $issues = [])
    {
        parent::__construct($message, array_map(
            static fn (ValidationIssue $i): array => [
                'line' => $i->line,
                'column' => $i->column,
                'message' => $i->message,
                'level' => $i->level,
            ],
            $issues,
        ));
    }

    /** @return list<ValidationIssue> */
    public function issues(): array
    {
        return $this->issues;
    }
}
