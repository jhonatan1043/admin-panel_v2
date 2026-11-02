<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_operador_no_entra_a_configuracion(): void
    {
        $operador = User::factory()->create(['role' => 'operador']);

        $this->actingAs($operador)->get('/configuracion')->assertForbidden();
    }

    public function test_el_admin_ve_la_configuracion_global_del_bot(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/configuracion')
            ->assertOk()
            ->assertSee('Bot token');
    }

    public function test_guarda_el_bot_token_y_el_chat_id_global(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put('/configuracion', [
            'telegram_bot_token' => '123:ABC',
            'telegram_chat_id' => '-100999',
        ])->assertRedirect();

        $this->assertSame('123:ABC', Setting::get('telegram_bot_token'));
        $this->assertSame('-100999', Setting::get('telegram_chat_id'));
    }
}
