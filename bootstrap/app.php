<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Запросы приходят через nginx в контейнере, а в продакшене ещё и через прокси с TLS.
        // Заголовкам X-Forwarded-* от адресов частных сетей верим: так приложение видит https
        // и настоящий адрес клиента.
        $middleware->trustProxies(at: 'PRIVATE_SUBNETS');

        $middleware->encryptCookies(except: ['sidebar_state']);

        // Кнопки в пуше защищены подписью ссылки, а не CSRF-токеном.
        $middleware->validateCsrfTokens(except: ['push/doses/*']);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
