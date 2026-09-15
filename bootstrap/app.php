<?php

use App\Exceptions\Auth\AccountLockedException;
use App\Http\Middleware\JwtAuthenticate;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // S5-01 / FR-20. First scheduled job in this project — Taqat
        // (production) needs its own cron entry running
        // `php artisan schedule:run` every minute for this to actually
        // fire; Laravel's scheduler does nothing on its own without one.
        $schedule->command('alerts:evaluate')->dailyAt('06:00');

        // S5-06 / FR-22. 20:00 is a placeholder, not a documented time —
        // late enough that most of a client's day is behind them (so
        // "hasn't logged today" is a meaningful signal, not a nag at
        // breakfast), early enough to still leave time to log before
        // midnight resets the "today" the reminder is about.
        $schedule->command('notifications:send-log-reminders')->dailyAt('20:00');

        // S5-04 / FR-21. Monday morning, summarising the week that just
        // closed (Mon-Sun) so every log for it has already landed.
        $schedule->command('ai-summaries:generate-weekly')->weeklyOn(1, '07:00');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'jwt' => JwtAuthenticate::class,
            // Not auto-registered by spatie/laravel-permission on this
            // Laravel version's array-config middleware system — see
            // Sprint 1's own custom `jwt` guard for why: there's no
            // "default auth middleware stack" for the package to hook
            // into automatically.
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AccountLockedException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'locked_until' => $e->lockedUntil->toIso8601String(),
            ], 423);
        });
    })->create();
