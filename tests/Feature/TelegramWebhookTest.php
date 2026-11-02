<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

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
