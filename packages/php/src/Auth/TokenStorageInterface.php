<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Auth;

/**
 * Pluggable store for JWT pairs, keyed by tenant key. Implementations (Laravel cache, Redis ...) are
 * supplied by the application; they receive only TokenPair, never the KSeF token or the private key.
 */
interface TokenStorageInterface
{
    public function get(string $key): ?TokenPair;

    public function put(string $key, TokenPair $pair, int $ttlSeconds): void;

    public function delete(string $key): void;
}
