<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

use LuroWeb\KsefFoundry\Support\Arr;

/** TokenStatusResponse / QueryTokensResponseItem (never contains the secret token). */
final class KsefTokenInfo
{
    /**
     * @param list<string> $requestedPermissions
     * @param list<string> $statusDetails
     */
    public function __construct(
        public readonly string $referenceNumber,
        public readonly string $authorIdentifierType,
        public readonly string $authorIdentifierValue,
        public readonly string $contextIdentifierType,
        public readonly string $contextIdentifierValue,
        public readonly string $description,
        public readonly array $requestedPermissions,
        public readonly \DateTimeImmutable $dateCreated,
        public readonly ?\DateTimeImmutable $lastUseDate,
        public readonly ?TokenStatus $status,
        public readonly array $statusDetails,
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        $author = Arr::arr($a, 'authorIdentifier');
        $ctx = Arr::arr($a, 'contextIdentifier');

        return new self(
            Arr::string($a, 'referenceNumber'),
            Arr::stringOrNull($author, 'type') ?? '',
            Arr::stringOrNull($author, 'value') ?? '',
            Arr::stringOrNull($ctx, 'type') ?? '',
            Arr::stringOrNull($ctx, 'value') ?? '',
            Arr::stringOrNull($a, 'description') ?? '',
            Arr::strings($a, 'requestedPermissions'),
            Arr::date($a, 'dateCreated'),
            Arr::dateOrNull($a, 'lastUseDate'),
            TokenStatus::tryFrom(Arr::stringOrNull($a, 'status') ?? ''),
            Arr::strings($a, 'statusDetails'),
        );
    }
}
