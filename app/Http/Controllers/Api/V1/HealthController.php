<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');

            return response()->json([
                'status' => 'ok',
                'service' => 'restopos-api',
                'version' => 'v1',
                'database' => 'connected',
                'checked_at' => now()->toIso8601String(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'degraded',
                'service' => 'restopos-api',
                'version' => 'v1',
                'database' => 'unavailable',
                'checked_at' => now()->toIso8601String(),
            ], 503);
        }
    }
}
