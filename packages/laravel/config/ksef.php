<?php

declare(strict_types=1);

/*
 * KSeF Foundry - Laravel configuration. Publish with:
 *   php artisan vendor:publish --tag=ksef-config
 *
 * The default environment is TEST. "production" is used only when you set it explicitly.
 * Secrets belong in .env (or your secret manager), never in this file.
 */

return [
    /* Name of the connection used by app(KsefClient::class), the Ksef facade and the jobs. */
    'default' => env('KSEF_CONNECTION', 'default'),

    /* test (default) | demo | production | custom (needs base_url; UNSAFE for real data, for local fault servers). */
    'environment' => env('KSEF_ENVIRONMENT', 'test'),

    /*
     * Named connections. Every key may be overridden per connection (environment, cache_store, ...).
     * auth = token | certificate; context_type = nip | internal_id | nip_vat_ue.
     * For tenants stored in your database use KsefManager::tenant([...same keys...]) instead.
     */
    'connections' => [
        'default' => [
            'auth' => env('KSEF_AUTH', 'token'),
            'context_type' => env('KSEF_CONTEXT_TYPE', 'nip'),
            'context_value' => env('KSEF_CONTEXT_NIP', env('KSEF_CONTEXT_VALUE')),
            'token' => env('KSEF_TOKEN'),
            'certificate_path' => env('KSEF_CERTIFICATE_PATH'),
            'private_key_path' => env('KSEF_PRIVATE_KEY_PATH'),
            'private_key_passphrase' => env('KSEF_PRIVATE_KEY_PASSPHRASE'),
        ],
    ],

    'http' => [
        /* curl = SDK built-in client (default). guzzle = hardened Guzzle PSR-18 wrapper (no redirects, TLS verify on). */
        'driver' => env('KSEF_HTTP_DRIVER', 'curl'),
        'timeout' => (float) env('KSEF_HTTP_TIMEOUT', 30),
        'connect_timeout' => (float) env('KSEF_HTTP_CONNECT_TIMEOUT', 10),
        'overall_deadline' => (float) env('KSEF_HTTP_DEADLINE', 120),
    ],

    'retry' => [
        'max_attempts' => (int) env('KSEF_RETRY_ATTEMPTS', 4),
        'base_delay' => (float) env('KSEF_RETRY_BASE_DELAY', 0.25),
        'max_delay' => (float) env('KSEF_RETRY_MAX_DELAY', 8),
        'max_retry_after' => (int) env('KSEF_RETRY_MAX_AFTER', 60),
    ],

    'cache' => [
        /* null = default cache store. Use a shared store (redis, database, file) in production; "array" is per process. */
        'store' => env('KSEF_CACHE_STORE'),
        'prefix' => env('KSEF_CACHE_PREFIX', 'ksef'),
        /* JWT pair encrypted at rest with the Laravel encrypter (APP_KEY). Do not disable without a reason. */
        'encrypt' => (bool) env('KSEF_CACHE_ENCRYPT', true),
        /* Single-flight login across processes via Cache::lock. */
        'lock' => (bool) env('KSEF_CACHE_LOCK', true),
        'lock_seconds' => (int) env('KSEF_LOCK_SECONDS', 150),
        'lock_wait_seconds' => (int) env('KSEF_LOCK_WAIT', 130),
    ],

    /* null = no logging (SDK default). The SDK logs method/path/status only, never secrets or invoice content. */
    'log' => [
        'channel' => env('KSEF_LOG_CHANNEL'),
    ],

    'exceptions' => [
        /* false = KsefExceptionRenderer never forwards upstream message/details/security (may hold taxpayer identifiers). */
        'expose_details' => (bool) env('KSEF_EXPOSE_DETAILS', false),
    ],

    'jobs' => [
        'queue' => env('KSEF_QUEUE'),
        'queue_connection' => env('KSEF_QUEUE_CONNECTION'),
        /* Seconds SubmitInvoiceJob waits inline for the final status before handing over to PollInvoiceStatusJob. */
        'await_seconds' => (float) env('KSEF_JOB_AWAIT', 20),
        'validate_xml' => (bool) env('KSEF_JOB_VALIDATE', true),
        /* Pause before reconciling after an ambiguous send, and how long the pending marker lives. */
        'reconcile_delay' => (float) env('KSEF_JOB_RECONCILE_DELAY', 3),
        /* Upper bound for any release() delay derived from a server Retry-After. */
        'max_release_delay' => (int) env('KSEF_JOB_MAX_RELEASE', 300),
        /* Seconds one SubmitInvoiceJob attempt may run; queue retry_after MUST be larger. */
        'timeout' => (int) env('KSEF_JOB_TIMEOUT', 90),
        'unique_for' => (int) env('KSEF_JOB_UNIQUE_FOR', 3600),
        'fetch_lookback_days' => (int) env('KSEF_FETCH_LOOKBACK_DAYS', 30),
    ],
];
