<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

use LuroWeb\KsefFoundry\Support\Arr;

/** GET /limits/subject (EffectiveSubjectLimits); both groups are optional in the API. */
final class SubjectLimits
{
    public function __construct(
        public readonly ?int $maxEnrollments,
        public readonly ?int $maxCertificates,
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            Arr::intOrNull(Arr::arr($a, 'enrollment'), 'maxEnrollments'),
            Arr::intOrNull(Arr::arr($a, 'certificate'), 'maxCertificates'),
        );
    }
}
