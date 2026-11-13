<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

/** TokenPermissionType from the OpenAPI. */
enum TokenPermission: string
{
    case InvoiceRead = 'InvoiceRead';
    case InvoiceWrite = 'InvoiceWrite';
    case CredentialsRead = 'CredentialsRead';
    case CredentialsManage = 'CredentialsManage';
    case SubunitManage = 'SubunitManage';
    case EnforcementOperations = 'EnforcementOperations';
    case Introspection = 'Introspection';
    case CollectiveIdentifierManage = 'CollectiveIdentifierManage';
}
