<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Cuentas de acceso de producción.
 *
 * No se registra en DatabaseSeeder; ejecutar manualmente:
 * php artisan db:seed --class=ProductionUserSeeder
 */
class ProductionUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // La contraseña se hashea vía el cast 'hashed' del modelo User
        User::firstOrCreate(
            ['email' => 'soporte@innovapisos.app'],
            [
                'name' => 'Soporte Innova',
                'password' => 'Soporte123',
            ]
        );

        User::firstOrCreate(
            ['email' => 'pedro@innovapisos.app'],
            [
                'name' => 'Pedro Lopez',
                'password' => 'Pedro2026',
            ]
        );
    }
}
