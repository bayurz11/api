<?php

namespace Tests\Feature;

use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationOperationalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_table_capacity_and_schedule_conflict_are_enforced(): void
    {
        $this->seed();
        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $tableOne = Table::query()->where('code', 'T01')->firstOrFail();
        $tableThree = Table::query()->where('code', 'T03')->firstOrFail();
        $reservedAt = now()->addDays(2)->startOfHour();

        $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/reservations', [
            'guest_name' => 'Rombongan Besar',
            'guest_phone' => '081200000001',
            'table_id' => $tableOne->id,
            'reserved_at' => $reservedAt->toIso8601String(),
            'duration_minutes' => 120,
            'guest_count' => $tableOne->capacity + 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('table_ids');

        $created = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/reservations', [
            'guest_name' => 'Rombongan Besar',
            'guest_phone' => '081200000001',
            'table_id' => $tableOne->id,
            'extra_table_ids' => [$tableThree->id],
            'reserved_at' => $reservedAt->toIso8601String(),
            'duration_minutes' => 120,
            'guest_count' => $tableOne->capacity + 1,
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonCount(2, 'data.tables');

        $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/reservations', [
            'guest_name' => 'Jadwal Bentrok',
            'guest_phone' => '081200000002',
            'table_id' => $tableOne->id,
            'reserved_at' => $reservedAt->copy()->addHour()->toIso8601String(),
            'duration_minutes' => 90,
            'guest_count' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('reserved_at');
    }

    public function test_deposit_requires_resolution_when_reservation_is_cancelled(): void
    {
        $this->seed();
        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();

        $reservationId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/reservations', [
            'guest_name' => 'Pelanggan Deposit',
            'guest_phone' => '081200000003',
            'table_id' => $table->id,
            'reserved_at' => now()->addDays(3)->toIso8601String(),
            'guest_count' => 2,
            'deposit_required_amount' => 100000,
        ])->assertCreated()->json('data.id');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservationId}/deposit", ['amount' => 100000])
            ->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservationId}/cancel", ['reason' => 'Permintaan pelanggan'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deposit_action');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservationId}/cancel", [
                'reason' => 'Permintaan pelanggan',
                'deposit_action' => 'REFUND',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $this->assertDatabaseHas('deposits', [
            'reservation_id' => $reservationId,
            'status' => 'REFUNDED',
        ]);
    }
}
