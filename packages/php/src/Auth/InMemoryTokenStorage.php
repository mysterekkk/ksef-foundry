<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Auth;

/** Default per-process storage. */
final class InMemoryTokenStorage implements TokenStorageInterface
{
    /** @var array<string, array{pair: TokenPair, expires: int}> */
    private array $items = [];

    private readonly \Closure $clock;

    /**
     * @param (\Closure(): int)|null $clock unix time provider (tests)
     */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function get(string $key): ?TokenPair
    {
        $item = $this->items[$key] ?? null;
        if ($item === null) {
            return null;
        }
        if ($item['expires'] <= ($this->clock)()) {
            unset($this->items[$key]);

            return null;
        }

        return $item['pair'];
    }

    public function put(string $key, TokenPair $pair, int $ttlSeconds): void
    {
        $this->items[$key] = ['pair' => $pair, 'expires' => ($this->clock)() + max(1, $ttlSeconds)];
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }
}
