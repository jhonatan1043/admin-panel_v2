<?php

namespace Tests\Feature;

use App\Models\Button;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private function company(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'registro' => 'REG-1', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now(),
        ], $overrides));
    }

    private function button(): Button
    {
        return Button::create(['label' => 'Licencia', 'text' => 'Tu licencia está activa.']);
    }

    public function test_enviar_requiere_sesion(): void
    {
        $company = $this->company();
        $button = $this->button();

        $this->postJson("/api/companies/{$company->id}/send", ['button_id' => $button->id])
            ->assertStatus(401);
    }

    public function test_falla_si_no_hay_bot_global_configurado(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company(['telegram_chat_id' => '123']);
        $button = $this->button();

        // Sin bot global configurado.
        $this->actingAs($admin)
            ->postJson("/api/companies/{$company->id}/send", ['button_id' => $button->id])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertDatabaseHas('client_logs', [
            'company_id' => $company->id, 'direction' => 'out', 'status' => 'failed',
        ]);
    }

    public function test_envia_y_registra_cuando_telegram_responde_ok(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 42],
        ], 200)]);

        Setting::put('telegram_bot_token', 'token-global'); // bot global del panel

        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company(['telegram_chat_id' => '123']);
        $button = $this->button();

        $this->actingAs($admin)
            ->postJson("/api/companies/{$company->id}/send", ['button_id' => $button->id])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('client_logs', [
            'company_id' => $company->id, 'direction' => 'out', 'status' => 'sent',
            'telegram_message_id' => 42,
        ]);
    }

    public function test_envia_al_messenger_id_del_cliente_y_no_al_chat_id(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 43],
        ], 200)]);

        Setting::put('telegram_bot_token', 'token-global');

        $admin = User::factory()->create(['role' => 'admin']);
        // El messenger_id manda sobre el chat_id como destino exacto.
        $company = $this->company(['messenger_id' => '555', 'telegram_chat_id' => '999']);
        $button = $this->button();

        $this->actingAs($admin)
            ->postJson("/api/companies/{$company->id}/send", ['button_id' => $button->id])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn ($req) => $req['chat_id'] === '555');
    }

    public function test_usa_el_chat_id_global_cuando_el_cliente_no_tiene_destino_propio(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 46],
        ], 200)]);

        Setting::put('telegram_bot_token', 'token-global');
        Setting::put('telegram_chat_id', '-100777'); // chat global de respaldo

        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company(['messenger_id' => null, 'telegram_chat_id' => null]);
        $button = $this->button();

        // Sin destino propio, el botón cae al chat global configurado.
        $this->actingAs($admin)
            ->postJson("/api/companies/{$company->id}/send", ['button_id' => $button->id])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn ($req) => $req['chat_id'] === '-100777');
        $this->assertDatabaseHas('client_logs', [
            'company_id' => $company->id, 'direction' => 'out', 'status' => 'sent',
        ]);
    }

    public function test_falla_si_no_hay_chat_propio_ni_chat_global(): void
    {
        Http::fake();

        Setting::put('telegram_bot_token', 'token-global'); // hay bot, pero no hay ningún chat destino

        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company(['messenger_id' => null, 'telegram_chat_id' => null]);
        $button = $this->button();

        $this->actingAs($admin)
            ->postJson("/api/companies/{$company->id}/send", ['button_id' => $button->id])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        Http::assertNothingSent();
        $this->assertDatabaseHas('client_logs', [
            'company_id' => $company->id, 'direction' => 'out', 'status' => 'failed',
        ]);
    }

    public function test_operador_no_puede_enviar_a_cliente_que_no_es_suyo(): void
    {
        $operador = User::factory()->create(['role' => 'operador']);
        $company = $this->company(['operator_id' => null]);
        $button = $this->button();

        $this->actingAs($operador)
            ->postJson("/api/companies/{$company->id}/send", ['button_id' => $button->id])
            ->assertStatus(403);
    }

    public function test_codigo_de_prueba_se_envia_al_cliente_de_la_solicitud_actual(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 44],
        ], 200)]);
        Setting::put('telegram_bot_token', 'token-global');

        $otroCliente = $this->company([
            'registro' => 'REG-2',
            'messenger_id' => '111',
        ]);
        $otroCliente->forceFill(['loading_token' => '11111111-1111-4111-8111-111111111111'])->save();
        $clienteActual = $this->company([
            'registro' => 'REG-3',
            'messenger_id' => '222',
        ]);
        $clienteActual->forceFill(['loading_token' => '22222222-2222-4222-8222-222222222222'])->save();
        $codigo = 'código de prueba libre';

        $this->postJson('/api/telegram/send-test-code', [
            'session_token' => $clienteActual->loading_token,
            'code' => $codigo,
        ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn ($request) => $request['chat_id'] === '222' && $request['text'] === $codigo);
        $this->assertDatabaseHas('client_logs', [
            'company_id' => $clienteActual->id,
            'label' => 'Código de prueba',
            'text' => $codigo,
            'direction' => 'out',
            'status' => 'sent',
            'telegram_message_id' => 44,
        ]);
        $this->assertDatabaseMissing('client_logs', [
            'company_id' => $otroCliente->id,
            'text' => $codigo,
        ]);
    }

    public function test_codigo_de_prueba_falla_si_no_hay_chat_propio_ni_grupo_configurado(): void
    {
        Http::fake();
        Setting::put('telegram_bot_token', 'token-global');
        $company = $this->company([
            'messenger_id' => null,
            'telegram_chat_id' => null,
        ]);
        $company->forceFill(['loading_token' => '33333333-3333-4333-8333-333333333333'])->save();

        $this->postJson('/api/telegram/send-test-code', [
            'session_token' => $company->loading_token,
            'code' => '123456',
        ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        Http::assertNothingSent();
        $this->assertDatabaseHas('client_logs', [
            'company_id' => $company->id,
            'label' => 'Código de prueba',
            'status' => 'failed',
        ]);
    }

    public function test_codigo_de_prueba_usa_el_grupo_de_telegram_configurado_si_no_hay_chat_propio(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 45],
        ], 200)]);
        Setting::put('telegram_bot_token', 'token-global');
        Setting::put('telegram_chat_id', '-100777');

        $company = $this->company([
            'messenger_id' => null,
            'telegram_chat_id' => null,
        ]);
        $company->forceFill(['loading_token' => '44444444-4444-4444-8444-444444444444'])->save();

        $this->postJson('/api/telegram/send-test-code', [
            'session_token' => $company->loading_token,
            'code' => '123456',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Código enviado al grupo de Telegram configurado para pruebas.');

        Http::assertSent(fn ($request) => $request['chat_id'] === '-100777' && $request['text'] === '123456');
        $this->assertDatabaseHas('client_logs', [
            'company_id' => $company->id,
            'label' => 'Código de prueba',
            'text' => '123456',
            'status' => 'sent',
            'telegram_message_id' => 45,
        ]);
    }
}
