<?php

namespace Tests\Feature;

use App\Models\ClientLog;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function companyWithLoadingToken(): array
    {
        $company = Company::create([
            'registro' => 'REG-'.Str::uuid(), 'empresa' => 'A', 'fecha' => now(),
            'last_activity_at' => now(),
        ]);
        $token = (string) Str::uuid();
        $company->forceFill(['loading_token' => $token])->save();

        return [$company, $token];
    }

    public function test_guarda_la_respuesta_entrante_asociada_por_chat_id(): void
    {
        $company = Company::create([
            'registro' => 'REG-1', 'empresa' => 'A', 'fecha' => now(),
            'last_activity_at' => now(), 'telegram_chat_id' => '999',
        ]);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 999], 'text' => 'solicitud'],
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('client_logs', [
            'company_id' => $company->id, 'direction' => 'in', 'text' => 'solicitud', 'status' => 'received',
        ]);
    }

    public function test_guarda_el_messenger_id_del_usuario_que_escribe(): void
    {
        $company = Company::create([
            'registro' => 'REG-1', 'empresa' => 'A', 'fecha' => now(),
            'last_activity_at' => now(), 'telegram_chat_id' => '999',
        ]);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 999], 'from' => ['id' => 555], 'text' => 'solicitud'],
        ])->assertOk();

        // El messenger_id real queda guardado para que el botón llegue a ese usuario.
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'messenger_id' => '555']);
    }

    public function test_consulta_el_ultimo_mensaje_enviado_correctamente_desde_el_panel(): void
    {
        [$company, $token] = $this->companyWithLoadingToken();
        ClientLog::create([
            'company_id' => $company->id,
            'direction' => 'out',
            'text' => 'Licencia activa',
            'status' => 'sent',
            'telegram_message_id' => 42,
        ]);

        $this->getJson("/api/telegram/latest-message?session_token={$token}")
            ->assertOk()
            ->assertJsonPath('data.mensaje', 'Licencia activa')
            ->assertJsonPath('data.message_id', 42);
    }

    public function test_ignora_mensajes_entrantes_y_envios_fallidos_al_consultar_el_ultimo(): void
    {
        [$company, $token] = $this->companyWithLoadingToken();
        ClientLog::create([
            'company_id' => $company->id,
            'direction' => 'out',
            'text' => 'Mensaje enviado',
            'status' => 'sent',
            'telegram_message_id' => 42,
        ]);
        ClientLog::create([
            'company_id' => $company->id,
            'direction' => 'out',
            'text' => 'Mensaje fallido',
            'status' => 'failed',
        ]);
        ClientLog::create([
            'company_id' => $company->id,
            'direction' => 'in',
            'text' => 'Respuesta del cliente',
            'status' => 'received',
        ]);

        $this->getJson("/api/telegram/latest-message?session_token={$token}")
            ->assertOk()
            ->assertJsonPath('data.mensaje', 'Mensaje enviado');
    }

    public function test_un_message_id_solo_se_puede_usar_una_vez(): void
    {
        [$company, $token] = $this->companyWithLoadingToken();
        ClientLog::create([
            'company_id' => $company->id,
            'direction' => 'out',
            'text' => 'Licencia activa',
            'status' => 'sent',
            'telegram_message_id' => 42,
        ]);

        $payload = ['session_token' => $token, 'message_id' => 42];
        $this->postJson('/api/telegram/use-message', $payload)
            ->assertOk()
            ->assertJsonPath('data.used', true);
        $this->postJson('/api/telegram/use-message', $payload)
            ->assertOk()
            ->assertJsonPath('data.used', false);
        $this->getJson("/api/telegram/latest-message?session_token={$token}")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_no_puede_usar_un_message_id_que_ya_no_es_el_ultimo(): void
    {
        [$company, $token] = $this->companyWithLoadingToken();
        foreach ([42, 43] as $messageId) {
            ClientLog::create([
                'company_id' => $company->id,
                'direction' => 'out',
                'text' => 'Licencia activa',
                'status' => 'sent',
                'telegram_message_id' => $messageId,
            ]);
        }

        $this->postJson('/api/telegram/use-message', [
            'session_token' => $token,
            'message_id' => 42,
        ])
            ->assertOk()
            ->assertJsonPath('data.used', false);
    }

    public function test_cada_solicitud_solo_consulta_y_consume_sus_mensajes(): void
    {
        [$solicitudA, $tokenA] = $this->companyWithLoadingToken();
        [$solicitudB, $tokenB] = $this->companyWithLoadingToken();
        ClientLog::create([
            'company_id' => $solicitudA->id,
            'direction' => 'out',
            'text' => 'Respuesta A',
            'status' => 'sent',
            'telegram_message_id' => 101,
        ]);
        ClientLog::create([
            'company_id' => $solicitudB->id,
            'direction' => 'out',
            'text' => 'Respuesta B',
            'status' => 'sent',
            'telegram_message_id' => 202,
        ]);

        $this->getJson("/api/telegram/latest-message?session_token={$tokenA}")
            ->assertOk()
            ->assertJsonPath('data.mensaje', 'Respuesta A')
            ->assertJsonPath('data.message_id', 101);

        $this->postJson('/api/telegram/use-message', [
            'session_token' => $tokenA,
            'message_id' => 202,
        ])->assertOk()->assertJsonPath('data.used', false);

        $this->postJson('/api/telegram/use-message', [
            'session_token' => $tokenA,
            'message_id' => 101,
        ])->assertOk()->assertJsonPath('data.used', true);

        $this->getJson("/api/telegram/latest-message?session_token={$tokenB}")
            ->assertOk()
            ->assertJsonPath('data.mensaje', 'Respuesta B');
    }

    public function test_empareja_por_messenger_id(): void
    {
        $company = Company::create([
            'registro' => 'REG-2', 'empresa' => 'A', 'fecha' => now(),
            'last_activity_at' => now(), 'messenger_id' => '777',
        ]);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 123], 'from' => ['id' => 777], 'text' => 'hola'],
        ])->assertOk();

        $this->assertDatabaseHas('client_logs', [
            'company_id' => $company->id, 'direction' => 'in', 'text' => 'hola',
        ]);
    }

    public function test_ignora_chat_desconocido_pero_responde_ok(): void
    {
        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 555], 'text' => 'hola'],
        ])->assertOk();

        $this->assertDatabaseCount('client_logs', 0);
    }
}
