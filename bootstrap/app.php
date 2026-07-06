<?php

use App\Services\Helpers\ScheduleHelper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$trustedProxies = env('TRUSTED_PROXIES', '*');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        // Register all scheduled jobs.
        ScheduleHelper::registerSchedule($schedule);
    })
    ->withMiddleware(function (Middleware $middleware) use ($trustedProxies) {
        // Trust the reverse proxy so forwarded headers (notably
        // X-Forwarded-Proto) are honoured. Without this, requests behind an
        // SSL-terminating proxy are seen as plain HTTP and absolute URLs such
        // as the Filament logout form action are generated over http://.
        $middleware->trustProxies(
            at: $trustedProxies === '*' ? '*' : explode(',', (string) $trustedProxies),
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
