<?php

namespace Database\Seeders;

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
        // Crear un usuario administrador
        User::updateOrCreate(
            ['email' => 'soporte@innovapisos.app'],
            [
                'name' => 'Soporte',
                'password' => Hash::make('Soporte123'),
                'email_verified_at' => now(),
            ]
        );

        // Crear un usuario regular
        User::updateOrCreate(
            ['email' => 'pedro_lopez@innovapisos.app'],
            [
                'name' => 'Pedro Lopez',
                'password' => Hash::make('Pedro2026'),
                'email_verified_at' => now(),
            ]
        );

        // Generar algunos usuarios aleatorios adicionales
        User::factory(5)->create();
    }
}
