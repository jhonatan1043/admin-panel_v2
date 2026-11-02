<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_crear_cliente_requiere_registro_empresa_y_fecha(): void
    {
        $this->postJson('/api/clients', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['registro', 'empresa', 'fecha']);
    }

    public function test_crea_cliente_con_datos_validos_y_el_resto_en_data(): void
    {
        $payload = [
            'registro' => 'REG-9',
            'empresa' => 'Acme S.A.',
            'fecha' => '2026-11-02 09:30',
            'name' => 'Juan',
            'data' => ['plan' => 'premium', 'origen' => 'web'],
        ];

        $this->postJson('/api/clients', $payload)
            ->assertCreated()
            ->assertJsonPath('data.registro', 'REG-9')
            ->assertJsonPath('data.empresa', 'Acme S.A.')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.data.plan', 'premium');

        $this->assertDatabaseHas('companies', ['registro' => 'REG-9', 'empresa' => 'Acme S.A.']);
    }

    public function test_segundo_post_refresca_y_no_duplica(): void
    {
        Company::create([
            'registro' => 'REG-9', 'empresa' => 'Acme', 'fecha' => now()->subHour(),
            'last_activity_at' => now()->subHour(),
        ]);

        $this->postJson('/api/clients', ['registro' => 'REG-9', 'empresa' => 'Acme', 'fecha' => '2026-11-02 09:30'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(1, Company::where('registro', 'REG-9')->count());
    }

    public function test_listado_devuelve_los_clientes(): void
    {
        Company::create(['registro' => 'REG-1', 'empresa' => 'A', 'fecha' => now(), 'last_activity_at' => now()]);

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
