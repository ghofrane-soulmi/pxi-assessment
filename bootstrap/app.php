<?php

use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerifyPixelforgeSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Server-to-server: no session, CSRF or Inertia; authenticated by its signature instead.
        then: function () {
            Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
                ->middleware(VerifyPixelforgeSignature::class)
                ->name('webhooks.pixelforge');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
