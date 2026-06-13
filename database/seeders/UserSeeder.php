<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'password' => Hash::make('password'),
            'role' => Role::Admin,
        ]);

        User::factory()->create([
            'name' => 'Another User',
            'email' => 'another@test.com',
            'password' => Hash::make('password'),
            'role' => Role::User,
        ]);
    }
}
