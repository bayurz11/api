<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'entity_type' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = DB::table('audit_logs')
            ->when(isset($validated['date_from']), fn ($query) => $query->whereDate('logged_at', '>=', $validated['date_from']))
            ->when(isset($validated['date_to']), fn ($query) => $query->whereDate('logged_at', '<=', $validated['date_to']))
            ->when(isset($validated['user_id']), fn ($query) => $query->where('user_id', $validated['user_id']))
            ->when(isset($validated['entity_type']), fn ($query) => $query->where('entity_type', $validated['entity_type']))
            ->when(isset($validated['action']), fn ($query) => $query->where('action', $validated['action']))
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->select([
                'audit_logs.id',
                'audit_logs.user_id',
                'users.name as user_name',
                'users.username',
                'audit_logs.role_name',
                'audit_logs.action',
                'audit_logs.entity_type',
                'audit_logs.entity_id',
                'audit_logs.before_data',
                'audit_logs.after_data',
                'audit_logs.reason',
                'audit_logs.logged_at',
            ])
            ->orderByDesc('audit_logs.id')
            ->paginate($validated['per_page'] ?? 15);

        $logs->getCollection()->transform(function ($row) {
            $row->before_data = $row->before_data ? json_decode($row->before_data, true) : null;
            $row->after_data = $row->after_data ? json_decode($row->after_data, true) : null;

            return $row;
        });

        return response()->json($logs);
    }
}
