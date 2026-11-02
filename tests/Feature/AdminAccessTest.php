<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitado_es_redirigido_al_login(): void
    {
        $this->get('/companies')->assertRedirect('/login');
    }

    public function test_operador_no_puede_entrar_a_operadores(): void
    {
        $operador = User::factory()->create(['role' => 'operador']);

        $this->actingAs($operador)->get('/operadores')->assertForbidden();
    }

    public function test_admin_entra_a_operadores(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/operadores')->assertOk();
    }

    public function test_operador_solo_ve_sus_clientes(): void
    {
        $operador = User::factory()->create(['role' => 'operador']);

        Company::create(['registro' => 'MIO-1', 'empresa' => 'A', 'fecha' => now(),
            'last_activity_at' => now(), 'operator_id' => $operador->id]);
        Company::create(['registro' => 'AJENO-2', 'empresa' => 'A', 'fecha' => now(),
            'last_activity_at' => now()]);

        $this->actingAs($operador)->get('/companies')
            ->assertOk()
            ->assertSee('MIO-1')
            ->assertDontSee('AJENO-2');
    }
}
