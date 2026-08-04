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
            ['email' => 'soporte@sistema-pavh.com'],
            [
                'name' => 'Soporte Justin',
                'password' => Hash::make('Justin1993'),
                'email_verified_at' => now(),
            ]
        );

        // Crear un usuario regular
        User::updateOrCreate(
            ['email' => 'pedro_lopez@sistema-pavh.com'],
            [
                'name' => 'Pedro Lopez',
                'password' => Hash::make('Pedro1234'),
                'email_verified_at' => now(),
            ]
        );

        // Generar algunos usuarios aleatorios adicionales
        User::factory(5)->create();
    }
}
