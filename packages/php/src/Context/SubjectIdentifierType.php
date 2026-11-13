<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Context;

/** AuthTokenRequest/SubjectIdentifierType (XSD auth v2.1). */
enum SubjectIdentifierType: string
{
    case CertificateSubject = 'certificateSubject';
    case CertificateFingerprint = 'certificateFingerprint';
}
