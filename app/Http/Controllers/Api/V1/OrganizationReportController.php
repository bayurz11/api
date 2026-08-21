<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use App\Support\OrganizationBranchMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OrganizationReportController extends Controller
{
    public function __invoke(Request $request, OrganizationBranchMetrics $metrics): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);
        $dateFrom = $validated['date_from'] ?? now()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();
        abort_if($dateFrom > $dateTo, 422, 'Tanggal mulai tidak boleh melebihi tanggal akhir.');

        $branch = app(BranchContext::class)->branch();
        abort_if(! $branch, 409, 'Cabang aktif belum dipilih.');

        return response()->json([
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
            ...$metrics->build(
                $branch->organization_id,
                Carbon::parse($dateFrom)->startOfDay(),
                Carbon::parse($dateTo)->addDay()->startOfDay(),
            ),
        ]);
    }
}
