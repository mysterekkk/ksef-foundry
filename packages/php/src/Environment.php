<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry;

use LuroWeb\KsefFoundry\Exception\ConfigurationException;

/**
 * KSeF environment. Default everywhere is TEST; production must be chosen explicitly.
 */
final class Environment
{
    public const TEST_URL = 'https://api-test.ksef.mf.gov.pl/v2';
    public const DEMO_URL = 'https://api-demo.ksef.mf.gov.pl/v2';
    public const PRODUCTION_URL = 'https://api.ksef.mf.gov.pl/v2';

    private function __construct(
        public readonly string $name,
        public readonly string $baseUrl,
    ) {
    }

    public static function test(): self
    {
        return new self('TEST', self::TEST_URL);
    }

    public static function demo(): self
    {
        return new self('DEMO', self::DEMO_URL);
    }

    public static function production(): self
    {
        return new self('PRODUCTION', self::PRODUCTION_URL);
    }

    /**
     * UNSAFE FOR REAL DATA. Only for fault-injection servers and future MF environments.
     * Must be https, except loopback hosts (127.0.0.1, ::1, localhost) which may use http.
     */
    public static function custom(string $baseUrl): self
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ConfigurationException('Custom environment base URL is not a valid absolute URL.');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        $loopback = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $loopback)) {
            throw new ConfigurationException('Custom environment must use https (http only for loopback hosts).');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationException('Custom environment base URL must not contain credentials, query or fragment.');
        }

        return new self('CUSTOM', rtrim($baseUrl, '/'));
    }

    public function isTest(): bool
    {
        return $this->name === 'TEST';
    }

    public function isProduction(): bool
    {
        return $this->name === 'PRODUCTION';
    }

    public function host(): string
    {
        return strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST));
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name && $this->baseUrl === $other->baseUrl;
    }
}
