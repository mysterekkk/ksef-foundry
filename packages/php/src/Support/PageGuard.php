<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Support;

use LuroWeb\KsefFoundry\Exception\KsefException;

/**
 * Termination guard for continuation-token pagination: a hostile or broken server must not be able to keep a
 * generator looping forever (repeated token or an absurd number of pages).
 */
final class PageGuard
{
    /** @var array<string, true> */
    private array $seen = [];
    private int $pages = 0;

    public function __construct(private readonly int $maxPages = 100_000)
    {
    }

    /**
     * Register the token a page returned (null = last page).
     *
     * @throws KsefException on a repeated token or when maxPages is exceeded
     */
    public function advance(?string $nextToken): void
    {
        if (++$this->pages > $this->maxPages) {
            throw new KsefException(sprintf('Pagination aborted: more than %d pages.', $this->maxPages));
        }
        if ($nextToken === null) {
            return;
        }
        if (isset($this->seen[$nextToken])) {
            throw new KsefException('Pagination aborted: the server repeated a continuation token.');
        }
        $this->seen[$nextToken] = true;
    }
}
