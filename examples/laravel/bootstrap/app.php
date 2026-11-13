<?php

use App\Http\Middleware\RequireApiToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use LuroWeb\KsefFoundry\Exception\KsefException;
use LuroWeb\KsefFoundry\Laravel\Exceptions\KsefExceptionRenderer;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['ksef.token' => RequireApiToken::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // safe JSON for KSeF failures (429 + Retry-After, 403, rejected invoice ...), no raw problem bodies
        $exceptions->render(fn (KsefException $e) => KsefExceptionRenderer::render($e));
    })->create();
