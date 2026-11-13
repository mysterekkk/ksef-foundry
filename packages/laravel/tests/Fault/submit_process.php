<?php

/**
 * A REAL separate PHP process running one SubmitInvoiceJob attempt inside a booted Laravel (Testbench) application,
 * sharing a file cache with its siblings. Usage: php submit_process.php <configJsonFile>
 * Prints one JSON line: {"ok":true,"released":bool}. Used to prove that two workers never send the same invoice at once.
 */

declare(strict_types=1);

use LuroWeb\KsefFoundry\Laravel\Jobs\SubmitInvoiceJob;
use LuroWeb\KsefFoundry\Laravel\KsefServiceProvider;
use Orchestra\Testbench\Foundation\Application as Testbench;

require __DIR__ . '/../../vendor/autoload.php';

$cfg = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$app = Testbench::create(options: ['load_environment_variables' => false, 'extra' => ['providers' => [], 'dont-discover' => ['*']]]);
$app->register(KsefServiceProvider::class);
$config = $app['config'];
$config->set('app.key', $cfg['appKey']);
$config->set('cache.default', 'file');
$config->set('cache.stores.file.path', $cfg['cachePath']);
$config->set('ksef.environment', 'custom');
$config->set('ksef.retry.max_attempts', 1);
$config->set('ksef.jobs.reconcile_delay', 0);
$config->set('ksef.jobs.await_seconds', 20);
$config->set('ksef.connections.default', [
    'auth' => 'token', 'context_value' => $cfg['nip'], 'token' => $cfg['token'], 'base_url' => $cfg['baseUrl'],
]);

$job = new SubmitInvoiceJob($cfg['xml']);
$app->call([$job, 'handle']);
echo json_encode(['ok' => true]) . "\n";
