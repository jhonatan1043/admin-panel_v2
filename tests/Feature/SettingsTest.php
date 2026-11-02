<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_probar_falla_sin_bot_token(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/configuracion/probar', [])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_probar_conexion_ok_con_token_valido(): void
    {
        Http::fake(['api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'mi_bot']], 200)]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/configuracion/probar', ['telegram_bot_token' => '123:ABC'])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_probar_detecta_token_invalido(): void
    {
        Http::fake(['api.telegram.org/*/getMe' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/configuracion/probar', ['telegram_bot_token' => 'malo'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_probar_envia_ping_cuando_hay_chat_id(): void
    {
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'mi_bot']], 200),
            'api.telegram.org/*/sendMessage' => Http::response(['ok' => true], 200),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/configuracion/probar', [
            'telegram_bot_token' => '123:ABC', 'telegram_chat_id' => '-100777',
        ])->assertOk()->assertJsonPath('ok', true);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'sendMessage') && $req['chat_id'] === '-100777');
    }
}
