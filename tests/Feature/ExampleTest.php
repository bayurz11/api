<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\QrOrder;
use App\Models\Reservation;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $tablesResponse = $this->actingAs($user, 'sanctum')->getJson('/api/v1/tables');
        $tablesResponse
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $menusResponse = $this->actingAs($user, 'sanctum')->getJson('/api/v1/menus?available_only=1');
        $menusResponse
            ->assertOk()
            ->assertJsonCount(3, 'data');
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
     * Verify print jobs can be created for kitchen, bar, proforma, and receipt.
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
            ->postJson('/api/v1/print/proforma-bill', ['bill_id' => $billId])
            ->assertCreated()
            ->assertJsonPath('data.job_type', 'PROFORMA_BILL');

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
     * Verify selected bill items can be split into a new bill and related order items follow.
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
                ['menu_id' => $drink->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $sourceBill = Bill::query()->with(['items', 'orders.items'])->findOrFail($billId);
        $drinkItem = $sourceBill->items->firstWhere('menu_id', $drink->id);

        $response = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/bills/{$billId}/split", [
            'bill_item_ids' => [$drinkItem->id],
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
            'id' => $drinkItem->id,
            'bill_id' => $newBillId,
        ]);

        $this->assertDatabaseHas('bills', [
            'id' => $billId,
            'subtotal' => 28000,
            'balance_due' => 28000,
        ]);

        $this->assertDatabaseHas('bills', [
            'id' => $newBillId,
            'bill_type' => 'CUSTOMER',
            'subtotal' => 8000,
            'balance_due' => 8000,
            'table_id' => null,
        ]);

        $this->assertDatabaseCount('orders', 2);

        $newBill = Bill::query()->with(['orders.items'])->findOrFail($newBillId);
        $this->assertCount(1, $newBill->orders);
        $this->assertCount(1, $newBill->orders->first()->items);
        $this->assertSame($drink->id, $newBill->orders->first()->items->first()->menu_id);

        $sourceBill = Bill::query()->with(['orders.items'])->findOrFail($billId);
        $this->assertCount(1, $sourceBill->orders);
        $this->assertCount(1, $sourceBill->orders->first()->items);
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
            'code' => 'T99',
            'name' => 'VIP 99',
            'capacity' => 8,
            'area' => 'VIP',
        ]);

        $tableResponse
            ->assertCreated()
            ->assertJsonPath('data.code', 'T99');

        $tableId = $tableResponse->json('data.id');

        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/tables/{$tableId}", [
            'name' => 'VIP 99 Updated',
            'status' => 'OUT_OF_SERVICE',
        ])->assertOk()
            ->assertJsonPath('data.name', 'VIP 99 Updated')
            ->assertJsonPath('data.status', 'OUT_OF_SERVICE');

        $menuResponse = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/menus', [
            'category_id' => $category->id,
            'sku' => 'MNM-999',
            'name' => 'Mocktail Test',
            'price' => 22000,
            'station_type' => 'BAR',
        ]);

        $menuResponse
            ->assertCreated()
            ->assertJsonPath('data.sku', 'MNM-999');

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
            'sku' => 'MNM-998',
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
            'sku' => 'MNM-997',
            'name' => 'Inactive Category Drink',
            'price' => 18000,
            'station_type' => 'BAR',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kategori menu yang dipilih tidak aktif.');

        $menuResponse = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/menus', [
            'category_id' => $barCategory->id,
            'sku' => 'MNM-996',
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
     * Verify waiter can read ready items, inspect bill checklist, and print customer checklist.
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

        $this->actingAs($waiter, 'sanctum')
            ->postJson('/api/v1/print/customer-checklist', ['bill_id' => $billId])
            ->assertCreated()
            ->assertJsonPath('data.job_type', 'CUSTOMER_CHECKLIST');
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
            ->assertJsonPath('summary.cashier_action_bills_count', 1);

        $channels = collect($response->json('data'))->pluck('channel')->all();

        $this->assertContains('waiter_ready', $channels);
        $this->assertContains('qr_pending', $channels);
        $this->assertContains('cashier_bill', $channels);
    }

}
