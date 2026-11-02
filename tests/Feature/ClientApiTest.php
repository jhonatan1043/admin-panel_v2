<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_crear_cliente_requiere_empresa_y_fecha_pero_no_registro(): void
    {
        $this->postJson('/api/clients', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['empresa', 'fecha'])
            ->assertJsonMissingValidationErrors(['registro']);
    }

    public function test_crea_cliente_con_registro_generado_internamente(): void
    {
        $payload = [
            'empresa' => 'Acme S.A.',
            'fecha' => '2026-11-02 09:30',
            'name' => 'Juan',
            'registro' => 'HACKEADO',      // se ignora: el servidor lo genera
            'data' => ['plan' => 'premium'],
        ];

        $response = $this->postJson('/api/clients', $payload)
            ->assertCreated()
            ->assertJsonPath('data.empresa', 'Acme S.A.')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.data.plan', 'premium');

        $registro = $response->json('data.registro');
        $this->assertMatchesRegularExpression('/^REG-\d{4,}$/', $registro);
        $this->assertNotSame('HACKEADO', $registro);
        $this->assertDatabaseHas('companies', ['registro' => $registro, 'empresa' => 'Acme S.A.']);
    }

    public function test_cada_post_crea_un_cliente_nuevo_con_registro_distinto(): void
    {
        $r1 = $this->postJson('/api/clients', ['empresa' => 'Acme', 'fecha' => '2026-11-02 09:30'])
            ->assertCreated()->json('data.registro');
        $r2 = $this->postJson('/api/clients', ['empresa' => 'Acme', 'fecha' => '2026-11-02 09:30'])
            ->assertCreated()->json('data.registro');

        $this->assertNotSame($r1, $r2);
        $this->assertSame(2, Company::count());
    }

    public function test_listado_devuelve_los_clientes(): void
    {
        Company::create(['registro' => 'REG-0001', 'empresa' => 'A', 'fecha' => now(), 'last_activity_at' => now()]);

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
