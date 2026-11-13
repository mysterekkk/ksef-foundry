<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Support;

/**
 * Reads schemas/openapi/test.json (official contract) and validates data against its schemas with a small
 * JSON-Schema subset: $ref, allOf, type, nullable, enum, required, properties, items, min/maxLength, pattern, minimum.
 */
final class OpenApi
{
    /** @var array<string, mixed> */
    private array $doc;

    private function __construct(string $path)
    {
        $this->doc = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function load(): ?self
    {
        $path = dirname(__DIR__, 4) . '/schemas/openapi/test.json';

        return is_file($path) ? new self($path) : null;
    }

    /**
     * The `example` of the response media type of an operation.
     *
     * @return array<mixed>|null
     */
    public function responseExample(string $method, string $path, string $status, string $mediaType = 'application/json'): ?array
    {
        $ex = $this->doc['paths'][$path][strtolower($method)]['responses'][$status]['content'][$mediaType]['example'] ?? null;

        return is_array($ex) ? $ex : null;
    }

    /**
     * Named examples (`examples` key) of a response media type.
     *
     * @return array<string, array<mixed>>
     */
    public function responseExamples(string $method, string $path, string $status, string $mediaType = 'application/json'): array
    {
        $out = [];
        $all = $this->doc['paths'][$path][strtolower($method)]['responses'][$status]['content'][$mediaType]['examples'] ?? [];
        foreach ($all as $name => $e) {
            if (isset($e['value']) && is_array($e['value'])) {
                $out[(string) $name] = $e['value'];
            }
        }

        return $out;
    }

    /** @return array<mixed>|null */
    public function requestExample(string $method, string $path): ?array
    {
        $ex = $this->doc['paths'][$path][strtolower($method)]['requestBody']['content']['application/json']['example'] ?? null;

        return is_array($ex) ? $ex : null;
    }

    /** @return array<string, mixed> */
    public function schema(string $name): array
    {
        return $this->doc['components']['schemas'][$name] ?? throw new \RuntimeException('Unknown schema ' . $name);
    }

    /** @return list<string> */
    public function enumValues(string $name): array
    {
        return array_values(array_map('strval', $this->schema($name)['enum'] ?? []));
    }

    /**
     * @param array<string, mixed> $schema
     * @return list<string> violations (empty = valid)
     */
    public function validate(mixed $data, array $schema, string $at = '$'): array
    {
        if (isset($schema['$ref'])) {
            return $this->validate($data, $this->schema(basename((string) $schema['$ref'])), $at);
        }
        $errors = [];
        foreach ($schema['allOf'] ?? [] as $sub) {
            $errors = array_merge($errors, $this->validate($data, $sub, $at));
        }
        if ($data === null) {
            return ($schema['nullable'] ?? false) || isset($schema['allOf']) && $this->anyNullable($schema) ? $errors : array_merge($errors, [$at . ': null not allowed']);
        }
        $type = $schema['type'] ?? null;
        $ok = match ($type) {
            'string' => is_string($data),
            'integer' => is_int($data),
            'number' => is_int($data) || is_float($data),
            'boolean' => is_bool($data),
            'array' => is_array($data) && array_is_list($data),
            'object' => is_array($data) && ($data === [] || !array_is_list($data)),
            default => true,
        };
        if (!$ok) {
            return array_merge($errors, [$at . ': expected ' . $type]);
        }
        if (isset($schema['enum']) && !in_array($data, $schema['enum'], true)) {
            $errors[] = $at . ': value not in enum';
        }
        if (is_string($data)) {
            if (isset($schema['minLength']) && mb_strlen($data) < $schema['minLength']) {
                $errors[] = $at . ': shorter than minLength';
            }
            if (isset($schema['maxLength']) && mb_strlen($data) > $schema['maxLength']) {
                $errors[] = $at . ': longer than maxLength';
            }
            if (isset($schema['pattern']) && preg_match('/' . str_replace('/', '\/', (string) $schema['pattern']) . '/', $data) !== 1) {
                $errors[] = $at . ': pattern mismatch';
            }
        }
        if ((is_int($data) || is_float($data)) && isset($schema['minimum']) && $data < $schema['minimum']) {
            $errors[] = $at . ': below minimum';
        }
        if (is_array($data) && $type === 'object') {
            foreach ($schema['required'] ?? [] as $req) {
                if (!array_key_exists($req, $data)) {
                    $errors[] = $at . '.' . $req . ': required';
                }
            }
            foreach ($schema['properties'] ?? [] as $prop => $sub) {
                if (array_key_exists($prop, $data)) {
                    $errors = array_merge($errors, $this->validate($data[$prop], $sub, $at . '.' . $prop));
                }
            }
        }
        if (is_array($data) && $type === 'array' && isset($schema['items'])) {
            foreach ($data as $i => $item) {
                $errors = array_merge($errors, $this->validate($item, $schema['items'], $at . '[' . $i . ']'));
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $schema */
    private function anyNullable(array $schema): bool
    {
        foreach ($schema['allOf'] ?? [] as $sub) {
            if (isset($sub['$ref']) && ($this->schema(basename((string) $sub['$ref']))['nullable'] ?? false)) {
                return true;
            }
        }

        return false;
    }
}
