<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        $customers = Customer::query()
            ->when(
                $request->filled('search'),
                fn ($query) => $query->where(function ($innerQuery) use ($request) {
                    $term = $request->string('search')->toString();
                    $innerQuery
                        ->where('name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('member_code', 'like', "%{$term}%");
                }),
            )
            ->latest('id')
            ->paginate($perPage);

        return response()->json($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $customer = DB::transaction(function () use ($validated): Customer {
            $customer = Customer::query()->create([
                ...$validated,
                'reward_points' => 0,
            ]);
            $customer->update([
                'member_code' => sprintf('MBR-%06d', $customer->id),
            ]);

            return $customer;
        });

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'customer.created',
            entityType: 'customer',
            entityId: $customer->id,
            after: $customer->toArray(),
        );

        return response()->json([
            'message' => 'Customer berhasil dibuat.',
            'data' => $customer,
        ], 201);
    }

    public function show(Customer $customer, Request $request): JsonResponse
    {
        $customer->loadCount(['bills', 'reservations']);
        $customer->load([
            'bills' => fn ($query) => $query
                ->select('id', 'bill_no', 'bill_type', 'status', 'customer_id', 'grand_total', 'paid_total', 'opened_at', 'closed_at')
                ->latest('id')
                ->limit(10),
            'reservations' => fn ($query) => $query
                ->select('id', 'customer_id', 'reservation_code', 'status', 'reserved_at', 'guest_count')
                ->latest('id')
                ->limit(10),
        ]);

        return response()->json([
            'data' => $customer,
        ]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'reward_points' => ['sometimes', 'integer', 'min:0'],
        ]);

        $before = $customer->only(['name', 'phone', 'email', 'member_code', 'notes', 'reward_points']);

        $customer->fill($validated);
        $customer->save();

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'customer.updated',
            entityType: 'customer',
            entityId: $customer->id,
            before: $before,
            after: $customer->only(['name', 'phone', 'email', 'member_code', 'notes', 'reward_points']),
        );

        return response()->json([
            'message' => 'Customer berhasil diperbarui.',
            'data' => $customer,
        ]);
    }

    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        abort_if($customer->bills()->exists(), 422, 'Customer sudah memiliki histori bill dan tidak dapat dihapus.');
        abort_if($customer->reservations()->exists(), 422, 'Customer sudah memiliki histori reservasi dan tidak dapat dihapus.');

        $before = $customer->toArray();
        $customer->delete();

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'customer.deleted',
            entityType: 'customer',
            entityId: $before['id'],
            before: $before,
        );

        return response()->json([
            'message' => 'Customer berhasil dihapus.',
        ]);
    }
}
