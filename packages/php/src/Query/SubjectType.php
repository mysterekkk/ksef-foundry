<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Query;

/** InvoiceQuerySubjectType: Subject1 seller, Subject2 buyer, Subject3 third party, SubjectAuthorized authorised subject. */
enum SubjectType: string
{
    case Subject1 = 'Subject1';
    case Subject2 = 'Subject2';
    case Subject3 = 'Subject3';
    case SubjectAuthorized = 'SubjectAuthorized';
}
