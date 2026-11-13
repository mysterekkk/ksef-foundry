<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Console;

use Illuminate\Console\Command;
use LuroWeb\KsefFoundry\Crypto\PublicKeyUsage;
use LuroWeb\KsefFoundry\Exception\KsefException;
use LuroWeb\KsefFoundry\Laravel\KsefManager;

/** ksef:check - connectivity, public keys, login and limits. Prints no secrets and no full identifiers. */
final class CheckCommand extends Command
{
    protected $signature = 'ksef:check {connection? : Connection name (default: ksef.default)} {--no-auth : Only check public keys, do not log in}';

    protected $description = 'Check the KSeF connection: environment, public keys, authentication and limits (no secrets printed)';

    public function handle(KsefManager $manager): int
    {
        $arg = $this->argument('connection');
        $name = is_string($arg) && $arg !== '' ? $arg : $manager->defaultConnectionName();
        try {
            $client = $manager->connection($name);
            $env = $client->environment();
            $ctx = $client->credentials()->context();
            $this->line(sprintf('Connection: %s', $name));
            $this->line(sprintf('Environment: %s (%s)', $env->name, $env->baseUrl));
            if ($env->isProduction()) {
                $this->warn('PRODUCTION environment selected.');
            }
            $this->line(sprintf('Context: %s %s', $ctx->type->value, self::mask($ctx->value)));
            $this->line(sprintf('Auth method: %s', $client->credentials()->method()));

            foreach ([PublicKeyUsage::KsefTokenEncryption, PublicKeyUsage::SymmetricKeyEncryption] as $usage) {
                $key = $client->publicKeys()->get($usage);
                $this->line(sprintf(
                    'Public key %s: RSA-%d, valid until %s',
                    $usage->value,
                    $key->rsaBits,
                    $key->validTo->format('Y-m-d'),
                ));
            }
            if ($this->option('no-auth') === true) {
                $this->info('OK (public keys only).');

                return self::SUCCESS;
            }
            $client->auth()->getAccessToken($client->credentials());
            $this->line('Authentication: OK');
            $limits = $client->limits()->context();
            $this->line(sprintf(
                'Online session limits: max %d invoices, max %d MB per invoice',
                $limits->onlineSession->maxInvoices,
                $limits->onlineSession->maxInvoiceSizeInMB,
            ));
            $this->info('OK');

            return self::SUCCESS;
        } catch (KsefException $e) {
            $d = $e->diagnostics();
            $this->error(sprintf('FAILED: %s: %s', (new \ReflectionClass($e))->getShortName(), is_string($d['message'] ?? null) ? $d['message'] : 'error'));

            return self::FAILURE;
        }
    }

    private static function mask(string $value): string
    {
        return strlen($value) <= 4 ? '****' : str_repeat('*', strlen($value) - 4) . substr($value, -4);
    }
}
