<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Reservation;
use App\Support\AuditLogger;
use App\Support\BillTableManager;
use App\Support\BillTotals;
use App\Support\ReservationManager;
use App\Support\SequenceNumber;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    private const SOURCES = ['PHONE', 'WHATSAPP', 'WALK_IN', 'WEB', 'OTHER'];

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        $reservations = Reservation::query()
            ->with([
                'customer:id,name,phone,member_code',
                'table:id,code,name,capacity,area,status',
                'tables:id,code,name,capacity,area,status',
            ])
            ->withSum(['deposits as deposit_total' => fn ($query) => $query->where('status', 'PAID')], 'amount')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('date'), fn ($query) => $query->whereDate('reserved_at', $request->date('date')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.trim($request->string('search')->toString()).'%';
                $query->where(function ($nested) use ($search) {
                    $nested->where('reservation_code', 'like', $search)
                        ->orWhere('guest_name', 'like', $search)
                        ->orWhere('guest_phone', 'like', $search)
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $search));
                });
            })
            ->orderByRaw("CASE WHEN status IN ('PENDING', 'CONFIRMED', 'ARRIVED') THEN 0 ELSE 1 END")
            ->orderBy('reserved_at')
            ->paginate($perPage);

        return response()->json($reservations);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $reservedAt = Carbon::parse($validated['reserved_at']);
        $tableIds = ReservationManager::normalizeTableIds(
            $validated['table_id'] ?? null,
            $validated['extra_table_ids'] ?? [],
        );

        ReservationManager::validateAssignment(
            $tableIds,
            (int) $validated['guest_count'],
            $reservedAt,
            (int) ($validated['duration_minutes'] ?? 120),
        );

        $reservation = DB::transaction(function () use ($request, $validated, $reservedAt, $tableIds) {
            $customer = isset($validated['customer_id'])
                ? \App\Models\Customer::query()->find($validated['customer_id'])
                : null;

            $reservation = Reservation::query()->create([
                'customer_id' => $validated['customer_id'] ?? null,
                'guest_name' => $validated['guest_name'] ?? $customer?->name,
                'guest_phone' => $validated['guest_phone'] ?? $customer?->phone,
                'table_id' => $tableIds[0] ?? null,
                'reservation_code' => SequenceNumber::generate('RSV', Reservation::class, 'reservation_code'),
                'reserved_at' => $reservedAt,
                'duration_minutes' => $validated['duration_minutes'] ?? 120,
                'arrival_grace_minutes' => $validated['arrival_grace_minutes'] ?? 15,
                'guest_count' => $validated['guest_count'],
                'deposit_required_amount' => $validated['deposit_required_amount'] ?? 0,
                'status' => 'PENDING',
                'source' => $validated['source'] ?? 'PHONE',
                'notes' => $validated['notes'] ?? null,
                'cancellation_policy' => $validated['cancellation_policy'] ?? null,
            ]);

            ReservationManager::syncTables($reservation, $tableIds);
            $this->audit($request, $reservation, 'reservation.created');

            return $reservation;
        });

        return response()->json([
            'message' => 'Reservasi berhasil dibuat dan menunggu konfirmasi.',
            'data' => $this->loadReservation($reservation),
        ], 201);
    }

    public function update(Request $request, Reservation $reservation): JsonResponse
    {
        abort_unless(in_array($reservation->status, ['PENDING', 'CONFIRMED'], true), 422, 'Reservasi pada status ini tidak dapat diubah.');
        $validated = $this->validatePayload($request);
        $reservedAt = Carbon::parse($validated['reserved_at']);
        $tableIds = ReservationManager::normalizeTableIds(
            $validated['table_id'] ?? null,
            $validated['extra_table_ids'] ?? [],
        );

        ReservationManager::validateAssignment(
            $tableIds,
            (int) $validated['guest_count'],
            $reservedAt,
            (int) ($validated['duration_minutes'] ?? 120),
            $reservation->id,
        );

        DB::transaction(function () use ($validated, $reservedAt, $tableIds, $reservation, $request) {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $reservation->update([
                'customer_id' => $validated['customer_id'] ?? null,
                'guest_name' => $validated['guest_name'],
                'guest_phone' => $validated['guest_phone'],
                'reserved_at' => $reservedAt,
                'duration_minutes' => $validated['duration_minutes'] ?? 120,
                'arrival_grace_minutes' => $validated['arrival_grace_minutes'] ?? 15,
                'guest_count' => $validated['guest_count'],
                'deposit_required_amount' => $validated['deposit_required_amount'] ?? 0,
                'source' => $validated['source'] ?? 'PHONE',
                'notes' => $validated['notes'] ?? null,
                'cancellation_policy' => $validated['cancellation_policy'] ?? null,
            ]);
            ReservationManager::syncTables($reservation, $tableIds);
            $this->audit($request, $reservation, 'reservation.updated');
        });

        return response()->json([
            'message' => 'Reservasi berhasil diperbarui.',
            'data' => $this->loadReservation($reservation->fresh()),
        ]);
    }

    public function confirm(Request $request, Reservation $reservation): JsonResponse
    {
        $this->transition($request, $reservation, ['PENDING'], 'CONFIRMED', ['confirmed_at' => now()]);

        return response()->json(['message' => 'Reservasi berhasil dikonfirmasi.', 'data' => $this->loadReservation($reservation->fresh())]);
    }

    public function checkIn(Request $request, Reservation $reservation): JsonResponse
    {
        abort_unless(in_array($reservation->status, ['CONFIRMED'], true), 422, 'Hanya reservasi terkonfirmasi yang dapat check-in.');
        abort_if(now()->lt($reservation->reserved_at->copy()->subHour()), 422, 'Check-in baru dapat dilakukan satu jam sebelum jadwal.');
        $tableIds = ReservationManager::tableIds($reservation);
        abort_if(BillTableManager::activeBillExistsOnAnyTable($tableIds), 422, 'Salah satu meja masih memiliki bill aktif.');

        DB::transaction(function () use ($request, $reservation, $tableIds) {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $reservation->update(['status' => 'ARRIVED', 'arrived_at' => now()]);
            BillTableManager::updateTablesStatus($tableIds, 'RESERVED');
            $this->audit($request, $reservation, 'reservation.checked_in');
        });

        return response()->json(['message' => 'Kedatangan tamu berhasil dicatat.', 'data' => $this->loadReservation($reservation->fresh())]);
    }

    public function cancel(Request $request, Reservation $reservation): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'deposit_action' => ['nullable', Rule::in(['REFUND', 'FORFEIT'])],
        ]);
        abort_unless(in_array($reservation->status, ['PENDING', 'CONFIRMED', 'ARRIVED'], true), 422, 'Reservasi pada status ini tidak dapat dibatalkan.');
        $hasDeposit = $reservation->deposits()->where('status', 'PAID')->exists();
        if ($hasDeposit && empty($validated['deposit_action'])) {
            throw ValidationException::withMessages(['deposit_action' => 'Pilih pengembalian atau forfeiture untuk deposit yang sudah dibayar.']);
        }

        DB::transaction(function () use ($request, $reservation, $validated) {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if (! empty($validated['deposit_action'])) {
                $reservation->deposits()->where('status', 'PAID')->update([
                    'status' => $validated['deposit_action'] === 'REFUND' ? 'REFUNDED' : 'FORFEITED',
                ]);
            }
            $reservation->update([
                'status' => 'CANCELLED',
                'cancelled_at' => now(),
                'cancellation_reason' => $validated['reason'],
            ]);
            ReservationManager::releaseReservedTables($reservation);
            $this->audit($request, $reservation, 'reservation.cancelled', $validated['reason']);
        });

        return response()->json(['message' => 'Reservasi berhasil dibatalkan.', 'data' => $this->loadReservation($reservation->fresh())]);
    }

    public function markNoShow(Request $request, Reservation $reservation): JsonResponse
    {
        abort_unless($reservation->status === 'CONFIRMED', 422, 'Hanya reservasi terkonfirmasi yang dapat ditandai no-show.');
        $deadline = $reservation->reserved_at->copy()->addMinutes($reservation->arrival_grace_minutes ?: 15);
        abort_if(now()->lt($deadline), 422, 'Masa toleransi kedatangan belum berakhir.');
        $this->transition($request, $reservation, ['CONFIRMED'], 'NO_SHOW', ['no_show_at' => now()]);
        ReservationManager::releaseReservedTables($reservation->fresh());

        return response()->json(['message' => 'Reservasi ditandai tidak hadir.', 'data' => $this->loadReservation($reservation->fresh())]);
    }

    public function addDeposit(Request $request, Reservation $reservation): JsonResponse
    {
        abort_unless(in_array($reservation->status, ['PENDING', 'CONFIRMED', 'ARRIVED'], true), 422, 'Reservasi ini tidak menerima deposit.');
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'payment_method' => ['nullable', 'string', Rule::in(['CASH', 'QRIS_MANUAL', 'TRANSFER', 'DEBIT', 'KREDIT', 'VOUCHER'])],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $deposit = DB::transaction(function () use ($request, $reservation, $validated) {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $paidTotal = (float) $reservation->deposits()->where('status', 'PAID')->sum('amount');
            $target = (float) $reservation->deposit_required_amount;
            abort_if($target > 0 && $paidTotal + (float) $validated['amount'] > $target, 422, 'Nominal deposit melebihi target DP reservasi.');

            return Deposit::query()->create([
                'reservation_id' => $reservation->id,
                'customer_id' => $reservation->customer_id,
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'] ?? 'CASH',
                'reference_no' => $validated['reference_no'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'received_by' => $request->user()->id,
                'received_at' => now(),
                'status' => 'PAID',
            ]);
        });
        $this->audit($request, $reservation, 'deposit.created');

        return response()->json(['message' => 'Deposit berhasil ditambahkan.', 'data' => $deposit], 201);
    }

    public function convertToBill(Request $request, Reservation $reservation): JsonResponse
    {
        abort_unless($reservation->status === 'ARRIVED', 422, 'Catat kedatangan tamu sebelum membuat bill.');

        $bill = DB::transaction(function () use ($reservation, $request) {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $tableIds = ReservationManager::tableIds($reservation);
            abort_if(BillTableManager::activeBillExistsOnAnyTable($tableIds), 422, 'Salah satu meja reservasi sudah memiliki bill aktif.');
            $depositTotal = (float) $reservation->deposits()->where('status', 'PAID')->sum('amount');

            $bill = Bill::query()->create([
                'bill_no' => SequenceNumber::generate('BILL', Bill::class, 'bill_no'),
                'bill_type' => 'RESERVATION',
                'table_id' => $tableIds[0] ?? null,
                'customer_id' => $reservation->customer_id,
                'customer_name' => $reservation->guest_name,
                'reservation_id' => $reservation->id,
                'opened_by' => $request->user()->id,
                'cashier_id' => $request->user()->id,
                'guest_count' => $reservation->guest_count,
                'status' => 'OPEN',
                'paid_total' => $depositTotal,
                'opened_at' => now(),
            ]);

            $reservation->update(['status' => 'CONVERTED', 'seated_at' => now()]);
            BillTableManager::syncBillTables($bill, $tableIds);
            BillTableManager::updateBillTablesStatus($bill, 'OPEN_BILL');

            $reservation->deposits()->where('status', 'PAID')->get()->each(function (Deposit $deposit) use ($bill): void {
                $payment = $deposit->payment ?: Payment::query()->create([
                    'bill_id' => $bill->id,
                    'payment_no' => SequenceNumber::generate('PAY', Payment::class, 'payment_no'),
                    'payment_method' => $deposit->payment_method ?? 'CASH',
                    'payment_type' => 'DEPOSIT',
                    'amount' => $deposit->amount,
                    'reference_no' => $deposit->reference_no,
                    'paid_by' => $deposit->received_by,
                    'paid_at' => $deposit->received_at ?? now(),
                    'status' => 'PAID',
                ]);
                $deposit->update(['bill_id' => $bill->id, 'payment_id' => $payment->id]);
            });

            $bill = BillTotals::recalculate($bill);
            $this->audit($request, $reservation, 'reservation.converted_to_bill');

            return $bill;
        });

        return response()->json([
            'message' => 'Reservasi berhasil dikonversi ke bill.',
            'data' => $bill->load(['table:id,code,name,status', 'tables:id,code,name,status,capacity,area']),
        ], 201);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_phone' => ['required', 'string', 'max:32'],
            'table_id' => ['required', 'integer', 'exists:tables,id'],
            'extra_table_ids' => ['nullable', 'array', 'max:20'],
            'extra_table_ids.*' => ['integer', 'distinct', 'exists:tables,id'],
            'reserved_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:30', 'max:720'],
            'arrival_grace_minutes' => ['nullable', 'integer', 'min:5', 'max:120'],
            'guest_count' => ['required', 'integer', 'min:1', 'max:500'],
            'deposit_required_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'source' => ['nullable', Rule::in(self::SOURCES)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'cancellation_policy' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function transition(Request $request, Reservation $reservation, array $from, string $to, array $attributes): void
    {
        abort_unless(in_array($reservation->status, $from, true), 422, 'Perubahan status reservasi tidak valid.');
        $reservation->update(['status' => $to, ...$attributes]);
        $this->audit($request, $reservation, 'reservation.'.strtolower($to));
    }

    private function audit(Request $request, Reservation $reservation, string $action, ?string $reason = null): void
    {
        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: $action,
            entityType: 'reservation',
            entityId: $reservation->id,
            after: $reservation->toArray(),
            reason: $reason,
        );
    }

    private function loadReservation(Reservation $reservation): Reservation
    {
        return $reservation->load([
            'customer:id,name,phone,member_code',
            'table:id,code,name,capacity,area,status',
            'tables:id,code,name,capacity,area,status',
        ])->loadSum(['deposits as deposit_total' => fn ($query) => $query->where('status', 'PAID')], 'amount');
    }
}
