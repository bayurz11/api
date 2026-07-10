<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\BarController;
use App\Http\Controllers\Api\V1\BillController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\KitchenController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\MenuCategoryController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderItemStatusController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PrintController;
use App\Http\Controllers\Api\V1\QrMenuController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\RestaurantProfileController;
use App\Http\Controllers\Api\V1\TableController;
use App\Http\Controllers\Api\V1\WaiterChecklistController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::get('/qr-menu/{tableCode}', [QrMenuController::class, 'menu']);
    Route::post('/qr-menu/{tableCode}/checkout', [QrMenuController::class, 'checkout']);
    Route::get('/qr-menu/orders/{guestToken}', [QrMenuController::class, 'status']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/mark-read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
        Route::get('/dashboard', DashboardController::class)->middleware('permission:dashboard.view');

        Route::middleware('permission:reports.view')->group(function () {
            Route::get('/audit-logs', [AuditLogController::class, 'index']);
            Route::get('/reports/sales-summary', [ReportController::class, 'salesSummary']);
            Route::get('/reports/sales-summary/export', [ReportController::class, 'exportSalesSummary']);
            Route::get('/settings/restaurant-profile', [RestaurantProfileController::class, 'show']);
            Route::post('/settings/restaurant-profile', [RestaurantProfileController::class, 'update']);
        });

        Route::middleware('permission:tables.view')->group(function () {
            Route::get('/tables', [TableController::class, 'index']);
            Route::post('/tables/{table}/mark-ready', [TableController::class, 'markReady']);
            Route::post('/tables/{table}/mark-read', [TableController::class, 'markReady']);
        });

        Route::middleware('permission:tables.manage')->group(function () {
            Route::post('/tables', [TableController::class, 'store']);
            Route::patch('/tables/{table}', [TableController::class, 'update']);
            Route::delete('/tables/{table}', [TableController::class, 'destroy']);
        });

        Route::middleware('permission:menus.view')->group(function () {
            Route::get('/menu-categories', [MenuCategoryController::class, 'index']);
            Route::get('/menus', [MenuController::class, 'index']);
        });

        Route::middleware('permission:menus.manage')->group(function () {
            Route::post('/menu-categories', [MenuCategoryController::class, 'store']);
            Route::patch('/menu-categories/{menuCategory}', [MenuCategoryController::class, 'update']);
            Route::delete('/menu-categories/{menuCategory}', [MenuCategoryController::class, 'destroy']);
            Route::post('/menus', [MenuController::class, 'store']);
            Route::patch('/menus/{menu}', [MenuController::class, 'update']);
            Route::delete('/menus/{menu}', [MenuController::class, 'destroy']);
        });

        Route::middleware('permission:customers.view')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        });

        Route::middleware('permission:customers.manage')->group(function () {
            Route::post('/customers', [CustomerController::class, 'store']);
            Route::patch('/customers/{customer}', [CustomerController::class, 'update']);
            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);
        });

        Route::middleware('permission:reservations.view')->group(function () {
            Route::get('/reservations', [ReservationController::class, 'index']);
        });

        Route::middleware('permission:reservations.manage')->group(function () {
            Route::post('/reservations', [ReservationController::class, 'store']);
        });

        Route::post('/reservations/{reservation}/deposit', [ReservationController::class, 'addDeposit'])->middleware('permission:deposits.manage');
        Route::post('/reservations/{reservation}/convert-to-bill', [ReservationController::class, 'convertToBill'])->middleware('permission:bills.create');

        Route::middleware('permission:bills.view')->group(function () {
            Route::get('/bills', [BillController::class, 'index']);
            Route::get('/bills/{bill}', [BillController::class, 'show']);
            Route::get('/bills/{bill}/checklist', [WaiterChecklistController::class, 'showBillChecklist']);
            Route::get('/bills/{bill}/orders', [OrderController::class, 'index']);
            Route::get('/bills/{bill}/payments', [PaymentController::class, 'index']);
        });

        Route::post('/bills', [BillController::class, 'store'])->middleware('permission:bills.create');
        Route::patch('/bills/{bill}', [BillController::class, 'update'])->middleware('permission:bills.manage');
        Route::post('/bills/{bill}/reopen', [BillController::class, 'reopen'])->middleware('permission:bills.reopen');
        Route::post('/bills/{bill}/merge', [BillController::class, 'merge'])->middleware('permission:bills.merge');
        Route::post('/bills/{bill}/split', [BillController::class, 'split'])->middleware('permission:bills.split');
        Route::post('/bills/{bill}/void', [BillController::class, 'void'])->middleware('permission:bills.void');
        Route::post('/bills/{bill}/transfer-table', [BillController::class, 'transferTable'])->middleware('permission:bills.transfer');

        Route::middleware('permission:orders.create')->group(function () {
            Route::get('/qr-orders', [QrMenuController::class, 'index']);
            Route::post('/qr-orders/{qrOrder}/approve', [QrMenuController::class, 'approve']);
            Route::post('/qr-orders/{qrOrder}/reject', [QrMenuController::class, 'reject']);
            Route::post('/bills/{bill}/orders', [OrderController::class, 'store']);
        });

        Route::middleware('permission:payments.create')->group(function () {
            Route::post('/bills/{bill}/payments', [PaymentController::class, 'store']);
            Route::post('/bills/{bill}/split-payment', [PaymentController::class, 'split']);
            Route::post('/bills/{bill}/close', [PaymentController::class, 'close']);
        });

        Route::post('/bills/{bill}/refund', [PaymentController::class, 'refund'])->middleware('permission:bills.refund');
        Route::post('/payments/{payment}/void', [PaymentController::class, 'void'])->middleware('permission:payments.void');

        Route::middleware('permission:prints.view')->group(function () {
            Route::get('/printers', [PrintController::class, 'printers']);
            Route::get('/print-jobs', [PrintController::class, 'jobs']);
            Route::get('/print/receipt/{bill}/pdf', [PrintController::class, 'receiptPdf']);
        });

        Route::middleware('permission:prints.create')->group(function () {
            Route::post('/printers/{printer}/test', [PrintController::class, 'testPrinter']);
            Route::post('/print/kitchen-ticket', [PrintController::class, 'kitchenTicket']);
            Route::post('/print/bar-ticket', [PrintController::class, 'barTicket']);
            Route::post('/print/receipt', [PrintController::class, 'receipt']);
        });

        Route::get('/waiter/ready-items', [WaiterChecklistController::class, 'readyItems'])->middleware('permission:orders.serve');
        Route::get('/kitchen/orders', [KitchenController::class, 'index'])->middleware('permission:orders.update-status');
        Route::get('/bar/orders', [BarController::class, 'index'])->middleware('permission:orders.update-status');
        Route::patch('/order-items/{orderItem}/status', [OrderItemStatusController::class, 'update']);
    });
});
