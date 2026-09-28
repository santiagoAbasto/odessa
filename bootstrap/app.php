<?php

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Impide que un Host falsificado contamine URLs absolutas (por ejemplo,
        // los enlaces de recuperación de contraseña). En local Laravel lo omite.
        $middleware->trustHosts(
            at: fn () => array_filter([
                ($host = parse_url((string) config('app.url'), PHP_URL_HOST))
                    ? '^'.preg_quote($host, '/').'$'
                    : null,
            ]),
            subdomains: false,
        );

        $middleware->web(append: [
            SecurityHeaders::class,
            EnsureUserIsActive::class,
            EnsurePasswordIsChanged::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
