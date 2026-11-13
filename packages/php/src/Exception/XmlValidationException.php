<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

/** XML is not well-formed, unsafe (DOCTYPE/PI) or violates the XSD. */
class XmlValidationException extends KsefException
{
    /**
     * @param list<array{line: int, column: int, message: string, level: string}> $errors
     */
    public function __construct(string $message, private readonly array $errors = [])
    {
        parent::__construct($message);
    }

    public function category(): string
    {
        return 'xml_validation';
    }

    /** @return list<array{line: int, column: int, message: string, level: string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function diagnostics(): array
    {
        return parent::diagnostics() + ['errors' => $this->errors];
    }
}
