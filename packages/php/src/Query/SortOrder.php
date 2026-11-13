<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Query;

/** SortOrder query parameter. */
enum SortOrder: string
{
    case Asc = 'Asc';
    case Desc = 'Desc';
}
