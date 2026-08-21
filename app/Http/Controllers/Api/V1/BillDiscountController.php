<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillDiscount;
use App\Support\AuditLogger;
use App\Support\BillTotals;
use App\Support\PaymentSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BillDiscountController extends Controller
{
    private const TYPES = ['FIXED', 'PERCENTAGE', 'VOUCHER'];

    public function index(Bill $bill): JsonResponse
    {
        return response()->json([
            'data' => $bill->discounts()
                ->whereNull('voided_at')
                ->with('appliedBy:id,name,username')
                ->latest('id')
                ->get(),
            'summary' => PaymentSummary::forBill($bill->fresh('payments')),
        ]);
    }

    public function store(Request $request, Bill $bill): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'value' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'voucher_code' => ['nullable', 'required_if:type,VOUCHER', 'string', 'max:80'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $user = $request->user();

        $discount = DB::transaction(function () use ($bill, $validated, $user) {
            $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            $this->ensureBillAcceptsDiscount($bill);
            $subtotal = (float) $bill->items()->sum('line_total');
            abort_if($subtotal <= 0, 422, 'Tagihan belum memiliki item untuk diberi diskon.');

            $type = $validated['type'];
            $value = round((float) $validated['value'], 2);
            abort_if($type === 'PERCENTAGE' && $value > 100, 422, 'Persentase diskon maksimal 100%.');

            $voucherCode = isset($validated['voucher_code'])
                ? strtoupper(trim($validated['voucher_code']))
                : null;

            if ($type === 'VOUCHER') {
                $alreadyUsed = BillDiscount::query()
                    ->where('bill_id', $bill->id)
                    ->whereNull('voided_at')
                    ->whereRaw('UPPER(voucher_code) = ?', [$voucherCode])
                    ->exists();
                abort_if($alreadyUsed, 422, 'Kode voucher sudah digunakan pada tagihan ini.');
            }

            $amount = $type === 'PERCENTAGE'
                ? round($subtotal * $value / 100, 2)
                : $value;
            $currentDiscount = (float) BillDiscount::query()
                ->where('bill_id', $bill->id)
                ->whereNull('voided_at')
                ->sum('amount');
            $newDiscountTotal = round($currentDiscount + $amount, 2);

            abort_if($newDiscountTotal > $subtotal, 422, 'Total diskon tidak boleh melebihi subtotal tagihan.');

            $candidateGrandTotal = max($subtotal - $newDiscountTotal, 0)
                + (float) $bill->tax_total
                + (float) $bill->service_total;
            abort_if(
                $candidateGrandTotal < (float) $bill->paid_total,
                422,
                'Diskon membuat total tagihan lebih kecil dari pembayaran yang sudah tercatat.',
            );

            $discount = BillDiscount::query()->create([
                'bill_id' => $bill->id,
                'type' => $type,
                'value' => $value,
                'amount' => $amount,
                'voucher_code' => $voucherCode,
                'reason' => trim($validated['reason']),
                'applied_by' => $user->id,
            ]);

            $bill->update(['discount_total' => $newDiscountTotal]);
            BillTotals::recalculate($bill);

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'bill.discount_applied',
                entityType: 'bill_discount',
                entityId: $discount->id,
                after: $discount->toArray(),
                reason: $discount->reason,
            );

            return $discount;
        });

        return response()->json([
            'message' => 'Diskon berhasil diterapkan.',
            'data' => $discount->load('appliedBy:id,name,username'),
            'bill' => $bill->fresh(),
            'summary' => PaymentSummary::forBill($bill->fresh('payments')),
        ], 201);
    }

    public function destroy(Request $request, Bill $bill, BillDiscount $discount): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);
        abort_unless($discount->bill_id === $bill->id, 404);
        $user = $request->user();

        DB::transaction(function () use ($bill, $discount, $validated, $user) {
            $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            $discount = BillDiscount::query()->lockForUpdate()->findOrFail($discount->id);
            $this->ensureBillAcceptsDiscount($bill);
            abort_if($discount->voided_at !== null, 422, 'Diskon sudah dibatalkan.');

            $discount->update([
                'voided_at' => now(),
                'voided_by' => $user->id,
                'void_reason' => trim($validated['reason']),
            ]);

            $discountTotal = (float) BillDiscount::query()
                ->where('bill_id', $bill->id)
                ->whereNull('voided_at')
                ->sum('amount');
            $bill->update(['discount_total' => $discountTotal]);
            BillTotals::recalculate($bill);

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'bill.discount_voided',
                entityType: 'bill_discount',
                entityId: $discount->id,
                before: ['voided_at' => null],
                after: $discount->fresh()->toArray(),
                reason: $validated['reason'],
            );
        });

        return response()->json([
            'message' => 'Diskon berhasil dibatalkan.',
            'bill' => $bill->fresh(),
            'summary' => PaymentSummary::forBill($bill->fresh('payments')),
        ]);
    }

    private function ensureBillAcceptsDiscount(Bill $bill): void
    {
        abort_if(
            in_array($bill->status, ['PAID', 'CANCELLED', 'VOID', 'REFUND'], true),
            422,
            'Diskon tidak dapat diubah pada tagihan yang sudah selesai.',
        );
    }
}
