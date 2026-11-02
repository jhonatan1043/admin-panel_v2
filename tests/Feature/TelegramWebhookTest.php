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

    public function test_ignora_chat_desconocido_pero_responde_ok(): void
    {
        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 555], 'text' => 'hola'],
        ])->assertOk();

        $this->assertDatabaseCount('client_logs', 0);
    }
}
