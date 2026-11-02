<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardGroupingTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_tablero_agrupa_los_clientes_por_empresa(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Company::create(['registro' => 'A-ACT', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        Company::create(['registro' => 'A-OLD', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()->subHours(2)]);
        Company::create(['registro' => 'G-ACT', 'empresa' => 'Globex', 'fecha' => now(), 'last_activity_at' => now()]);

        $this->actingAs($admin)->get('/companies')
            ->assertOk()
            ->assertSee('Acme')
            ->assertSee('Globex')
            ->assertSee('A-ACT')   // activo de Acme
            ->assertSee('G-ACT')   // activo de Globex
            ->assertSee('A-OLD')   // inactivo de Acme, agrupado
            ->assertSee('OTROS');  // grupo del resto
    }
}
