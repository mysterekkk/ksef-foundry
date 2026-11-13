<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Query;

/** InvoiceQueryDateType. Incremental download must use PermanentStorage with ascending order. */
enum DateType: string
{
    case Issue = 'Issue';
    case Invoicing = 'Invoicing';
    case PermanentStorage = 'PermanentStorage';
}
