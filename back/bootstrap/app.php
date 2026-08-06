<?php

use App\Helpers\ResponseHelper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__.'/../routes/channels.php',
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        \App\Notifications\Scheduling\DispatchDueAlerts::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Without this, Laravel ignores X-Forwarded-Proto and resolves request URLs as http
        // even when the client reached the public endpoint over https. That breaks any check
        // computed over the request URL — notably Twilio's webhook signature, which is signed
        // over the exact external URL and would then always mismatch with a misleading 401.
        //
        // PRODUCTION: replace '*' with the actual proxy/load-balancer IP ranges. Trusting any
        // proxy is only safe while the app is not reachable directly, because a client that can
        // reach it bypassing the proxy could otherwise spoof X-Forwarded-* (scheme and client IP,
        // which the login rate limiter keys on).
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'vet.tenant' => \App\Http\Middleware\EnsureUserBelongsToVet::class,
            'kapso.signature' => \App\Notifications\Http\Middleware\VerifyKapsoWebhookSignature::class,
            'twilio.signature' => \App\Notifications\Http\Middleware\VerifyTwilioWebhookSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->expectsJson()) {
                return ResponseHelper::makeFromException($e);
            }
        });
    })->create();
