<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Deposit;
use App\Models\Reservation;
use App\Support\AuditLogger;
use App\Support\BillTableManager;
use App\Support\SequenceNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        $reservations = Reservation::query()
            ->with(['customer:id,name,phone,member_code', 'table:id,code,name'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest('reserved_at')
            ->paginate($perPage);

        return response()->json($reservations);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'table_id' => ['nullable', 'integer', 'exists:tables,id'],
            'reserved_at' => ['required', 'date'],
            'guest_count' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $reservation = Reservation::query()->create([
            'customer_id' => $validated['customer_id'] ?? null,
            'table_id' => $validated['table_id'] ?? null,
            'reservation_code' => SequenceNumber::generate('RSV', Reservation::class, 'reservation_code'),
            'reserved_at' => $validated['reserved_at'],
            'guest_count' => $validated['guest_count'],
            'status' => 'BOOKED',
            'notes' => $validated['notes'] ?? null,
        ]);

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'reservation.created',
            entityType: 'reservation',
            entityId: $reservation->id,
            after: $reservation->toArray(),
        );

        return response()->json([
            'message' => 'Reservasi berhasil dibuat.',
            'data' => $reservation->load(['customer:id,name', 'table:id,code,name']),
        ], 201);
    }

    public function addDeposit(Request $request, Reservation $reservation): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
        ]);

        $deposit = Deposit::query()->create([
            'reservation_id' => $reservation->id,
            'customer_id' => $reservation->customer_id,
            'amount' => $validated['amount'],
            'received_by' => $request->user()->id,
            'received_at' => now(),
            'status' => 'PAID',
        ]);

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'deposit.created',
            entityType: 'deposit',
            entityId: $deposit->id,
            after: $deposit->toArray(),
        );

        return response()->json([
            'message' => 'Deposit berhasil ditambahkan.',
            'data' => $deposit,
        ], 201);
    }

    public function convertToBill(Request $request, Reservation $reservation): JsonResponse
    {
        abort_if($reservation->status === 'CONVERTED', 422, 'Reservasi sudah dikonversi menjadi bill.');

        $bill = DB::transaction(function () use ($reservation, $request) {
            if ($reservation->table_id) {
                $openBillExists = BillTableManager::activeBillExistsOnAnyTable([
                    (int) $reservation->table_id,
                ]);

                abort_if($openBillExists, 422, 'Meja reservasi sudah memiliki bill aktif.');
            }

            $depositTotal = (float) $reservation->deposits()->sum('amount');

            $bill = Bill::query()->create([
                'bill_no' => SequenceNumber::generate('BILL', Bill::class, 'bill_no'),
                'bill_type' => 'RESERVATION',
                'table_id' => $reservation->table_id,
                'customer_id' => $reservation->customer_id,
                'reservation_id' => $reservation->id,
                'opened_by' => $request->user()->id,
                'cashier_id' => $request->user()->id,
                'guest_count' => $reservation->guest_count,
                'status' => 'OPEN',
                'paid_total' => $depositTotal,
                'opened_at' => now(),
            ]);

            $reservation->update(['status' => 'CONVERTED']);

            if ($bill->table_id) {
                BillTableManager::syncBillTables($bill, [$bill->table_id]);
                BillTableManager::updateBillTablesStatus($bill, 'OPEN_BILL');
            }

            $reservation->deposits()->update(['bill_id' => $bill->id]);

            AuditLogger::log(
                userId: $request->user()->id,
                roleName: $request->user()->getRoleNames()->first(),
                action: 'reservation.converted_to_bill',
                entityType: 'bill',
                entityId: $bill->id,
                after: $bill->toArray(),
            );

            return $bill;
        });

        return response()->json([
            'message' => 'Reservasi berhasil dikonversi ke bill.',
            'data' => $bill->load(['table:id,code,name,status', 'tables:id,code,name,status,capacity,area']),
        ], 201);
    }
}
