<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

use LuroWeb\KsefFoundry\Support\Arr;

final class AuthSessionPage
{
    /** @param list<AuthSession> $items */
    public function __construct(
        public readonly array $items,
        public readonly ?string $continuationToken,
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        $items = [];
        foreach (Arr::objects(Arr::arr($a, 'items')) as $i) {
            $items[] = AuthSession::fromArray($i);
        }
        $c = Arr::stringOrNull($a, 'continuationToken');

        return new self($items, $c === '' ? null : $c);
    }
}
