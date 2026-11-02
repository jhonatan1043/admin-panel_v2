<?php

namespace Tests\Feature;

use App\Models\ClientLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_endpoint_del_tablero_requiere_sesion(): void
    {
        $this->get('/companies/board')->assertRedirect('/login');
    }

    public function test_el_endpoint_del_tablero_lista_los_clientes_actuales(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);

        $this->actingAs($admin)->get('/companies/board')
            ->assertOk()
            ->assertSee('Acme')
            ->assertSee('REG-0001');
    }

    public function test_un_cliente_nuevo_aparece_en_el_siguiente_refresco(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/companies/board')
            ->assertOk()
            ->assertDontSee('REG-0001');

        Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);

        $this->actingAs($admin)->get('/companies/board')
            ->assertOk()
            ->assertSee('REG-0001');
    }

    public function test_el_detalle_muestra_la_conversacion_del_cliente(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        ClientLog::create(['company_id' => $company->id, 'direction' => 'in', 'text' => 'solicitud', 'status' => 'received']);

        $this->actingAs($admin)->get("/companies/{$company->id}/detail")
            ->assertOk()
            ->assertSee('Cliente')
            ->assertSee('solicitud');
    }

    public function test_el_operador_no_ve_el_detalle_de_clientes_ajenos(): void
    {
        $operador = User::factory()->create(['role' => 'operador']);
        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);

        $this->actingAs($operador)->get("/companies/{$company->id}/detail")
            ->assertForbidden();
    }

    public function test_exporta_la_conversacion_en_txt(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        ClientLog::create(['company_id' => $company->id, 'direction' => 'in', 'text' => 'hola', 'status' => 'received']);

        $res = $this->actingAs($admin)->get("/companies/{$company->id}/detail/export")
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="conversacion-REG-0001.txt"');

        $this->assertStringContainsString('Cliente: hola', $res->getContent());
    }

    public function test_el_operador_no_exporta_conversacion_ajena(): void
    {
        $operador = User::factory()->create(['role' => 'operador']);
        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);

        $this->actingAs($operador)->get("/companies/{$company->id}/detail/export")
            ->assertForbidden();
    }
}
