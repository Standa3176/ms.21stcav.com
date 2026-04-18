<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Webhook endpoints don't have sessions / CSRF tokens
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        // FOUND-03: Attach correlation_id at HTTP entry for EVERY request (global middleware).
        // Applies to web, api, webhooks AND the /up health route — infrastructure-level tracing.
        // Queued jobs hydrate Context automatically via Laravel 12's dehydrate/hydrate mechanism.
        $middleware->append(\App\Http\Middleware\AttachCorrelationId::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
