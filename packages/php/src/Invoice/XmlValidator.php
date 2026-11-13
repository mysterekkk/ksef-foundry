<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Invoice;

/**
 * Validates invoice XML against the official FA(3) XSD (bundled, resolved to local paths,
 * see resources/xsd/FA3) using real libxml. Never touches the network and never expands entities.
 *
 * KSeF technical rules (docs: faktury/weryfikacja-faktury.md) enforced before the XSD:
 * UTF-8 without BOM, prolog may not declare another encoding, no processing instructions,
 * no DOCTYPE/entities, none of the listed non-recommended Unicode characters, size limit.
 */
final class XmlValidator
{
    public const DEFAULT_MAX_BYTES = 3_000_000;
    public const SCHEMA_FILE = 'schemat_FA3_v1-0E.xsd';

    private readonly string $schemaPath;

    private static ?string $forbiddenPattern = null;

    public function __construct(
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        ?string $schemaPath = null,
    ) {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('maxBytes must be >= 1');
        }
        $this->schemaPath = $schemaPath ?? self::bundledSchemaPath();
        if (!is_file($this->schemaPath)) {
            throw new \RuntimeException('FA(3) XSD bundle not found at ' . $this->schemaPath);
        }
    }

    /** Directory of the XSD bundle shipped with the package (works from vendor/ too). */
    public static function bundledSchemaDir(): string
    {
        return dirname(__DIR__, 2) . '/resources/xsd/FA3';
    }

    public static function bundledSchemaPath(): string
    {
        return self::bundledSchemaDir() . '/' . self::SCHEMA_FILE;
    }

    public function validateFa3(#[\SensitiveParameter] string $xml): ValidationResult
    {
        return $this->run($xml, true);
    }

    /**
     * Safety checks only (no XSD): size, UTF-8/BOM/encoding, DOCTYPE/entities, processing instructions, forbidden
     * characters. Used by the sender even when schema validation is switched off.
     */
    public function validateSafety(#[\SensitiveParameter] string $xml): ValidationResult
    {
        return $this->run($xml, false);
    }

    /** @throws InvoiceValidationException */
    public function assertSafe(#[\SensitiveParameter] string $xml): void
    {
        $r = $this->validateSafety($xml);
        if (!$r->isValid()) {
            throw new InvoiceValidationException('Unsafe invoice XML: ' . $r->summary(), $r->issues);
        }
    }

    private function run(#[\SensitiveParameter] string $xml, bool $withSchema): ValidationResult
    {
        $pre = $this->preChecks($xml);
        if ($pre !== []) {
            return new ValidationResult($pre);
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $issues = [];
        try {
            $doc = new \DOMDocument();
            $doc->resolveExternals = false;
            $doc->substituteEntities = false;
            $doc->validateOnParse = false;
            $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING);
            $issues = self::collect('xml');
            if (!$loaded || $issues !== []) {
                return new ValidationResult(
                    $issues === [] ? [
                        new ValidationIssue(0, 0, ValidationIssue::LEVEL_FATAL, 'XML is not well-formed.', 'xml'),
                    ] : $issues,
                );
            }
            if ($doc->doctype !== null) {
                return new ValidationResult(
                    [new ValidationIssue(0, 0, ValidationIssue::LEVEL_FATAL, 'DOCTYPE is not allowed.', 'doctype')],
                );
            }
            $pis = $this->findProcessingInstructions($doc);
            if ($pis !== []) {
                return new ValidationResult($pis);
            }
            // character references (e.g. &#x85;) only become visible after parsing
            $decoded = $doc->documentElement->textContent ?? '';
            if (preg_match(self::forbiddenCharsPattern(), $decoded, $fm) === 1) {
                return new ValidationResult(
                    [
                        new ValidationIssue(
                            0,
                            0,
                            ValidationIssue::LEVEL_FATAL,
                            sprintf('Forbidden character U+%04X (via character reference).', mb_ord($fm[0], 'UTF-8')),
                            'forbidden-char',
                        ),
                    ],
                );
            }
            if (!$withSchema) {
                return new ValidationResult([]);
            }
            libxml_clear_errors();
            $ok = $doc->schemaValidate($this->schemaPath);
            $issues = self::collect('xsd');
            if (!$ok && $issues === []) {
                $issues[] = new ValidationIssue(0, 0, ValidationIssue::LEVEL_ERROR, 'Schema validation failed.', 'xsd');
            }
            return new ValidationResult($issues);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @throws InvoiceValidationException */
    public function assertValid(#[\SensitiveParameter] string $xml): void
    {
        $r = $this->validateFa3($xml);
        if (!$r->isValid()) {
            throw new InvoiceValidationException('FA(3) XML is invalid: ' . $r->summary(), $r->issues);
        }
    }

    /** @return list<ValidationIssue> */
    private function preChecks(#[\SensitiveParameter] string $xml): array
    {
        $len = strlen($xml);
        if ($len > $this->maxBytes) {
            return [
                new ValidationIssue(
                    0,
                    0,
                    ValidationIssue::LEVEL_FATAL,
                    sprintf('Document is %d bytes; limit is %d.', $len, $this->maxBytes),
                    'size',
                ),
            ];
        }
        if ($len === 0) {
            return [new ValidationIssue(0, 0, ValidationIssue::LEVEL_FATAL, 'Document is empty.', 'xml')];
        }
        $issues = [];
        if (str_starts_with($xml, "\xEF\xBB\xBF")) {
            $issues[] = new ValidationIssue(
                1,
                1,
                ValidationIssue::LEVEL_FATAL,
                'UTF-8 BOM (EF BB BF) is not allowed.',
                'bom',
            );
        } elseif (
            str_starts_with(
                $xml,
                "\xFE\xFF",
            ) || str_starts_with($xml, "\xFF\xFE") || str_starts_with($xml, "\x00<") || str_starts_with($xml, "<\x00")
        ) {
            $issues[] = new ValidationIssue(
                1,
                1,
                ValidationIssue::LEVEL_FATAL,
                'UTF-16 content is not allowed; UTF-8 required.',
                'encoding',
            );
        }
        if (preg_match('//u', $xml) !== 1) {
            $issues[] = new ValidationIssue(0, 0, ValidationIssue::LEVEL_FATAL, 'Document is not valid UTF-8.', 'utf8');
            return $issues;
        }
        $decl = '/\A(?:\xEF\xBB\xBF)?<\?xml\s[^?]*?encoding\s*=\s*(["\'])(.*?)\1/s';
        if (preg_match($decl, $xml, $m) === 1 && strtolower($m[2]) !== 'utf-8') {
            $issues[] = new ValidationIssue(
                1,
                1,
                ValidationIssue::LEVEL_FATAL,
                sprintf('Prolog declares encoding "%s"; only UTF-8 is allowed.', $m[2]),
                'encoding',
            );
        }
        if (preg_match('/<!(?:DOCTYPE|ENTITY|ELEMENT|ATTLIST|NOTATION)/i', $xml, $m, PREG_OFFSET_CAPTURE) === 1) {
            [$l, $c] = self::position($xml, $m[0][1]);
            $issues[] = new ValidationIssue(
                $l,
                $c,
                ValidationIssue::LEVEL_FATAL,
                'DOCTYPE / entity declarations are not allowed.',
                'doctype',
            );
        }
        if (preg_match(self::forbiddenCharsPattern(), $xml, $m, PREG_OFFSET_CAPTURE) === 1) {
            [$l, $c] = self::position($xml, $m[0][1]);
            $issues[] = new ValidationIssue(
                $l,
                $c,
                ValidationIssue::LEVEL_FATAL,
                sprintf('Forbidden character U+%04X.', mb_ord($m[0][0], 'UTF-8')),
                'forbidden-char',
            );
        }
        return $issues;
    }

    /** @return list<ValidationIssue> */
    private function findProcessingInstructions(\DOMDocument $doc): array
    {
        $xp = new \DOMXPath($doc);
        $nodes = $xp->query('//processing-instruction()');
        $out = [];
        if ($nodes !== false) {
            foreach ($nodes as $n) {
                if (!$n instanceof \DOMNode) {
                    continue;
                }
                $out[] = new ValidationIssue(
                    $n->getLineNo(),
                    0,
                    ValidationIssue::LEVEL_FATAL,
                    'Processing instructions are not allowed.',
                    'processing-instruction',
                );
            }
        }
        return $out;
    }

    /** @return list<ValidationIssue> */
    private static function collect(string $code): array
    {
        $out = [];
        foreach (libxml_get_errors() as $e) {
            $level = match ($e->level) {
                LIBXML_ERR_WARNING => ValidationIssue::LEVEL_WARNING,
                LIBXML_ERR_ERROR => ValidationIssue::LEVEL_ERROR,
                default => ValidationIssue::LEVEL_FATAL,
            };
            $out[] = new ValidationIssue(max(0, $e->line), max(0, $e->column), $level, trim($e->message), $code);
        }
        libxml_clear_errors();
        return $out;
    }

    /** @return array{int,int} */
    private static function position(string $xml, int $byteOffset): array
    {
        $before = substr($xml, 0, $byteOffset);
        $line = substr_count($before, "\n") + 1;
        $nl = strrpos($before, "\n");
        return [$line, $byteOffset - ($nl === false ? -1 : $nl)];
    }

    /** PCRE (/u) matching any character KSeF/XML 1.0 forbid (controls except TAB/LF/CR, listed ranges). */
    public static function forbiddenCharsPattern(): string
    {
        $p = self::$forbiddenPattern;
        if ($p === null) {
            $planes = '';
            for ($i = 1; $i <= 16; $i++) {
                $planes .= sprintf('\x{%X}\x{%X}', ($i << 16) | 0xFFFE, ($i << 16) | 0xFFFF);
            }
            // XML 1.0 illegal controls + KSeF list: 7F-84, 86-9F, FDD0-FDEF, plane-end non-characters
            $p = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x{7F}-\x{84}\x{86}-\x{9F}'
                . '\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}' . $planes . ']/u';
            self::$forbiddenPattern = $p;
        }
        return $p;
    }
}
