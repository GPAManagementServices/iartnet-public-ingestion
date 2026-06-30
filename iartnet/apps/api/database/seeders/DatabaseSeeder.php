<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed roles first
        $this->call(RoleSeeder::class);

        // Create admin user
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@iartnet.local'],
            [
                'name' => 'Amministratore',
                'email' => 'admin@iartnet.local',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        // Assign admin role
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole && ! $adminUser->hasRole('admin')) {
            $adminUser->roles()->attach($adminRole);
        }
    }
}
