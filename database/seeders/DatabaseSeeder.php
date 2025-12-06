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
            UserSeeder::class,
            StoreSeeder::class,
            TagSeeder::class,
            ProductSeeder::class,
            SettingsSeeder::class,
            ProductSourceSeeder::class,
        ]);
    }
}
