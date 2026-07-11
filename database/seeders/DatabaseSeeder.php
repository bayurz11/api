<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
        ]);

        if (app()->environment(['local', 'testing']) || (bool) env('APP_DEMO_SEEDER', false)) {
            $this->call([
                DemoDataSeeder::class,
            ]);
        }
    }
}
