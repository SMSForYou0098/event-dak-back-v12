<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create Role "Admin" if it doesn't exist
        $role = Role::firstOrCreate(['name' => 'Admin']);

        // 2. Create User
        $user = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'number' => '1234567890', // Dummy number
                'password' => Hash::make('password'), // Default password
                'status' => 1, // Assuming 1 is active
            ]
        );

        // 3. Assign Role
        if (!$user->hasRole('Admin')) {
            $user->assignRole($role);
        }

        $this->command->info('Admin user created and assigned Admin role.');
    }
}
