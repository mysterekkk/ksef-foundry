<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel;

use Illuminate\Contracts\Config\Repository;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\KsefClient;

/**
 * Named connections (config('ksef.connections')) and runtime tenants (credentials from your DB).
 * Each client has its own credentials and therefore its own token cache entry (SDK tenant key).
 *
 * @mixin KsefClient
 */
final class KsefManager
{
    private const MAX_TENANT_CLIENTS = 32;

    /** @var array<string, KsefClient> */
    private array $connections = [];

    /** @var array<string, KsefClient> */
    private array $tenants = [];

    /** @var (\Closure(string): (array<string, mixed>|null))|null */
    private ?\Closure $resolver = null;

    public function __construct(
        private readonly Repository $config,
        private readonly KsefClientFactory $factory,
    ) {
    }

    public function defaultConnectionName(): string
    {
        $name = $this->config->get('ksef.default', 'default');

        return is_string($name) && $name !== '' ? $name : 'default';
    }

    /** Client of a named connection (memoised); null = default connection. */
    public function connection(?string $name = null): KsefClient
    {
        $name ??= $this->defaultConnectionName();

        return $this->connections[$name] ??= $this->factory->make($this->connectionConfig($name), $this->global());
    }

    /**
     * Client for a tenant supplied at runtime (same keys as a config connection: auth, context_value, token, ...).
     * Memoised per configuration fingerprint, so two tenants never share a client or tokens.
     *
     * @param array<string, mixed> $config
     */
    public function tenant(#[\SensitiveParameter] array $config): KsefClient
    {
        $id = hash('sha256', serialize($config)); // serialize() never throws, so no secret can reach a trace
        if (!isset($this->tenants[$id])) {
            if (count($this->tenants) >= self::MAX_TENANT_CLIENTS) {
                array_shift($this->tenants);
            }
            $this->tenants[$id] = $this->factory->make($config, $this->global());
        }

        return $this->tenants[$id];
    }

    /**
     * Resolves connection names that are not in config (e.g. a tenant id) from your own storage.
     * The closure returns a connection config array or null.
     *
     * @param \Closure(string): (array<string, mixed>|null) $resolver
     */
    public function resolveUsing(\Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    public function hasConnection(string $name): bool
    {
        return is_array($this->config->get('ksef.connections.' . $name)) || $this->resolver !== null;
    }

    /** Drops the memoised client (e.g. after rotating a token). */
    public function forget(string $name): void
    {
        unset($this->connections[$name]);
    }

    public function purge(): void
    {
        $this->connections = [];
        $this->tenants = [];
    }

    /** @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->connection()->{$method}(...$arguments);
    }

    /** @return array<string, mixed> */
    private function connectionConfig(string $name): array
    {
        $c = $this->config->get('ksef.connections.' . $name);
        if (!is_array($c) && $this->resolver !== null) {
            $c = ($this->resolver)($name);
        }
        if (!is_array($c)) {
            throw new ConfigurationException(sprintf('KSeF connection "%s" is not defined.', $name));
        }

        /** @var array<string, mixed> $c */
        return $c;
    }

    /** @return array<string, mixed> */
    private function global(): array
    {
        $g = $this->config->get('ksef', []);

        /** @var array<string, mixed> */
        return is_array($g) ? $g : [];
    }
}
