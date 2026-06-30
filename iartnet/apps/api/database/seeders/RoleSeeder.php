<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'admin',
                'display_name' => 'Amministratore',
                'description' => 'Ruolo con accesso completo a tutte le funzionalità del sistema',
            ],
            [
                'name' => 'operatore',
                'display_name' => 'Operatore',
                'description' => 'Ruolo con accesso limitato alle funzionalità operative',
            ],
            [
                'name' => 'partner',
                'display_name' => 'Partner',
                'description' => 'Ruolo con accesso limitato ai dati della propria istituzione',
            ],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role['name']],
                $role
            );
        }
    }
}
