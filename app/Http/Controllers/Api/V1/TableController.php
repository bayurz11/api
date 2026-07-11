<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Table;
use App\Support\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TableController extends Controller
{
    private const TABLE_STATUSES = [
        'AVAILABLE',
        'OPEN_BILL',
        'RESERVED',
        'CLEANING',
        'OUT_OF_SERVICE',
    ];

    public function index(Request $request): JsonResponse
    {
        Table::query()
            ->where('status', 'CLEANING')
            ->where('updated_at', '<=', Carbon::now()->subMinutes(10))
            ->update([
                'status' => 'AVAILABLE',
            ]);

        $tables = Table::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('area'), fn ($query) => $query->where('area', $request->string('area')))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $tables,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:tables,code'],
            'name' => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'area' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(self::TABLE_STATUSES)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $table = Table::query()->create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'capacity' => $validated['capacity'] ?? 1,
            'area' => $validated['area'] ?? null,
            'status' => $validated['status'] ?? 'AVAILABLE',
            'is_active' => $validated['is_active'] ?? true,
        ]);

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'table.created',
            entityType: 'table',
            entityId: $table->id,
            after: $table->toArray(),
        );

        return response()->json([
            'message' => 'Meja berhasil dibuat.',
            'data' => $table,
        ], 201);
    }

    public function update(Request $request, Table $table): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('tables', 'code')->ignore($table->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
            'area' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(self::TABLE_STATUSES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $before = $table->only(['code', 'name', 'capacity', 'area', 'status', 'is_active']);

        $table->fill($validated);
        $table->save();

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'table.updated',
            entityType: 'table',
            entityId: $table->id,
            before: $before,
            after: $table->only(['code', 'name', 'capacity', 'area', 'status', 'is_active']),
        );

        return response()->json([
            'message' => 'Meja berhasil diperbarui.',
            'data' => $table,
        ]);
    }

    public function markReady(Request $request, Table $table): JsonResponse
    {
        if ($table->status === 'AVAILABLE') {
            return response()->json([
                'message' => 'Meja ini sudah siap digunakan.',
                'data' => $table,
            ]);
        }

        abort_if(
            $table->status !== 'CLEANING',
            422,
            'Meja ini tidak sedang dalam status pembersihan.',
        );

        $before = $table->only(['code', 'name', 'capacity', 'area', 'status', 'is_active']);

        $table->status = 'AVAILABLE';
        $table->save();

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'table.marked_ready',
            entityType: 'table',
            entityId: $table->id,
            before: $before,
            after: $table->only(['code', 'name', 'capacity', 'area', 'status', 'is_active']),
        );

        return response()->json([
            'message' => 'Meja ini sudah siap digunakan.',
            'data' => $table,
        ]);
    }

    public function destroy(Request $request, Table $table): JsonResponse
    {
        $hasActiveBill = $table->bills()
            ->whereIn('status', ['OPEN', 'ORDERING', 'READY_TO_PAY', 'PARTIALLY_PAID', 'SERVED'])
            ->exists();
        $hasActiveLinkedBill = $table->linkedBills()
            ->whereIn('status', ['OPEN', 'ORDERING', 'READY_TO_PAY', 'PARTIALLY_PAID', 'SERVED'])
            ->exists();
        abort_if($hasActiveBill || $hasActiveLinkedBill, 422, 'Meja masih memiliki bill aktif dan tidak dapat dihapus.');

        $hasActiveReservation = $table->reservations()
            ->whereIn('status', ['BOOKED'])
            ->exists();
        abort_if($hasActiveReservation, 422, 'Meja masih memiliki reservasi aktif dan tidak dapat dihapus.');

        $before = $table->toArray();
        $table->delete();

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'table.deleted',
            entityType: 'table',
            entityId: $before['id'],
            before: $before,
        );

        return response()->json([
            'message' => 'Meja berhasil dihapus.',
        ]);
    }
}
