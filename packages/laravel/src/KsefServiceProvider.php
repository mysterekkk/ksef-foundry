<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Laravel\Console\CheckCommand;
use LuroWeb\KsefFoundry\Laravel\Contracts\InvoiceSink;
use LuroWeb\KsefFoundry\Laravel\Support\NullInvoiceSink;

final class KsefServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ksef.php', 'ksef');

        $this->app->singleton(KsefClientFactory::class, static fn (Application $app): KsefClientFactory => new KsefClientFactory($app));
        $this->app->singleton(KsefManager::class, static fn (Application $app): KsefManager => new KsefManager(
            $app->make('config'),
            $app->make(KsefClientFactory::class),
        ));
        $this->app->alias(KsefManager::class, 'ksef');

        // Lazy: a missing token does not break boot (artisan about, route:list); it fails on first use.
        $this->app->singleton(KsefClient::class, static fn (Application $app): KsefClient => $app->make(KsefManager::class)->connection());

        $this->app->bindIf(InvoiceSink::class, NullInvoiceSink::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/ksef.php' => $this->app->configPath('ksef.php')], 'ksef-config');
            $this->commands([CheckCommand::class]);
        }
    }
}
