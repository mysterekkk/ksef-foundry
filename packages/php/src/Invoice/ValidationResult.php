<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Invoice;

final class ValidationResult
{
    /** @param list<ValidationIssue> $issues */
    public function __construct(public readonly array $issues = [])
    {
    }

    public function isValid(): bool
    {
        foreach ($this->issues as $i) {
            if ($i->level !== ValidationIssue::LEVEL_WARNING) {
                return false;
            }
        }
        return true;
    }

    /** @return list<ValidationIssue> */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (ValidationIssue $i): bool => $i->level !== ValidationIssue::LEVEL_WARNING,
        ));
    }

    /** @return list<string> distinct rule codes of the errors */
    public function codes(): array
    {
        return array_values(
            array_unique(array_map(static fn (ValidationIssue $i): string => $i->code, $this->errors())),
        );
    }

    public function summary(int $max = 5): string
    {
        $parts = [];
        foreach (array_slice($this->errors(), 0, $max) as $i) {
            $parts[] = sprintf('[%s] line %d: %s', $i->code, $i->line, $i->message);
        }
        $more = count($this->errors()) - count($parts);
        return implode('; ', $parts) . ($more > 0 ? sprintf(' (+%d more)', $more) : '');
    }
}
