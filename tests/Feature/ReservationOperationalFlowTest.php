<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReservationOperationalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_roles_can_confirm_reservations_when_permission_cache_or_pivot_is_stale(): void
    {
        $this->seed();

        $permissionId = DB::table('permissions')
            ->where('name', 'reservations.operate')
            ->value('id');
        DB::table('role_has_permissions')
            ->where('permission_id', $permissionId)
            ->delete();

        $admin = User::factory()->create([
            'username' => 'admin-reservasi',
            'is_active' => true,
        ]);
        $admin->assignRole('Admin');

        $users = [
            $admin,
            User::query()->where('username', 'kasir01')->firstOrFail(),
            User::query()->where('username', 'waiter01')->firstOrFail(),
        ];
        $table = Table::query()->where('code', 'T01')->firstOrFail();

        foreach ($users as $index => $user) {
            $reservation = Reservation::query()->create([
                'guest_name' => "Tamu Operasional {$index}",
                'guest_phone' => "08120000010{$index}",
                'table_id' => $table->id,
                'reservation_code' => "RSV-ROLE-{$index}",
                'reserved_at' => now()->addDays($index + 1),
                'guest_count' => 2,
                'status' => 'PENDING',
                'source' => 'PHONE',
            ]);
            $reservation->tables()->attach($table->id);

            $this->actingAs($user, 'sanctum')
                ->postJson("/api/v1/reservations/{$reservation->id}/confirm")
                ->assertOk()
                ->assertJsonPath('data.status', 'CONFIRMED');
        }

        foreach (['kitchen01', 'bar01'] as $index => $username) {
            $reservation = Reservation::query()->create([
                'guest_name' => "Tamu Ditolak {$index}",
                'guest_phone' => "08120000020{$index}",
                'table_id' => $table->id,
                'reservation_code' => "RSV-DENY-{$index}",
                'reserved_at' => now()->addWeek()->addDays($index),
                'guest_count' => 2,
                'status' => 'PENDING',
                'source' => 'PHONE',
            ]);
            $reservation->tables()->attach($table->id);

            $this->actingAs(
                User::query()->where('username', $username)->firstOrFail(),
                'sanctum',
            )->postJson("/api/v1/reservations/{$reservation->id}/confirm")
                ->assertForbidden();
        }
    }

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
