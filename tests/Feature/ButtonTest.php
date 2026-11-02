<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_formulario_de_alta_trae_activo_marcado_por_defecto(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get('/botones')->assertOk()->getContent();

        // El checkbox de alta debe venir marcado para que el botón nuevo salga activo.
        $this->assertStringContainsString('name="is_active" value="1" checked', $html);
    }

    public function test_un_boton_creado_queda_activo_y_visible(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post('/botones', ['label' => 'Nuevo', 'text' => 'hola', 'is_active' => '1', 'sort_order' => 0])
            ->assertRedirect();

        $this->assertDatabaseHas('buttons', ['label' => 'Nuevo', 'is_active' => true]);
    }
}
