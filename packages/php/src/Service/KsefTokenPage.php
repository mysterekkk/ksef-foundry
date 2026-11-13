<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

use LuroWeb\KsefFoundry\Support\Arr;

final class KsefTokenPage
{
    /** @param list<KsefTokenInfo> $tokens */
    public function __construct(
        public readonly array $tokens,
        public readonly ?string $continuationToken,
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        $items = [];
        foreach (Arr::objects(Arr::arr($a, 'tokens')) as $t) {
            $items[] = KsefTokenInfo::fromArray($t);
        }
        $c = Arr::stringOrNull($a, 'continuationToken');

        return new self($items, $c === '' ? null : $c);
    }
}
