<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Auth;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use LuroWeb\KsefFoundry\Auth\TokenPair;
use LuroWeb\KsefFoundry\Auth\TokenStorageInterface;

/**
 * Laravel-cache backed JWT pair storage. Only the JWT pair is stored (never the KSeF token / private key).
 * With an encrypter (default) the pair is encrypted at rest; the key is the SDK tenant key (a SHA-256
 * that already separates environment, context, auth method and credential) under a configurable prefix.
 */
final class CacheTokenStorage implements TokenStorageInterface
{
    private readonly \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(
        private readonly Repository $cache,
        private readonly ?Encrypter $encrypter,
        private readonly string $prefix = 'ksef',
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function get(string $key): ?TokenPair
    {
        $raw = $this->cache->get($this->name($key));
        if (!is_string($raw)) {
            return null;
        }
        try {
            $json = $this->encrypter !== null ? $this->encrypter->decrypt($raw, false) : $raw;
            $data = json_decode(is_string($json) ? $json : '', true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \UnexpectedValueException('bad payload');
            }

            return TokenPair::fromStorageArray($data);
        } catch (\Throwable) {
            $this->delete($key); // corrupt, foreign or encrypted with an old APP_KEY: treat as a miss

            return null;
        }
    }

    public function put(string $key, TokenPair $pair, int $ttlSeconds): void
    {
        $untilRefresh = $pair->refreshValidUntil->getTimestamp() - ($this->clock)();
        $ttl = max(1, min($ttlSeconds, $untilRefresh));
        $json = json_encode($pair->toStorageArray(), JSON_THROW_ON_ERROR);
        $value = $this->encrypter !== null ? $this->encrypter->encrypt($json, false) : $json;
        $this->cache->put($this->name($key), $value, $ttl);
    }

    public function delete(string $key): void
    {
        $this->cache->forget($this->name($key));
    }

    private function name(string $key): string
    {
        return $this->prefix . ':jwt:' . $key;
    }
}
