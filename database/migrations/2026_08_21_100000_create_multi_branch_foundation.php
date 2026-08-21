<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('timezone', 50)->default('Asia/Jakarta');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('branch_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role_name', 50)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'user_id']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('branch_menus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $table->string('local_sku')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->string('station_type')->nullable();
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'menu_id']);
            $table->unique(['branch_id', 'local_sku']);
            $table->index(['branch_id', 'is_active', 'is_available']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('tokenable_id')
                ->constrained()
                ->nullOnDelete();
        });

        $now = now();
        $organizationId = DB::table('organizations')->insertGetId([
            'code' => 'WARUNG-BABEH',
            'name' => 'Warung Babeh',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $branchId = DB::table('branches')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'UTAMA',
            'name' => 'Cabang Utama',
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('users')->orderBy('id')->each(function (object $user) use ($branchId, $now): void {
            $roleName = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_id', $user->id)
                ->value('roles.name');

            DB::table('branch_user')->insert([
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'role_name' => $roleName,
                'is_default' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        DB::table('menus')->orderBy('id')->each(function (object $menu) use ($branchId, $now): void {
            DB::table('branch_menus')->insert([
                'branch_id' => $branchId,
                'menu_id' => $menu->id,
                'local_sku' => $menu->sku,
                'price' => $menu->price,
                'station_type' => $menu->station_type,
                'is_available' => $menu->is_available,
                'is_active' => $menu->is_active,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::dropIfExists('branch_menus');
        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('organizations');
    }
};
