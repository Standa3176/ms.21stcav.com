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

        // Plan 03 appends AttachCorrelationId middleware here
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
