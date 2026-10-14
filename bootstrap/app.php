<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Which proxies may set X-Forwarded-*. Behind a load balancer this must
        // be the LB's IPs or CIDR -- "*" trusts anything, which is fine for a
        // dev tunnel but lets a client spoof scheme/host if the app is also
        // reachable directly. Defaults to "*" so local dev is unchanged.
        $proxies = env('TRUSTED_PROXIES', '*');

        $middleware->trustProxies(
            at: $proxies === '*' ? '*' : array_filter(array_map('trim', explode(',', (string) $proxies)))
        );

        // The throttle middleware is high in Laravel's default priority list, so
        // without this it runs BEFORE the session token is verified and the
        // 'score-submit' limiter has no shop_id/player_id to key on -- silently
        // falling back to the spoofable IP. Verified by the two throttle tests.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\ThrottleRequests::class,
            prepend: \App\Http\Middleware\VerifyShopifySession::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
