<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\RestaurantProfileController;
use App\Models\Bill;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Printer;
use App\Models\QrOrder;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verify the API health endpoint is available.
     */
    public function test_api_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'service' => 'restopos-api',
                'version' => 'v1',
            ]);
    }

    /**
     * Verify demo user can log in with Sanctum.
     */
    public function test_user_can_login_and_receive_token(): void
    {
        $this->seed();

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'kasir01',
            'password' => 'password',
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'username', 'email', 'roles', 'permissions'],
            ]);

        $this->assertDatabaseHas('users', [
            'username' => 'kasir01',
        ]);

        $this->assertInstanceOf(User::class, User::query()->where('username', 'kasir01')->first());
    }

    /**
     * Verify authenticated user can read tables and menus.
     */
    public function test_authenticated_user_can_list_tables_and_menus(): void
    {
        $this->seed();

        $user = User::query()->where('username', 'kasir01')->firstOrFail();
        $tableCount = Table::query()->count();
        $availableMenuCount = Menu::query()->where('is_available', true)->count();

        $tablesResponse = $this->actingAs($user, 'sanctum')->getJson('/api/v1/tables');
        $tablesResponse
            ->assertOk()
            ->assertJsonCount($tableCount, 'data');

        $menusResponse = $this->actingAs($user, 'sanctum')->getJson('/api/v1/menus?available_only=1');
        $menusResponse
            ->assertOk()
            ->assertJsonCount($availableMenuCount, 'data');
    }

    /**
     * Verify bill and order flow works for MVP.
     */
    public function test_authenticated_user_can_create_bill_and_send_order(): void
    {
        $this->seed();

        $user = User::query()->where('username', 'kasir01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $menu = Menu::query()->where('sku', 'MKN-001')->firstOrFail();

        $billResponse = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ]);

        $billResponse
            ->assertCreated()
            ->assertJsonPath('data.table.id', $table->id);

        $billId = $billResponse->json('data.id');

        $orderResponse = $this->actingAs($user, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                [
                    'menu_id' => $menu->id,
                    'qty' => 2,
                    'notes' => 'Tidak pedas',
                ],
            ],
        ]);

        $orderResponse
            ->assertCreated()
            ->assertJsonPath('data.items.0.station_type', 'KITCHEN');

        $this->assertDatabaseHas('bills', [
            'id' => $billId,
            'status' => 'ORDERING',
        ]);

        $this->assertDatabaseHas('tables', [
            'id' => $table->id,
            'status' => 'OPEN_BILL',
        ]);

        $bill = Bill::query()->findOrFail($billId);
        $this->assertSame('56000.00', $bill->grand_total);
    }

    /**
     * Verify dine in bill can span multiple tables when guest count exceeds one table capacity.
     */
    public function test_authenticated_user_can_create_multi_table_bill(): void
    {
        $this->seed();

        $user = User::query()->where('username', 'kasir01')->firstOrFail();
        $primaryTable = Table::query()->where('code', 'T01')->firstOrFail();
        $secondaryTable = Table::query()->where('code', 'T02')->firstOrFail();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $primaryTable->id,
            'extra_table_ids' => [$secondaryTable->id],
            'guest_count' => 7,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.table.id', $primaryTable->id)
            ->assertJsonCount(2, 'data.tables');

        $billId = $response->json('data.id');

        $this->assertDatabaseHas('bill_tables', [
            'bill_id' => $billId,
            'table_id' => $primaryTable->id,
        ]);
        $this->assertDatabaseHas('bill_tables', [
            'bill_id' => $billId,
            'table_id' => $secondaryTable->id,
        ]);
        $this->assertDatabaseHas('tables', [
            'id' => $primaryTable->id,
            'status' => 'OPEN_BILL',
        ]);
        $this->assertDatabaseHas('tables', [
            'id' => $secondaryTable->id,
            'status' => 'OPEN_BILL',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bills?table_id='.$secondaryTable->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $billId);
    }

    /**
     * Verify unavailable menu is rejected from ordering.
     */
    public function test_unavailable_menu_cannot_be_ordered(): void
    {
        $this->seed();

        $user = User::query()->where('username', 'kasir01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $menu = Menu::query()->where('sku', 'MKN-001')->firstOrFail();
        $menu->update(['is_available' => false]);

        $billId = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $menu->id, 'qty' => 1],
            ],
        ]);

        $response->assertStatus(422);
    }

    /**
     * Verify station queue and payment flow work.
     */
    public function test_station_status_and_payment_flow_work(): void
    {
        $this->seed();

        $user = User::query()->where('username', 'kasir01')->firstOrFail();
        $waiter = User::query()->where('username', 'waiter01')->firstOrFail();
        $kitchen = User::query()->where('username', 'kitchen01')->firstOrFail();
        $barUser = User::query()->where('username', 'bar01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $food = Menu::query()->where('sku', 'MKN-001')->firstOrFail();
        $drink = Menu::query()->where('sku', 'MNM-001')->firstOrFail();

        $billId = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1, 'notes' => 'Tidak pedas'],
                ['menu_id' => $drink->id, 'qty' => 1, 'notes' => 'Less sugar'],
            ],
        ])->assertCreated();

        $this->actingAs($kitchen, 'sanctum')
            ->getJson('/api/v1/kitchen/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($barUser, 'sanctum')
            ->getJson('/api/v1/bar/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $kitchenItem = OrderItem::query()->where('station_type', 'KITCHEN')->firstOrFail();
        $barItem = OrderItem::query()->where('station_type', 'BAR')->firstOrFail();

        $this->actingAs($kitchen, 'sanctum')
            ->patchJson("/api/v1/order-items/{$kitchenItem->id}/status", ['status' => 'READY'])
            ->assertOk();

        $this->actingAs($barUser, 'sanctum')
            ->patchJson("/api/v1/order-items/{$barItem->id}/status", ['status' => 'READY'])
            ->assertOk();

        $this->actingAs($waiter, 'sanctum')
            ->patchJson("/api/v1/order-items/{$barItem->id}/status", ['status' => 'SERVED'])
            ->assertOk();

        $this->actingAs($waiter, 'sanctum')
            ->patchJson("/api/v1/order-items/{$kitchenItem->id}/status", ['status' => 'SERVED'])
            ->assertOk();

        $paymentResponse = $this->actingAs($user, 'sanctum')->postJson("/api/v1/bills/{$billId}/payments", [
            'payment_method' => 'CASH',
            'amount' => 36000,
        ]);

        $paymentResponse
            ->assertCreated()
            ->assertJsonPath('bill.status', 'PAID');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bills/{$billId}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID');

        $this->assertDatabaseHas('tables', [
            'id' => $table->id,
            'status' => 'CLEANING',
        ]);

        $table->update(['status' => 'AVAILABLE']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/mark-ready")
            ->assertOk()
            ->assertJsonPath('message', 'Meja ini sudah siap digunakan.')
            ->assertJsonPath('data.status', 'AVAILABLE');

        $table->update(['status' => 'CLEANING']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/mark-read")
            ->assertOk()
            ->assertJsonPath('message', 'Meja ini sudah siap digunakan.')
            ->assertJsonPath('data.status', 'AVAILABLE');
    }

    /**
     * Verify dashboard analytics payload is available for operational roles.
     */
    public function test_dashboard_analytics_payload_is_available(): void
    {
        $this->seed();

        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $food = Menu::query()->where('sku', 'MKN-001')->firstOrFail();

        $billId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 2],
            ],
        ])->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/bills/{$billId}/payments", [
                'payment_method' => 'CASH',
                'amount' => 56000,
            ])
            ->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('summary.today_bills', 1)
            ->assertJsonStructure([
                'summary' => [
                    'total_tables',
                    'available_tables',
                    'occupied_tables',
                    'open_bills',
                    'ready_to_pay_bills',
                    'today_sales',
                    'today_bills',
                    'average_bill',
                    'sales_growth_percent',
                ],
                'analytics' => [
                    'sales_trend',
                    'top_items',
                    'payment_methods',
                    'bill_types',
                    'station_load' => [
                        'kitchen' => ['waiting_count', 'processing_count', 'ready_count'],
                        'bar' => ['waiting_count', 'processing_count', 'ready_count'],
                        'waiter' => ['ready_to_serve_count', 'served_today_count'],
                    ],
                ],
            ]);
    }

    /**
     * Verify permission and transfer table flow.
     */
    public function test_permission_boundaries_and_transfer_table_work(): void
    {
        $this->seed();

        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $waiter = User::query()->where('username', 'waiter01')->firstOrFail();
        $kitchen = User::query()->where('username', 'kitchen01')->firstOrFail();
        $tableOne = Table::query()->where('code', 'T01')->firstOrFail();
        $tableTwo = Table::query()->where('code', 'T02')->firstOrFail();

        $billId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $tableOne->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/v1/bills/{$billId}/transfer-table", ['table_id' => $tableTwo->id])
            ->assertStatus(403);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/bills/{$billId}/transfer-table", ['table_id' => $tableTwo->id])
            ->assertOk()
            ->assertJsonPath('data.table.id', $tableTwo->id);

        $this->actingAs($kitchen, 'sanctum')
            ->getJson('/api/v1/bills')
            ->assertStatus(403);
    }

    /**
     * Verify customer, reservation, deposit, and convert flow work.
     */
    public function test_customer_reservation_deposit_and_convert_flow_work(): void
    {
        $this->seed();

        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $table = Table::query()->where('code', 'T02')->firstOrFail();

        $customerResponse = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/customers', [
            'name' => 'Siti Aminah',
            'phone' => '081298765432',
            'member_code' => 'MBR-002',
            'notes' => 'Sering reservasi keluarga',
        ]);

        $customerResponse
            ->assertCreated()
            ->assertJsonPath('data.member_code', 'MBR-002');

        $customerId = $customerResponse->json('data.id');

        $reservationResponse = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/reservations', [
            'customer_id' => $customerId,
            'table_id' => $table->id,
            'reserved_at' => now()->addDay()->toIso8601String(),
            'guest_count' => 6,
            'notes' => 'Acara keluarga',
        ]);

        $reservationResponse->assertCreated();
        $reservationId = $reservationResponse->json('data.id');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservationId}/deposit", [
                'amount' => 250000,
            ])
            ->assertCreated();

        $convertResponse = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservationId}/convert-to-bill");

        $convertResponse
            ->assertCreated()
            ->assertJsonPath('data.customer_id', $customerId)
            ->assertJsonPath('data.paid_total', '250000.00');

        $customer = Customer::query()->findOrFail($customerId);
        $reservation = Reservation::query()->findOrFail($reservationId);

        $this->assertSame('CONVERTED', $reservation->status);
        $this->assertDatabaseHas('bills', [
            'reservation_id' => $reservationId,
            'customer_id' => $customer->id,
        ]);
        $this->assertDatabaseHas('deposits', [
            'reservation_id' => $reservationId,
            'bill_id' => $convertResponse->json('data.id'),
        ]);
    }

    /**
     * Verify bill totals can be adjusted and partial payment is reflected.
     */
    public function test_bill_adjustment_and_partial_payment_flow_work(): void
    {
        $this->seed();

        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $food = Menu::query()->where('sku', 'MKN-001')->firstOrFail();

        $billId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 2],
            ],
        ])->assertCreated();

        $adjustResponse = $this->actingAs($cashier, 'sanctum')->patchJson("/api/v1/bills/{$billId}", [
            'discount_total' => 6000,
            'tax_total' => 5000,
            'service_total' => 3000,
        ]);

        $adjustResponse
            ->assertOk()
            ->assertJsonPath('data.subtotal', '56000.00')
            ->assertJsonPath('data.discount_total', '6000.00')
            ->assertJsonPath('data.tax_total', '5000.00')
            ->assertJsonPath('data.service_total', '3000.00')
            ->assertJsonPath('data.grand_total', '58000.00')
            ->assertJsonPath('data.balance_due', '58000.00');

        $partialPayment = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/payments", [
            'payment_method' => 'CASH',
            'amount' => 20000,
        ]);

        $partialPayment
            ->assertCreated()
            ->assertJsonPath('bill.status', 'PARTIALLY_PAID')
            ->assertJsonPath('bill.balance_due', '38000.00')
            ->assertJsonPath('summary.net_paid_total', '20000.00')
            ->assertJsonPath('summary.remaining_payment_total', '38000.00')
            ->assertJsonPath('summary.payment_progress.is_partial', true);

        $this->assertDatabaseHas('bills', [
            'id' => $billId,
            'status' => 'PARTIALLY_PAID',
        ]);
    }

    /**
     * Verify paid payment can be voided and bill can be reopened or voided safely.
     */
    public function test_void_payment_reopen_bill_and_void_bill_flow_work(): void
    {
        $this->seed();

        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $food = Menu::query()->where('sku', 'MKN-001')->firstOrFail();

        $billId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $paymentResponse = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/payments", [
            'payment_method' => 'CASH',
            'amount' => 28000,
        ]);

        $paymentResponse->assertCreated();
        $paymentId = $paymentResponse->json('data.id');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/payments/{$paymentId}/void", [
                'reason' => 'Salah input nominal',
            ])
            ->assertOk()
            ->assertJsonPath('bill.status', 'ORDERING');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/bills/{$billId}/void", [
                'reason' => 'Customer batal makan',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'VOID');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/bills/{$billId}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'OPEN');

        $paidBillId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => Table::query()->where('code', 'T02')->firstOrFail()->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$paidBillId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$paidBillId}/payments", [
            'payment_method' => 'CASH',
            'amount' => 28000,
        ])->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/bills/{$paidBillId}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'SERVED');
    }

    /**
     * Verify split payment can settle a bill using multiple methods.
     */
    public function test_split_payment_flow_work(): void
    {
        $this->seed();

        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $food = Menu::query()->where('sku', 'MKN-001')->firstOrFail();

        $billId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 2],
            ],
        ])->assertCreated();

        $response = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/split-payment", [
            'payments' => [
                [
                    'payment_method' => 'CASH',
                    'amount' => 30000,
                ],
                [
                    'payment_method' => 'DEBIT',
                    'amount' => 26000,
                    'reference_no' => 'EDC-001',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('bill.status', 'PAID')
            ->assertJsonPath('bill.balance_due', '0.00');

        $this->assertDatabaseHas('payments', [
            'bill_id' => $billId,
            'payment_method' => 'CASH',
            'amount' => 30000,
        ]);

        $this->assertDatabaseHas('payments', [
            'bill_id' => $billId,
            'payment_method' => 'DEBIT',
            'amount' => 26000,
        ]);
    }

    /**
     * Verify non-table bill and customer bill flow work.
     */
    public function test_non_table_and_customer_bill_flow_work(): void
    {
        $this->seed();

        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $customer = Customer::query()->where('member_code', 'MBR-001')->firstOrFail();

        $takeAwayResponse = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'TAKE_AWAY',
            'guest_count' => 1,
        ]);

        $takeAwayResponse
            ->assertCreated()
            ->assertJsonPath('data.bill_type', 'TAKE_AWAY')
            ->assertJsonPath('data.table_id', null);

        $manualCustomerResponse = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'TAKE_AWAY',
            'customer_name' => 'Budi Pickup',
            'guest_count' => 1,
        ]);

        $manualCustomerResponse
            ->assertCreated()
            ->assertJsonPath('data.bill_type', 'TAKE_AWAY')
            ->assertJsonPath('data.customer_name', 'Budi Pickup');

        $cateringResponse = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'CATERING',
            'customer_name' => 'Acara Kantor',
            'guest_count' => 15,
        ]);

        $cateringResponse
            ->assertCreated()
            ->assertJsonPath('data.bill_type', 'CATERING')
            ->assertJsonPath('data.customer_name', 'Acara Kantor')
            ->assertJsonPath('data.table_id', null);

        $customerBillResponse = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'CUSTOMER',
            'customer_id' => $customer->id,
            'guest_count' => 1,
        ]);

        $customerBillResponse
            ->assertCreated()
            ->assertJsonPath('data.bill_type', 'CUSTOMER')
            ->assertJsonPath('data.customer.id', $customer->id);

        $billsResponse = $this->actingAs($cashier, 'sanctum')->getJson("/api/v1/bills?customer_id={$customer->id}&bill_type=CUSTOMER");
        $billsResponse->assertOk();

        $customerDetailResponse = $this->actingAs($cashier, 'sanctum')->getJson("/api/v1/customers/{$customer->id}");
        $customerDetailResponse
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id);
    }

    /**
     * Verify fully paid bill can be refunded and tracked.
     */
    public function test_refund_bill_flow_work(): void
    {
        $this->seed();

        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $food = Menu::query()->where('sku', 'MKN-001')->firstOrFail();

        $billId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/payments", [
            'payment_method' => 'CASH',
            'amount' => 28000,
        ])->assertCreated();

        $refundResponse = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/refund", [
            'payment_method' => 'CASH',
            'reason' => 'Customer komplain kualitas makanan',
        ]);

        $refundResponse
            ->assertCreated()
            ->assertJsonPath('data.status', 'REFUND')
            ->assertJsonPath('bill.status', 'REFUND')
            ->assertJsonPath('bill.paid_total', '0.00');

        $this->assertDatabaseHas('payments', [
            'bill_id' => $billId,
            'status' => 'REFUND',
            'amount' => 28000,
        ]);
    }

    /**
     * Verify print jobs can be created for kitchen, bar, pre-bill, and final receipt.
     */
    public function test_print_jobs_flow_work(): void
    {
        $this->seed();

        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $food = Menu::query()->where('sku', 'MKN-001')->firstOrFail();
        $drink = Menu::query()->where('sku', 'MNM-001')->firstOrFail();

        $billId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $orderResponse = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
                ['menu_id' => $drink->id, 'qty' => 1],
            ],
        ]);

        $orderId = $orderResponse->json('data.id');

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/print/kitchen-ticket', ['order_id' => $orderId])
            ->assertCreated()
            ->assertJsonPath('data.job_type', 'KITCHEN_TICKET');

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/print/bar-ticket', ['order_id' => $orderId])
            ->assertCreated()
            ->assertJsonPath('data.job_type', 'BAR_TICKET');

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/print/pre-bill', ['bill_id' => $billId])
            ->assertCreated()
            ->assertJsonPath('data.job_type', 'PRE_BILL');

        $preBillPdfResponse = $this->actingAs($cashier, 'sanctum')
            ->get("/api/v1/print/pre-bill/{$billId}/pdf");

        $preBillPdfResponse->assertOk();
        $this->assertStringContainsString('application/pdf', $preBillPdfResponse->headers->get('content-type', ''));

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/payments", [
            'payment_method' => 'CASH',
            'amount' => 36000,
        ])->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/print/receipt', ['bill_id' => $billId])
            ->assertCreated()
            ->assertJsonPath('data.job_type', 'RECEIPT');

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/v1/print-jobs')
            ->assertOk();

        $printerId = Printer::query()->create([
            'name' => 'Cashier Printer',
            'printer_type' => 'ESC_POS',
            'connection_type' => 'LAN',
            'address' => '10.10.10.10',
            'station_type' => null,
            'is_active' => true,
        ])->id;

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/v1/printers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Cashier Printer');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/printers/{$printerId}/test")
            ->assertCreated()
            ->assertJsonPath('data.job_type', 'TEST_RECEIPT');
    }

    /**
     * Verify restaurant profile can be updated and final receipt PDF downloaded.
     */
    public function test_restaurant_profile_and_receipt_pdf_flow_work(): void
    {
        Storage::fake('public');
        $this->seed();

        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $food = Menu::query()->where('sku', 'MKN-001')->firstOrFail();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/settings/restaurant-profile', [
                'restaurant_name' => 'Warung Babeh',
                'restaurant_address' => 'Jl. Contoh No. 12, Jakarta',
                'restaurant_logo' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk()
            ->assertJsonPath('data.restaurant_name', 'Warung Babeh');

        $this->assertSame('Warung Babeh', Setting::getValue('restaurant_name'));
        $this->assertSame('Jl. Contoh No. 12, Jakarta', Setting::getValue('restaurant_address'));
        $this->get('/api/v1/restaurant-profile/logo')->assertOk();

        $logoPath = Setting::getValue('restaurant_logo_path');
        $this->assertIsString($logoPath);
        $this->assertTrue(Storage::disk('public')->exists($logoPath));

        $billId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/payments", [
            'payment_method' => 'CASH',
            'amount' => 28000,
        ])->assertCreated();

        $bill = Bill::query()->with(['table', 'customer', 'items', 'payments'])->findOrFail($billId);
        $profile = RestaurantProfileController::profilePayload();
        $profile['restaurant_logo_path'] = Storage::disk('public')->path($logoPath);

        $html = view('pdf.receipt', [
            'bill' => $bill,
            'profile' => $profile,
            'customerName' => $bill->customer?->name ?: $bill->customer_name,
        ])->render();

        $this->assertStringContainsString('<img src="', $html);
        $this->assertStringContainsString('class="logo"', $html);

        $pdfResponse = $this->actingAs($cashier, 'sanctum')
            ->get("/api/v1/print/receipt/{$billId}/pdf");

        $pdfResponse->assertOk();
        $this->assertStringContainsString('application/pdf', $pdfResponse->headers->get('content-type', ''));
    }

    /**
     * Verify two active bills can be merged into one target bill.
     */
    public function test_merge_bill_flow_work(): void
    {
        $this->seed();

        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $tableOne = Table::query()->where('code', 'T01')->firstOrFail();
        $tableTwo = Table::query()->where('code', 'T02')->firstOrFail();
        $food = Menu::query()->where('sku', 'MKN-001')->firstOrFail();

        $sourceBillId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $tableOne->id,
            'guest_count' => 2,
        ])->json('data.id');

        $targetBillId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $tableTwo->id,
            'guest_count' => 3,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$sourceBillId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$targetBillId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 2],
            ],
        ])->assertCreated();

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$sourceBillId}/payments", [
            'payment_method' => 'CASH',
            'amount' => 28000,
        ])->assertCreated();

        $response = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$sourceBillId}/merge", [
            'target_bill_id' => $targetBillId,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $targetBillId)
            ->assertJsonPath('data.guest_count', 5);

        $this->assertDatabaseHas('bills', [
            'id' => $sourceBillId,
            'status' => 'CANCELLED',
        ]);

        $this->assertDatabaseHas('bill_items', [
            'bill_id' => $targetBillId,
            'qty' => 1,
        ]);

        $this->assertDatabaseHas('payments', [
            'bill_id' => $targetBillId,
            'amount' => 28000,
        ]);
    }

    /**
     * Verify selected bill item quantities can be split into a new bill and related order items follow.
     */
    public function test_split_bill_flow_work(): void
    {
        $this->seed();

        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $food = Menu::query()->where('sku', 'MKN-001')->firstOrFail();
        $drink = Menu::query()->where('sku', 'MNM-001')->firstOrFail();
        $customer = Customer::query()->where('member_code', 'MBR-001')->firstOrFail();

        $billId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
                ['menu_id' => $drink->id, 'qty' => 3],
            ],
        ])->assertCreated();

        $sourceBill = Bill::query()->with(['items', 'orders.items'])->findOrFail($billId);
        $drinkItem = $sourceBill->items->firstWhere('menu_id', $drink->id);

        $response = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/split", [
            'items' => [
                [
                    'bill_item_id' => $drinkItem->id,
                    'qty' => 1,
                ],
            ],
            'customer_id' => $customer->id,
            'guest_count' => 1,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bill_type', 'CUSTOMER')
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.guest_count', 1)
            ->assertJsonPath('data.subtotal', '8000.00')
            ->assertJsonPath('data.balance_due', '8000.00');

        $newBillId = $response->json('data.id');

        $this->assertDatabaseHas('bill_items', [
            'bill_id' => $newBillId,
            'menu_id' => $drink->id,
            'qty' => 1,
        ]);

        $this->assertDatabaseHas('bills', [
            'id' => $billId,
            'subtotal' => 44000,
            'balance_due' => 44000,
        ]);

        $this->assertDatabaseHas('bills', [
            'id' => $newBillId,
            'bill_type' => 'CUSTOMER',
            'subtotal' => 8000,
            'balance_due' => 8000,
            'table_id' => null,
        ]);

        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseHas('bill_items', [
            'id' => $drinkItem->id,
            'bill_id' => $billId,
            'qty' => 2,
            'line_total' => 16000,
        ]);
        $this->assertDatabaseHas('order_items', [
            'bill_item_id' => $drinkItem->id,
            'qty' => 2,
        ]);
        $this->assertDatabaseHas('order_items', [
            'menu_id' => $drink->id,
            'qty' => 1,
        ]);

        $newBill = Bill::query()->with(['orders.items'])->findOrFail($newBillId);
        $this->assertCount(1, $newBill->orders);
        $this->assertCount(1, $newBill->orders->first()->items);
        $this->assertSame($drink->id, $newBill->orders->first()->items->first()->menu_id);

        $sourceBill = Bill::query()->with(['orders.items'])->findOrFail($billId);
        $this->assertCount(1, $sourceBill->orders);
        $this->assertCount(2, $sourceBill->orders->first()->items);
        $this->assertSame($food->id, $sourceBill->orders->first()->items->first()->menu_id);
    }

    /**
     * Verify master data CRUD for tables, menus, and customers works.
     */
    public function test_master_data_crud_flow_work(): void
    {
        $this->seed();

        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $category = MenuCategory::query()->where('station_type', 'BAR')->firstOrFail();

        $tableResponse = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/tables', [
            'name' => 'VIP 99',
            'capacity' => 8,
            'area' => 'VIP',
        ]);

        $tableResponse
            ->assertCreated()
            ->assertJsonPath('data.code', 'T04');

        $tableId = $tableResponse->json('data.id');

        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/tables/{$tableId}", [
            'name' => 'VIP 99 Updated',
            'status' => 'OUT_OF_SERVICE',
        ])->assertOk()
            ->assertJsonPath('data.name', 'VIP 99 Updated')
            ->assertJsonPath('data.status', 'OUT_OF_SERVICE');

        $menuResponse = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/menus', [
            'category_id' => $category->id,
            'name' => 'Mocktail Test',
            'price' => 22000,
            'station_type' => 'BAR',
        ]);

        $menuResponse
            ->assertCreated()
            ->assertJsonPath('data.sku', 'MNM-016');

        $menuId = $menuResponse->json('data.id');

        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/menus/{$menuId}", [
            'price' => 25000,
            'is_available' => false,
        ])->assertOk()
            ->assertJsonPath('data.price', '25000.00')
            ->assertJsonPath('data.is_available', false);

        $customerResponse = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/customers', [
            'name' => 'Budi CRUD',
            'phone' => '081200000001',
            'member_code' => 'MBR-CRUD',
        ]);

        $customerResponse
            ->assertCreated()
            ->assertJsonPath('data.member_code', 'MBR-CRUD');

        $customerId = $customerResponse->json('data.id');

        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/customers/{$customerId}", [
            'email' => 'budi@example.com',
            'reward_points' => 10,
        ])->assertOk()
            ->assertJsonPath('data.email', 'budi@example.com')
            ->assertJsonPath('data.reward_points', 10);

        $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/menus/{$menuId}")
            ->assertOk();

        $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/tables/{$tableId}")
            ->assertOk();

        $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/customers/{$customerId}")
            ->assertOk();

        $this->assertDatabaseMissing('menus', ['id' => $menuId]);
        $this->assertDatabaseMissing('tables', ['id' => $tableId]);
        $this->assertDatabaseMissing('customers', ['id' => $customerId]);
    }

    /**
     * Verify ingredient CRUD, stock adjustment, and menu recipe flow work.
     */
    public function test_inventory_master_data_flow_work(): void
    {
        $this->seed();

        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $menu = Menu::query()->where('sku', 'MKN-001')->firstOrFail();

        $createResponse = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/ingredients', [
            'name' => 'Cabai Rawit',
            'unit' => 'gram',
            'current_stock' => 2500,
            'minimum_stock' => 300,
            'notes' => 'Untuk sambal tambahan',
            'is_active' => true,
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('data.code', 'BRG-001')
            ->assertJsonPath('data.current_stock', '2500.00');

        $ingredientId = $createResponse->json('data.id');

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/ingredients')
            ->assertOk()
            ->assertJsonFragment([
                'code' => 'BRG-001',
                'name' => 'Cabai Rawit',
            ]);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/menus/{$menu->id}/ingredients", [
                'ingredients' => [
                    [
                        'ingredient_id' => $ingredientId,
                        'qty_per_portion' => 12,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.ingredients.0.code', 'BRG-001')
            ->assertJsonPath('data.ingredients.0.qty_per_portion', 12);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/menus/{$menu->id}/ingredients")
            ->assertOk()
            ->assertJsonPath('data.ingredients.0.code', 'BRG-001');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/ingredients/{$ingredientId}/adjust-stock", [
                'qty_delta' => -250,
                'reason' => 'Sampling produksi',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_stock', '2250.00');

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/ingredients/{$ingredientId}", [
                'name' => 'Cabai Rawit Merah',
                'minimum_stock' => 400,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Cabai Rawit Merah')
            ->assertJsonPath('data.minimum_stock', '400.00');

        $this->assertDatabaseHas('ingredient_stock_movements', [
            'ingredient_id' => $ingredientId,
            'movement_type' => 'INITIAL',
        ]);
        $this->assertDatabaseHas('ingredient_stock_movements', [
            'ingredient_id' => $ingredientId,
            'movement_type' => 'ADJUST_OUT',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/ingredients/{$ingredientId}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Stok barang yang sudah dipakai di menu tidak dapat dihapus.');

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/menus/{$menu->id}/ingredients", [
                'ingredients' => [],
            ])
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/ingredients/{$ingredientId}")
            ->assertOk();

        $this->assertDatabaseMissing('ingredients', [
            'id' => $ingredientId,
        ]);
    }

    /**
     * Verify ingredient stock is deducted on order, menu auto closes when stock runs out,
     * and stock is restored when item is cancelled.
     */
    public function test_inventory_is_deducted_and_restored_through_order_flow(): void
    {
        $this->seed();

        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $kitchen = User::query()->where('username', 'kitchen01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $menu = Menu::query()->where('sku', 'MKN-001')->firstOrFail();

        $ingredientId = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/ingredients', [
                'code' => 'BHN-STOK-01',
                'name' => 'Bahan Test Stok',
                'unit' => 'gram',
                'current_stock' => 10,
                'minimum_stock' => 0,
                'is_active' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/menus/{$menu->id}/ingredients", [
                'ingredients' => [
                    [
                        'ingredient_id' => $ingredientId,
                        'qty_per_portion' => 10,
                    ],
                ],
            ])
            ->assertOk();

        $menu->refresh();
        $this->assertTrue((bool) $menu->is_stock_available);

        $billId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $menu->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredientId,
            'current_stock' => 0,
        ]);
        $this->assertDatabaseHas('order_items', [
            'menu_id' => $menu->id,
            'stock_deducted' => true,
        ]);
        $this->assertDatabaseHas('ingredient_stock_movements', [
            'ingredient_id' => $ingredientId,
            'movement_type' => 'ORDER_OUT',
        ]);

        $menu->refresh();
        $this->assertFalse((bool) $menu->is_stock_available);

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $menu->id, 'qty' => 1],
            ],
        ])->assertStatus(422);

        $orderItem = OrderItem::query()->where('menu_id', $menu->id)->latest('id')->firstOrFail();

        $this->actingAs($kitchen, 'sanctum')
            ->patchJson("/api/v1/order-items/{$orderItem->id}/status", ['status' => 'CANCELLED'])
            ->assertOk();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredientId,
            'current_stock' => 10,
        ]);
        $this->assertDatabaseHas('order_items', [
            'id' => $orderItem->id,
            'stock_deducted' => false,
            'status' => 'CANCELLED',
        ]);
        $this->assertDatabaseHas('ingredient_stock_movements', [
            'ingredient_id' => $ingredientId,
            'movement_type' => 'ORDER_RESTORE',
        ]);

        $menu->refresh();
        $this->assertTrue((bool) $menu->is_stock_available);
    }

    /**
     * Verify voiding a bill restores deducted inventory and reopens menu stock.
     */
    public function test_void_bill_restores_inventory_and_menu_stock(): void
    {
        $this->seed();

        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $menu = Menu::query()->where('sku', 'MKN-001')->firstOrFail();

        $ingredientId = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/ingredients', [
                'code' => 'BHN-STOK-VOID',
                'name' => 'Bahan Void Test',
                'unit' => 'gram',
                'current_stock' => 20,
                'minimum_stock' => 0,
                'is_active' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/menus/{$menu->id}/ingredients", [
                'ingredients' => [
                    [
                        'ingredient_id' => $ingredientId,
                        'qty_per_portion' => 20,
                    ],
                ],
            ])
            ->assertOk();

        $billId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $menu->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredientId,
            'current_stock' => 0,
        ]);

        $this->assertDatabaseHas('menus', [
            'id' => $menu->id,
            'is_stock_available' => false,
        ]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/bills/{$billId}/void", [
                'reason' => 'Pelanggan batal datang',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'VOID');

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredientId,
            'current_stock' => 20,
        ]);

        $this->assertDatabaseHas('menus', [
            'id' => $menu->id,
            'is_stock_available' => true,
        ]);

        $this->assertDatabaseHas('ingredient_stock_movements', [
            'ingredient_id' => $ingredientId,
            'movement_type' => 'ORDER_RESTORE',
        ]);
    }

    /**
     * Verify owner can manage menu categories safely and menu-category rules are enforced.
     */
    public function test_menu_category_crud_and_menu_validation_work(): void
    {
        $this->seed();

        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $barCategory = MenuCategory::query()->where('station_type', 'BAR')->firstOrFail();
        $kitchenCategory = MenuCategory::query()->where('station_type', 'KITCHEN')->firstOrFail();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/menu-categories?active_only=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $createCategoryResponse = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/menu-categories', [
            'name' => 'Dessert',
            'station_type' => 'KITCHEN',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $createCategoryResponse
            ->assertCreated()
            ->assertJsonPath('data.name', 'Dessert')
            ->assertJsonPath('data.station_type', 'KITCHEN');

        $newCategoryId = $createCategoryResponse->json('data.id');

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/menu-categories/{$newCategoryId}", [
                'name' => 'Dessert Premium',
                'sort_order' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Dessert Premium')
            ->assertJsonPath('data.sort_order', 20);

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/menus', [
            'category_id' => $barCategory->id,
            'name' => 'Mismatch Drink',
            'price' => 18000,
            'station_type' => 'KITCHEN',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Station type menu harus sama dengan station type kategori.');

        $inactiveCategory = MenuCategory::query()->create([
            'name' => 'Nonaktif Test',
            'station_type' => 'BAR',
            'sort_order' => 99,
            'is_active' => false,
        ]);

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/menus', [
            'category_id' => $inactiveCategory->id,
            'name' => 'Inactive Category Drink',
            'price' => 18000,
            'station_type' => 'BAR',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kategori menu yang dipilih tidak aktif.');

        $menuResponse = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/menus', [
            'category_id' => $barCategory->id,
            'name' => 'Valid Drink',
            'price' => 19000,
            'station_type' => 'BAR',
        ]);

        $menuResponse
            ->assertCreated()
            ->assertJsonPath('data.category.id', $barCategory->id);

        $menuId = $menuResponse->json('data.id');

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/menus/{$menuId}", [
                'category_id' => $kitchenCategory->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Station type menu harus sama dengan station type kategori.');

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/menu-categories/{$barCategory->id}", [
                'station_type' => 'KITCHEN',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kategori yang sudah memiliki menu tidak bisa diubah station type-nya.');

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/menu-categories/{$barCategory->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kategori menu yang masih memiliki menu tidak dapat dihapus.');

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/menus/{$menuId}")
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/menu-categories/{$newCategoryId}")
            ->assertOk();

        $this->assertDatabaseMissing('menus', ['id' => $menuId]);
        $this->assertDatabaseMissing('menu_categories', ['id' => $newCategoryId]);
    }

    /**
     * Verify waiter can read ready items and inspect bill checklist.
     */
    public function test_waiter_checklist_flow_work(): void
    {
        $this->seed();

        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $waiter = User::query()->where('username', 'waiter01')->firstOrFail();
        $kitchen = User::query()->where('username', 'kitchen01')->firstOrFail();
        $barUser = User::query()->where('username', 'bar01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $food = Menu::query()->where('sku', 'MKN-001')->firstOrFail();
        $drink = Menu::query()->where('sku', 'MNM-001')->firstOrFail();

        $billId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
                ['menu_id' => $drink->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $kitchenItem = OrderItem::query()->where('station_type', 'KITCHEN')->firstOrFail();
        $barItem = OrderItem::query()->where('station_type', 'BAR')->firstOrFail();

        $this->actingAs($kitchen, 'sanctum')
            ->patchJson("/api/v1/order-items/{$kitchenItem->id}/status", ['status' => 'READY'])
            ->assertOk();

        $this->actingAs($barUser, 'sanctum')
            ->patchJson("/api/v1/order-items/{$barItem->id}/status", ['status' => 'READY'])
            ->assertOk();

        $this->actingAs($waiter, 'sanctum')
            ->getJson('/api/v1/waiter/ready-items')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($waiter, 'sanctum')
            ->getJson("/api/v1/bills/{$billId}/checklist")
            ->assertOk()
            ->assertJsonPath('data.summary.ready', 2)
            ->assertJsonPath('data.bill.id', $billId)
            ->assertJsonCount(2, 'data.items');

    }

    /**
     * Verify invalid order item status transitions are rejected.
     */
    public function test_invalid_order_item_status_transition_is_rejected(): void
    {
        $this->seed();

        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $waiter = User::query()->where('username', 'waiter01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $food = Menu::query()->where('sku', 'MKN-001')->firstOrFail();

        $billId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $item = OrderItem::query()->firstOrFail();

        $this->actingAs($waiter, 'sanctum')
            ->patchJson("/api/v1/order-items/{$item->id}/status", ['status' => 'SERVED'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Transisi status item order tidak valid.');
    }

    /**
     * Verify bill status derives from item readiness and empty bills cannot be paid.
     */
    public function test_bill_state_machine_and_payment_gate_work(): void
    {
        $this->seed();

        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $waiter = User::query()->where('username', 'waiter01')->firstOrFail();
        $kitchen = User::query()->where('username', 'kitchen01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $food = Menu::query()->where('sku', 'MKN-001')->firstOrFail();

        $billId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/bills/{$billId}/payments", [
                'payment_method' => 'CASH',
                'amount' => 10000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Bill belum memiliki tagihan untuk dibayar.');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $item = OrderItem::query()->firstOrFail();

        $this->actingAs($kitchen, 'sanctum')
            ->patchJson("/api/v1/order-items/{$item->id}/status", ['status' => 'READY'])
            ->assertOk();

        $this->actingAs($cashier, 'sanctum')
            ->getJson("/api/v1/bills/{$billId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'READY_TO_PAY')
            ->assertJsonPath('data.orders.0.status', 'READY');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->getJson("/api/v1/bills/{$billId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ORDERING');

        $this->actingAs($waiter, 'sanctum')
            ->patchJson("/api/v1/order-items/{$item->id}/status", ['status' => 'SERVED'])
            ->assertOk();

        $latestItem = OrderItem::query()->latest('id')->firstOrFail();

        $this->actingAs($kitchen, 'sanctum')
            ->patchJson("/api/v1/order-items/{$latestItem->id}/status", ['status' => 'READY'])
            ->assertOk();

        $this->actingAs($waiter, 'sanctum')
            ->patchJson("/api/v1/order-items/{$latestItem->id}/status", ['status' => 'SERVED'])
            ->assertOk();

        $this->actingAs($cashier, 'sanctum')
            ->getJson("/api/v1/bills/{$billId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'SERVED')
            ->assertJsonPath('data.orders.0.status', 'SERVED');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->getJson("/api/v1/bills/{$billId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ORDERING');
    }

    /**
     * Verify owner can read sales summary report and audit log feed.
     */
    public function test_reporting_and_audit_log_flow_work(): void
    {
        $this->seed();

        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $tableTwo = Table::query()->where('code', 'T02')->firstOrFail();
        $food = Menu::query()->where('sku', 'MKN-001')->firstOrFail();
        $drink = Menu::query()->where('sku', 'MNM-001')->firstOrFail();

        $billId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
                ['menu_id' => $drink->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/payments", [
            'payment_method' => 'CASH',
            'amount' => 36000,
        ])->assertCreated();

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/refund", [
            'payment_method' => 'CASH',
            'reason' => 'Customer komplain',
            'amount' => 10000,
        ])->assertCreated();

        $secondBillId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $tableTwo->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$secondBillId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$secondBillId}/payments", [
            'payment_method' => 'DEBIT',
            'amount' => 28000,
        ])->assertCreated();

        Payment::query()
            ->where('bill_id', $secondBillId)
            ->where('status', 'PAID')
            ->update(['paid_at' => now()->subDay()]);

        $reportResponse = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/reports/sales-summary?date_from=' . now()->subDay()->toDateString() . '&date_to=' . now()->toDateString());

        $reportResponse
            ->assertOk()
            ->assertJsonPath('summary.gross_sales', '64000.00')
            ->assertJsonPath('summary.refund_total', '10000.00')
            ->assertJsonPath('summary.net_sales', '54000.00')
            ->assertJsonPath('summary.paid_bills_count', 2)
            ->assertJsonPath('summary.refunded_bills_count', 1)
            ->assertJsonPath('payment_methods.0.payment_method', 'CASH')
            ->assertJsonPath('payment_methods.0.net_total', '26000.00')
            ->assertJsonPath('bill_types.0.bill_type', 'DINE_IN')
            ->assertJsonPath('daily_trend.0.date', now()->subDay()->toDateString())
            ->assertJsonPath('daily_trend.0.net_total', '28000.00')
            ->assertJsonPath('daily_trend.1.date', now()->toDateString())
            ->assertJsonPath('daily_trend.1.net_total', '26000.00')
            ->assertJsonPath('top_tables.0.table_code', 'T01');

        $exportResponse = $this->actingAs($owner, 'sanctum')
            ->get('/api/v1/reports/sales-summary/export?date_from=' . now()->subDay()->toDateString() . '&date_to=' . now()->toDateString());

        $exportResponse
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition');

        $exportContent = $exportResponse->streamedContent();

        $this->assertStringContainsString('section,key,value', $exportContent);
        $this->assertStringContainsString('summary,gross_sales,64000.00', $exportContent);
        $this->assertStringContainsString('daily_trend,' . now()->toDateString(), $exportContent);

        $excelResponse = $this->actingAs($owner, 'sanctum')
            ->get('/api/v1/reports/sales-summary/export-excel?date_from=' . now()->subDay()->toDateString() . '&date_to=' . now()->toDateString());

        $excelResponse
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('content-disposition');

        $this->assertStringContainsString('.xlsx', (string) $excelResponse->headers->get('content-disposition'));

        $excelFile = $excelResponse->baseResponse->getFile();
        $this->assertNotNull($excelFile);

        $excelContent = file_get_contents($excelFile->getPathname());

        $this->assertNotFalse($excelContent);
        $this->assertStringStartsWith('PK', $excelContent);

        $auditResponse = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/audit-logs?entity_type=bill&per_page=5');

        $auditResponse
            ->assertOk()
            ->assertJsonPath('data.0.entity_type', 'bill');
    }

    /**
     * Verify public QR menu flow can create pending order and waiter can approve it into a bill.
     */
    public function test_qr_menu_flow_work(): void
    {
        $this->seed();

        $waiter = User::query()->where('username', 'waiter01')->firstOrFail();
        $food = Menu::query()->where('sku', 'MKN-001')->firstOrFail();
        $drink = Menu::query()->where('sku', 'MNM-001')->firstOrFail();

        $this->getJson('/api/v1/qr-menu/T01')
            ->assertOk()
            ->assertJsonPath('data.table.code', 'T01');

        $checkoutResponse = $this->postJson('/api/v1/qr-menu/T01/checkout', [
            'customer_name' => 'Guest QR',
            'customer_phone' => '081234000000',
            'guest_count' => 2,
            'notes' => 'Tolong cepat',
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
                ['menu_id' => $drink->id, 'qty' => 2, 'notes' => 'Es sedikit'],
            ],
        ]);

        $checkoutResponse
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.table.code', 'T01')
            ->assertJsonPath('data.subtotal', '44000.00');

        $guestToken = $checkoutResponse->json('data.guest_token');
        $qrOrderId = $checkoutResponse->json('data.id');

        $this->actingAs($waiter, 'sanctum')
            ->getJson('/api/v1/qr-orders?status=PENDING')
            ->assertOk()
            ->assertJsonPath('data.0.id', $qrOrderId);

        $approveResponse = $this->actingAs($waiter, 'sanctum')
            ->postJson("/api/v1/qr-orders/{$qrOrderId}/approve");

        $approveResponse
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('bill.bill_type', 'DINE_IN')
            ->assertJsonPath('order.source', 'QR');

        $this->getJson("/api/v1/qr-menu/orders/{$guestToken}")
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');

        $qrOrder = QrOrder::query()->findOrFail($qrOrderId);

        $this->assertDatabaseHas('bills', [
            'id' => $qrOrder->linked_bill_id,
            'table_id' => Table::query()->where('code', 'T01')->firstOrFail()->id,
            'status' => 'ORDERING',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $qrOrder->approved_order_id,
            'source' => 'QR',
        ]);

        $this->assertDatabaseHas('bill_items', [
            'bill_id' => $qrOrder->linked_bill_id,
            'menu_id' => $food->id,
            'qty' => 1,
        ]);

        $this->assertDatabaseHas('bill_items', [
            'bill_id' => $qrOrder->linked_bill_id,
            'menu_id' => $drink->id,
            'qty' => 2,
        ]);
    }

    /**
     * Verify operational notification feed aggregates waiter, QR, and cashier actions.
     */
    public function test_notification_feed_work(): void
    {
        $this->seed();

        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $waiter = User::query()->where('username', 'waiter01')->firstOrFail();
        $kitchen = User::query()->where('username', 'kitchen01')->firstOrFail();
        $table = Table::query()->where('code', 'T01')->firstOrFail();
        $food = Menu::query()->where('sku', 'MKN-001')->firstOrFail();
        $drink = Menu::query()->where('sku', 'MNM-001')->firstOrFail();

        $billId = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/bills', [
            'bill_type' => 'DINE_IN',
            'table_id' => $table->id,
            'guest_count' => 2,
        ])->json('data.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/orders", [
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
                ['menu_id' => $drink->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $readyItem = OrderItem::query()->where('station_type', 'KITCHEN')->firstOrFail();

        $this->actingAs($kitchen, 'sanctum')
            ->patchJson("/api/v1/order-items/{$readyItem->id}/status", ['status' => 'READY'])
            ->assertOk();

        $this->postJson('/api/v1/qr-menu/T02/checkout', [
            'customer_name' => 'Notif Guest',
            'guest_count' => 2,
            'items' => [
                ['menu_id' => $food->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/payments", [
            'payment_method' => 'CASH',
            'amount' => 10000,
        ])->assertCreated();

        $response = $this->actingAs($waiter, 'sanctum')
            ->getJson('/api/v1/notifications');

        $response
            ->assertOk()
            ->assertJsonPath('summary.ready_items_count', 1)
            ->assertJsonPath('summary.pending_qr_orders_count', 1)
            ->assertJsonPath('summary.cashier_action_bills_count', 0)
            ->assertJsonPath('summary.total_unread_count', 2)
            ->assertJsonCount(2, 'data');

        $channels = collect($response->json('data'))->pluck('channel')->all();

        $this->assertContains('waiter_ready', $channels);
        $this->assertContains('qr_pending', $channels);
        $this->assertNotContains('cashier_bill', $channels);

        $readyNotification = collect($response->json('data'))
            ->firstWhere('channel', 'waiter_ready');

        $this->assertNotNull($readyNotification);
        $this->assertFalse($readyNotification['is_read']);

        $this->actingAs($waiter, 'sanctum')
            ->postJson('/api/v1/notifications/mark-read', [
                'channel' => $readyNotification['channel'],
                'entity_type' => $readyNotification['entity_type'],
                'entity_id' => $readyNotification['entity_id'],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Notifikasi ditandai sudah dibaca.');

        $afterSingleRead = $this->actingAs($waiter, 'sanctum')
            ->getJson('/api/v1/notifications');

        $afterSingleRead
            ->assertOk()
            ->assertJsonPath('summary.total_unread_count', 1);

        $updatedReadyNotification = collect($afterSingleRead->json('data'))
            ->firstWhere('channel', 'waiter_ready');

        $this->assertTrue($updatedReadyNotification['is_read']);
        $this->assertNotNull($updatedReadyNotification['read_at']);

        $this->actingAs($waiter, 'sanctum')
            ->postJson('/api/v1/notifications/mark-all-read')
            ->assertOk()
            ->assertJsonPath('message', 'Semua notifikasi berhasil ditandai sudah dibaca.')
            ->assertJsonPath('data.marked_count', 2);

        $afterMarkAll = $this->actingAs($waiter, 'sanctum')
            ->getJson('/api/v1/notifications');

        $afterMarkAll
            ->assertOk()
            ->assertJsonPath('summary.total_unread_count', 0);

        $cashierResponse = $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/v1/notifications');

        $cashierResponse
            ->assertOk()
            ->assertJsonPath('summary.cashier_action_bills_count', 1);

        $cashierChannels = collect($cashierResponse->json('data'))->pluck('channel')->all();
        $this->assertContains('cashier_bill', $cashierChannels);
    }

}
