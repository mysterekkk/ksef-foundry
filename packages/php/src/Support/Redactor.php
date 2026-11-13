<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Support;

/**
 * Removes secrets from strings and structures before logging or putting them in diagnostics.
 */
final class Redactor
{
    public const MASK = '[REDACTED]';

    private const SENSITIVE_KEYS = [
        'authorization', 'token', 'accesstoken', 'refreshtoken', 'authenticationtoken',
        'encryptedtoken', 'encryptedsymmetrickey', 'initializationvector', 'encryptedinvoicecontent',
        'privatekey', 'passphrase', 'password', 'secret', 'symmetrickey', 'key', 'iv',
        'ksefToken', 'cookie', 'set-cookie',
    ];

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function redactArray(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && $this->isSensitiveKey($k)) {
                $out[$k] = self::MASK;
            } elseif (is_array($v)) {
                $out[$k] = $this->redactArray($v);
            } elseif (is_string($v)) {
                $out[$k] = $this->redactString($v);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * @param array<string, list<string>|string> $headers
     * @return array<string, string>
     */
    public function redactHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[$name] = $this->isSensitiveKey($name)
                ? self::MASK
                : $this->redactString(is_array($value) ? implode(', ', $value) : $value);
        }

        return $out;
    }

    public function redactString(string $text): string
    {
        $text = (string) preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer ' . self::MASK, $text);
        $text = (string) preg_replace('/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]*/', self::MASK, $text);
        $text = (string) preg_replace(
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
            self::MASK,
            $text
        );
        $keys = implode('|', array_map(static fn (string $k): string => preg_quote($k, '/'), self::SENSITIVE_KEYS));

        return (string) preg_replace(
            '/("(?:' . $keys . ')"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i',
            '$1"' . self::MASK . '"',
            $text
        );
    }

    private function isSensitiveKey(string $key): bool
    {
        return in_array(strtolower($key), array_map('strtolower', self::SENSITIVE_KEYS), true);
    }
}
