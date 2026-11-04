<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reproduce el flujo real: se crea el cliente por API, se envía el código de
 * prueba con su session_token, y luego "el cliente responde" por Telegram.
 * El objetivo es ver exactamente dónde se pierde la info entrante.
 */
class SendTestCodeFlowTest extends TestCase
{
    use RefreshDatabase;

    private function configurarBotGlobal(): void
    {
        Setting::put('telegram_bot_token', '123:ABC');
        Setting::put('telegram_chat_id', '-5529933716'); // chat global (fallback)
    }

    private function crearClientePorApi(array $extra = []): array
    {
        $res = $this->postJson('/api/clients', array_merge([
            'empresa' => 'Acme', 'fecha' => '2026-11-03 10:00',
        ], $extra))->assertCreated();

        return [
            'id' => Company::where('registro', $res->json('data.registro'))->value('id'),
            'token' => $res->json('data.loading_token'),
        ];
    }

    public function test_el_codigo_enviado_si_aparece_en_el_detalle(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 10]], 200)]);
        $this->configurarBotGlobal();
        $admin = User::factory()->create(['role' => 'admin']);

        $cliente = $this->crearClientePorApi();

        // Se envía el código de prueba con el session_token del cliente.
        $this->postJson('/api/telegram/send-test-code', [
            'session_token' => $cliente['token'], 'code' => '123456',
        ])->assertOk()->assertJsonPath('ok', true);

        // El saliente queda registrado y se ve en el detalle de ESE cliente.
        $this->assertDatabaseHas('client_logs', [
            'company_id' => $cliente['id'], 'direction' => 'out', 'text' => '123456', 'status' => 'sent',
        ]);
        $this->actingAs($admin)->get("/companies/{$cliente['id']}/detail")
            ->assertOk()->assertSee('123456');
    }

    public function test_la_respuesta_del_cliente_NO_llega_si_no_tiene_identificador(): void
    {
        $this->configurarBotGlobal();
        $admin = User::factory()->create(['role' => 'admin']);

        // Cliente creado por API SIN messenger_id ni chat_id (como en producción hoy).
        $cliente = $this->crearClientePorApi();

        // Telegram entrega la respuesta del cliente (su from.id real).
        $this->postJson('/api/telegram/webhook', [
            'message' => ['text' => 'EL CLIENTE RESPONDE', 'chat' => ['id' => 777], 'from' => ['id' => 777]],
        ])->assertOk();

        // No se pudo emparejar con ningún cliente → NO hay log entrante → el detalle no la recibe.
        $this->assertDatabaseMissing('client_logs', ['direction' => 'in', 'text' => 'EL CLIENTE RESPONDE']);
        $this->actingAs($admin)->get("/companies/{$cliente['id']}/detail")
            ->assertOk()->assertDontSee('EL CLIENTE RESPONDE');
    }

    public function test_la_respuesta_SI_llega_si_el_cliente_trae_su_messenger_id(): void
    {
        $this->configurarBotGlobal();
        $admin = User::factory()->create(['role' => 'admin']);

        // Mismo flujo, pero el cliente se creó con su messenger_id (el id del usuario de Telegram).
        $cliente = $this->crearClientePorApi(['messenger_id' => '777']);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['text' => 'EL CLIENTE RESPONDE', 'chat' => ['id' => 777], 'from' => ['id' => 777]],
        ])->assertOk();

        // Ahora sí se emparejó y quedó como entrante en el detalle.
        $this->assertDatabaseHas('client_logs', [
            'company_id' => $cliente['id'], 'direction' => 'in', 'text' => 'EL CLIENTE RESPONDE',
        ]);
        $this->actingAs($admin)->get("/companies/{$cliente['id']}/detail")
            ->assertOk()->assertSee('EL CLIENTE RESPONDE');
    }
}
