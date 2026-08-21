<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'customers', 'tables', 'reservations', 'printers', 'settings',
        'bills', 'bill_items', 'orders', 'order_items', 'payments', 'deposits',
        'print_jobs', 'cashier_shifts', 'audit_logs', 'qr_orders', 'qr_order_items',
        'ingredients', 'menu_ingredients', 'ingredient_stock_movements', 'shopping_notes',
        'bill_discounts',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'branch_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('branch_id')
                    ->nullable()
                    ->after('id')
                    ->constrained()
                    ->restrictOnDelete();
            });
        }

        $mainBranchId = DB::table('branches')
            ->where('code', 'UTAMA')
            ->orderBy('id')
            ->value('id') ?? DB::table('branches')->orderBy('id')->value('id');

        if ($mainBranchId) {
            foreach (self::TABLES as $tableName) {
                if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'branch_id')) {
                    DB::table($tableName)->whereNull('branch_id')->update(['branch_id' => $mainBranchId]);
                }
            }
        }

        $this->replaceGlobalUniqueIndexes();
    }

    public function down(): void
    {
        $this->restoreGlobalUniqueIndexes();

        foreach (array_reverse(self::TABLES) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'branch_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('branch_id');
            });
        }
    }

    private function replaceGlobalUniqueIndexes(): void
    {
        Schema::table('tables', function (Blueprint $table): void {
            $table->dropUnique('tables_code_unique');
            $table->unique(['branch_id', 'code'], 'tables_branch_code_unique');
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique('customers_member_code_unique');
            $table->unique(['branch_id', 'member_code'], 'customers_branch_member_unique');
        });
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropUnique('settings_key_unique');
            $table->unique(['branch_id', 'key'], 'settings_branch_key_unique');
        });
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropUnique('ingredients_code_unique');
            $table->unique(['branch_id', 'code'], 'ingredients_branch_code_unique');
        });
    }

    private function restoreGlobalUniqueIndexes(): void
    {
        Schema::table('tables', function (Blueprint $table): void {
            $table->dropUnique('tables_branch_code_unique');
            $table->unique('code');
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique('customers_branch_member_unique');
            $table->unique('member_code');
        });
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropUnique('settings_branch_key_unique');
            $table->unique('key');
        });
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropUnique('ingredients_branch_code_unique');
            $table->unique('code');
        });
    }
};
