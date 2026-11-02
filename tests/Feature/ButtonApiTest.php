<?php

namespace Tests\Feature;

use App\Models\Button;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ButtonApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_los_botones_con_id_nombre_mensaje_y_url(): void
    {
        $button = Button::create([
            'label' => 'Licencia', 'text' => 'Tu licencia está activa.', 'url' => 'https://interno/accion',
        ]);

        $this->getJson('/api/buttons')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $button->id)
            ->assertJsonPath('data.0.nombre', 'Licencia')
            ->assertJsonPath('data.0.mensaje', 'Tu licencia está activa.')
            ->assertJsonPath('data.0.url', 'https://interno/accion');
    }

    public function test_incluye_tambien_los_botones_inactivos(): void
    {
        Button::create(['label' => 'Activo', 'text' => 'A', 'is_active' => true]);
        Button::create(['label' => 'Inactivo', 'text' => 'B', 'is_active' => false]);

        $this->getJson('/api/buttons')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
