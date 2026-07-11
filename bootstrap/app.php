<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'permission' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! $request->is('api/*') || ! app()->environment('production')) {
                return null;
            }

            if ($exception instanceof ValidationException || $exception instanceof AuthenticationException) {
                return null;
            }

            if ($exception instanceof ThrottleRequestsException) {
                return response()->json([
                    'message' => 'Terlalu banyak permintaan. Silakan coba lagi beberapa saat lagi.',
                ], 429);
            }

            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 404) {
                return response()->json([
                    'message' => 'Endpoint API tidak ditemukan.',
                ], 404);
            }

            return response()->json([
                'message' => 'Terjadi kesalahan pada server.',
            ], 500);
        });
    })->create();
