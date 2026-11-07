<?php

use App\Http\Controllers\Store\StoreApiController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        $middleware->alias([
            'super.admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'shop.user' => \App\Http\Middleware\EnsureShopUser::class,
            'shop.access' => \App\Http\Middleware\EnsureShopScreenAccess::class,
            'store.token' => \App\Http\Middleware\AuthenticateStoreToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API's error shape, whatever went wrong. Nothing internal reaches a store.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $e instanceof ThrottleRequestsException => StoreApiController::error('rate_limited', 'Too many requests. Wait and try again.', 429)->withHeaders($e->getHeaders()),
                $e instanceof MethodNotAllowedHttpException => StoreApiController::error('method_not_allowed', 'That method isn\'t allowed here.', 405),
                $e instanceof NotFoundHttpException => StoreApiController::error('not_found', 'There\'s nothing at that address.', 404),
                $e instanceof HttpExceptionInterface => StoreApiController::error('request_failed', 'The request could not be completed.', $e->getStatusCode()),
                default => StoreApiController::error('server_error', 'Something went wrong on our side. Try again.', 500),
            };
        });
    })->create();
