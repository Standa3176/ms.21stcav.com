<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * Pitfall E: fail fast if queue driver is not redis — Horizon silently ignores
     * non-redis drivers and failed jobs go to different storage, making the Horizon
     * UI and failed-job alerts lie about what's actually happening.
     *
     * The guard is suppressed under `testing` because phpunit.xml forces
     * QUEUE_CONNECTION=sync to run queued jobs synchronously in tests — Horizon's
     * boot assertion would fail every single test run otherwise.
     */
    public function boot(): void
    {
        parent::boot();

        if (app()->environment('testing')) {
            return;
        }

        throw_unless(
            config('queue.default') === 'redis',
            new \RuntimeException('Horizon requires QUEUE_CONNECTION=redis; found: '.config('queue.default'))
        );
    }

    /**
     * Register the Horizon gate — admin role only (D-01 / D-02).
     *
     * Pitfall K: /horizon must be admin-only; exposing it to non-admins leaks
     * queue payloads and job failure messages that may contain PII.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return $user !== null && $user->hasRole('admin');
        });
    }
}
