<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_stays_available_when_optional_analytics_schema_is_missing(): void
    {
        $this->seed();
        $owner = User::query()->where('username', 'owner')->firstOrFail();
        Log::spy();

        Schema::drop('ingredient_stock_movements');

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('meta.degraded', true)
            ->assertJsonStructure([
                'summary' => ['total_tables', 'available_tables', 'open_bills'],
                'analytics' => ['sales_trend', 'top_items', 'station_load'],
                'reminders' => ['settings', 'summary', 'items'],
            ]);

        Log::shouldHaveReceived('error')->once();
    }
}
