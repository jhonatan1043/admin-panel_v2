<?php

namespace Database\Seeders;

use App\Models\Button;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Usuarios de acceso al panel.
        $users = [
            ['Operador', 'operador', 'operador@panel.test', 'operador123', 'operador'],
            ['Admin', 'admin', 'admin@panel.test', 'admin123', 'admin'],
        ];

        foreach ($users as [$name, $username, $email, $password, $role]) {
            User::updateOrCreate(
                ['username' => $username],
                ['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'role' => $role]
            );
        }

        // Catálogo global de botones.
        $catalog = [
            ['Licencia', 'Tu licencia ha sido activada correctamente.', 0],
            ['Paquete 1', 'Has adquirido el Paquete 1. Gracias por tu compra.', 1],
            ['En espera', 'Tu solicitud está siendo procesada, por favor espera.', 2],
            ['Soporte', 'Un agente de soporte te atenderá en breve.', 3],
        ];
        foreach ($catalog as [$label, $text, $order]) {
            Button::firstOrCreate(['label' => $label], ['text' => $text, 'sort_order' => $order]);
        }

        $operatorId = User::where('username', 'operador')->value('id');

        // [registro, nombre, empresa, minutos desde última actividad, asignado al operador, botones]
        $data = [
            ['REG-0001', 'Juan Pérez',    'Acme S.A.',    2,  true,  ['Sux', 'Licencia', 'Paquete 1']],
            ['REG-0002', 'María López',   'Globex',       5,  false, ['Bienvenida', 'Oferta', 'Soporte']],
            ['REG-0003', 'Carlos Ruiz',   'Acme S.A.',    15, true,  ['En espera', 'Confirmar']],
            ['REG-0004', 'Ana Gómez',     'Initech',      40, false, []],
            ['REG-0005', 'Pedro Sánchez', 'Globex',       90, true,  ['Expirado']],
        ];

        foreach ($data as [$registro, $name, $empresa, $minutes, $mine, $buttons]) {
            $company = Company::firstOrNew(['registro' => $registro]);
            $company->fill([
                'name' => $name,
                'empresa' => $empresa,
                'fecha' => now()->subMinutes($minutes),
                'last_activity_at' => now()->subMinutes($minutes),
                'operator_id' => $mine ? $operatorId : null,
            ])->save();

            foreach ($buttons as $i => $label) {
                $company->messageTemplates()->firstOrCreate(
                    ['label' => $label],
                    ['body' => "Mensaje de ejemplo: {$label}.", 'sort_order' => $i]
                );
            }
        }
    }
}
