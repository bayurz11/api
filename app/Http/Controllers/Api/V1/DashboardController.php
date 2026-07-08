<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Return lightweight dashboard data for the mobile app.
     */
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'summary' => [
                'total_tables' => DB::table('tables')->count(),
                'available_tables' => DB::table('tables')->where('status', 'AVAILABLE')->count(),
                'open_bills' => DB::table('bills')->whereIn('status', ['OPEN', 'ORDERING', 'READY_TO_PAY', 'PARTIALLY_PAID'])->count(),
                'today_sales' => (float) DB::table('payments')
                    ->whereDate('paid_at', now()->toDateString())
                    ->sum('amount'),
            ],
        ]);
    }
}
