<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
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

            if (
                $exception instanceof ValidationException
                || $exception instanceof AuthenticationException
                || $exception instanceof AuthorizationException
            ) {
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

            if ($exception instanceof HttpExceptionInterface) {
                $statusCode = $exception->getStatusCode();
                $message = trim($exception->getMessage());

                return response()->json([
                    'message' => $message !== '' && $message !== 'Server Error'
                        ? $message
                        : match ($statusCode) {
                            403 => 'Anda tidak memiliki hak akses untuk aksi ini.',
                            405 => 'Metode permintaan tidak didukung pada endpoint ini.',
                            413 => 'Ukuran data yang dikirim melebihi batas server.',
                            415 => 'Format data yang dikirim tidak didukung.',
                            default => 'Terjadi kesalahan pada server.',
                        },
                ], $statusCode >= 400 && $statusCode < 600 ? $statusCode : 500);
            }

            return response()->json([
                'message' => 'Terjadi kesalahan pada server.',
            ], 500);
        });
    })->create();
