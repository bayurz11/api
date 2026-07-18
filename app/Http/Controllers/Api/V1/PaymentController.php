<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Payment;
use App\Support\AuditLogger;
use App\Support\BillTableManager;
use App\Support\BillTotals;
use App\Support\PaymentSummary;
use App\Support\SequenceNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    private const PAYMENT_METHODS = ['CASH', 'QRIS_MANUAL', 'TRANSFER', 'DEBIT', 'KREDIT', 'VOUCHER'];

    public function index(Bill $bill): JsonResponse
    {
        return response()->json([
            'data' => $bill->payments()->latest('id')->get(),
            'summary' => PaymentSummary::forBill($bill->fresh('payments')),
        ]);
    }

    public function store(Request $request, Bill $bill): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'string', Rule::in(self::PAYMENT_METHODS)],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:1', 'max:9999999999.99'],
            'reference_no' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $payment = DB::transaction(function () use ($bill, $validated, $user) {
            $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            $this->ensureBillAcceptsPayment($bill);
            $this->ensureAmountDoesNotOverpay($bill, $this->toMinorUnits($validated['amount']));

            $payment = Payment::query()->create([
                'bill_id' => $bill->id,
                'payment_no' => SequenceNumber::generate('PAY', Payment::class, 'payment_no'),
                'payment_method' => $validated['payment_method'],
                'amount' => $validated['amount'],
                'reference_no' => $validated['reference_no'] ?? null,
                'paid_by' => $user->id,
                'paid_at' => now(),
                'status' => 'PAID',
            ]);

            $bill = BillTotals::recalculate($bill);

            if ($bill->status === 'PAID') {
                BillTableManager::updateBillTablesStatus($bill, 'CLEANING');
            }

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'payment.created',
                entityType: 'payment',
                entityId: $payment->id,
                after: $payment->toArray(),
            );

            return $payment;
        });

        return response()->json([
            'message' => 'Pembayaran berhasil ditambahkan.',
            'data' => $payment,
            'bill' => $bill->fresh(),
            'summary' => PaymentSummary::forBill($bill->fresh('payments')),
        ], 201);
    }

    public function split(Request $request, Bill $bill): JsonResponse
    {
        $validated = $request->validate([
            'payments' => ['required', 'array', 'min:2', 'max:20'],
            'payments.*.payment_method' => ['required', 'string', Rule::in(self::PAYMENT_METHODS)],
            'payments.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:1', 'max:9999999999.99'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $payments = DB::transaction(function () use ($bill, $validated, $user) {
            $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            $this->ensureBillAcceptsPayment($bill);

            $splitTotalMinor = collect($validated['payments'])
                ->sum(fn (array $payment) => $this->toMinorUnits($payment['amount']));

            $this->ensureAmountDoesNotOverpay($bill, $splitTotalMinor);

            $createdPayments = collect();

            foreach ($validated['payments'] as $paymentRow) {
                $payment = Payment::query()->create([
                    'bill_id' => $bill->id,
                    'payment_no' => SequenceNumber::generate('PAY', Payment::class, 'payment_no'),
                    'payment_method' => $paymentRow['payment_method'],
                    'amount' => $paymentRow['amount'],
                    'reference_no' => $paymentRow['reference_no'] ?? null,
                    'paid_by' => $user->id,
                    'paid_at' => now(),
                    'status' => 'PAID',
                ]);

                $createdPayments->push($payment);
            }

            $bill = BillTotals::recalculate($bill);

            if ($bill->status === 'PAID') {
                BillTableManager::updateBillTablesStatus($bill, 'CLEANING');
            }

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'payment.split_created',
                entityType: 'bill',
                entityId: $bill->id,
                after: [
                    'payments_count' => $createdPayments->count(),
                    'split_total' => $splitTotalMinor / 100,
                ],
            );

            return $createdPayments;
        });

        return response()->json([
            'message' => 'Split payment berhasil ditambahkan.',
            'data' => $payments->values(),
            'bill' => $bill->fresh(),
            'summary' => PaymentSummary::forBill($bill->fresh('payments')),
        ], 201);
    }

    public function close(Request $request, Bill $bill): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($bill, $user) {
            $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            $bill = BillTotals::recalculate($bill);
            abort_if($this->toMinorUnits($bill->balance_due) > 0, 422, 'Bill belum lunas.');

            $bill->update([
                'status' => 'PAID',
                'closed_at' => $bill->closed_at ?? now(),
            ]);

            BillTableManager::updateBillTablesStatus($bill, 'CLEANING');

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'bill.closed',
                entityType: 'bill',
                entityId: $bill->id,
                after: ['status' => 'PAID'],
            );
        });

        return response()->json([
            'message' => 'Bill berhasil ditutup.',
            'data' => $bill->fresh('table'),
            'summary' => PaymentSummary::forBill($bill->fresh('payments')),
        ]);
    }

    public function void(Request $request, Payment $payment): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        DB::transaction(function () use ($payment, $validated, $user) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_if($payment->status === 'VOID', 422, 'Payment sudah di-void.');
            $bill = Bill::query()->lockForUpdate()->findOrFail($payment->bill_id);

            $payment->update([
                'status' => 'VOID',
            ]);

            $bill = BillTotals::recalculate($bill);

            $tableStatus = in_array($bill->status, ['PAID', 'VOID', 'REFUND'], true)
                ? 'CLEANING'
                : 'OPEN_BILL';

            BillTableManager::updateBillTablesStatus($bill, $tableStatus);

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'payment.voided',
                entityType: 'payment',
                entityId: $payment->id,
                before: ['status' => 'PAID'],
                after: ['status' => 'VOID'],
                reason: $validated['reason'],
            );
        });

        return response()->json([
            'message' => 'Payment berhasil di-void.',
            'data' => $payment->fresh(),
            'bill' => $payment->bill()->first()?->fresh(),
            'summary' => PaymentSummary::forBill($payment->bill()->firstOrFail()->fresh('payments')),
        ]);
    }

    public function refund(Request $request, Bill $bill): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'payment_method' => ['required', 'string', Rule::in(self::PAYMENT_METHODS)],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:1', 'max:9999999999.99'],
        ]);

        $user = $request->user();

        $refundPayment = DB::transaction(function () use ($bill, $validated, $user) {
            $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);

            $paidTotalMinor = $this->toMinorUnits($bill->paid_total);
            abort_if($paidTotalMinor <= 0, 422, 'Bill tidak memiliki pembayaran yang dapat direfund.');
            abort_if($bill->status === 'REFUND', 422, 'Bill sudah direfund.');

            $refundAmountMinor = isset($validated['amount'])
                ? $this->toMinorUnits($validated['amount'])
                : $paidTotalMinor;

            abort_if($refundAmountMinor > $paidTotalMinor, 422, 'Nominal refund melebihi pembayaran bersih.');
            $refundAmount = $refundAmountMinor / 100;

            $refundPayment = Payment::query()->create([
                'bill_id' => $bill->id,
                'payment_no' => SequenceNumber::generate('PAY', Payment::class, 'payment_no'),
                'payment_method' => $validated['payment_method'],
                'amount' => $refundAmount,
                'reference_no' => $validated['reference_no'] ?? null,
                'paid_by' => $user->id,
                'paid_at' => now(),
                'status' => 'REFUND',
            ]);

            $bill = BillTotals::recalculate($bill);

            if ((float) $bill->paid_total <= 0) {
                $bill->update([
                    'status' => 'REFUND',
                    'closed_at' => now(),
                ]);
            }

            BillTableManager::updateBillTablesStatus($bill, 'CLEANING');

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'bill.refunded',
                entityType: 'bill',
                entityId: $bill->id,
                after: [
                    'refund_payment_id' => $refundPayment->id,
                    'refund_amount' => $refundAmount,
                    'status' => $bill->fresh()->status,
                ],
                reason: $validated['reason'],
            );

            return $refundPayment;
        });

        return response()->json([
            'message' => 'Refund bill berhasil diproses.',
            'data' => $refundPayment->fresh(),
            'bill' => $bill->fresh(),
            'summary' => PaymentSummary::forBill($bill->fresh('payments')),
        ], 201);
    }

    private function ensureBillAcceptsPayment(Bill $bill): void
    {
        abort_if(in_array($bill->status, ['PAID', 'CANCELLED', 'VOID', 'REFUND'], true), 422, 'Bill tidak menerima pembayaran.');
        abort_if((float) $bill->grand_total <= 0, 422, 'Bill belum memiliki tagihan untuk dibayar.');
        abort_if(! in_array($bill->status, ['ORDERING', 'READY_TO_PAY', 'SERVED', 'PARTIALLY_PAID'], true), 422, 'Status bill belum siap menerima pembayaran.');
    }

    private function ensureAmountDoesNotOverpay(Bill $bill, int $amountMinor): void
    {
        abort_if(
            $amountMinor > $this->toMinorUnits($bill->balance_due),
            422,
            'Nominal pembayaran melebihi sisa tagihan.',
        );
    }

    private function toMinorUnits(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
