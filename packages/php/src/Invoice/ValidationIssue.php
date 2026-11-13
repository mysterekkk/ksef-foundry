<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Invoice;

/**
 * One validation finding. `line`/`column` are 0 when not applicable.
 * `code` is a stable machine-readable rule id for pre-parse checks
 * ("size", "bom", "encoding", "utf8", "forbidden-char", "doctype", "processing-instruction", "xml", "xsd").
 */
final class ValidationIssue implements \JsonSerializable
{
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_FATAL = 'fatal';

    public function __construct(
        public readonly int $line,
        public readonly int $column,
        public readonly string $level,
        public readonly string $message,
        public readonly string $code = 'xsd',
    ) {
    }

    /** @return array{line: int, column: int, level: string, message: string, code: string} */
    public function jsonSerialize(): array
    {
        return [
            'line' => $this->line,
            'column' => $this->column,
            'level' => $this->level,
            'message' => $this->message,
            'code' => $this->code,
        ];
    }
}
