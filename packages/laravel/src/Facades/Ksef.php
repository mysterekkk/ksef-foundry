<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Laravel\KsefManager;

/**
 * Convenience proxy to the DEFAULT connection's KsefClient. Dependency injection of KsefClient /
 * KsefManager is preferred (testable, explicit tenant).
 *
 * @method static \LuroWeb\KsefFoundry\Session\InvoiceSender sender()
 * @method static \LuroWeb\KsefFoundry\Session\InvoiceSessionService sessions()
 * @method static \LuroWeb\KsefFoundry\Service\InvoiceService invoices()
 * @method static \LuroWeb\KsefFoundry\Query\InvoiceQueryService query()
 * @method static \LuroWeb\KsefFoundry\Service\UpoService upo()
 * @method static \LuroWeb\KsefFoundry\Service\LimitsService limits()
 * @method static \LuroWeb\KsefFoundry\Service\KsefTokenService tokens()
 * @method static \LuroWeb\KsefFoundry\Service\AuthSessionService authSessions()
 * @method static \LuroWeb\KsefFoundry\Environment environment()
 *
 * @see KsefClient
 */
final class Ksef extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return KsefClient::class;
    }

    /** Named connection or tenant manager. */
    public static function manager(): KsefManager
    {
        $m = static::getFacadeApplication()?->make(KsefManager::class);

        return $m instanceof KsefManager ? $m : throw new \LogicException('KsefManager is not bound.');
    }
}
