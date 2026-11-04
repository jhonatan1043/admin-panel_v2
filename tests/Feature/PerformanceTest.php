<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramMessage;
use App\Models\Button;
use App\Models\ClientLog;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PerformanceTest extends TestCase
{
    use RefreshDatabase;

    // ---- Índices -------------------------------------------------------

    public function test_existen_los_indices_de_rendimiento(): void
    {
        $this->assertTrue(Schema::hasIndex('companies', ['last_activity_at']));
        $this->assertTrue(Schema::hasIndex('companies', ['messenger_id']));
        $this->assertTrue(Schema::hasIndex('companies', ['telegram_chat_id']));
        $this->assertTrue(Schema::hasIndex('client_logs', 'client_logs_company_recent_index'));
    }

    // ---- Envío en cola -------------------------------------------------

    private function clienteListo(): array
    {
        Setting::put('telegram_bot_token', 'TEST-TOKEN');
        $admin = User::factory()->create(['role' => 'admin']);
        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now(), 'messenger_id' => '555']);
        $button = Button::create(['label' => 'Hola', 'text' => 'Hola cliente']);

        return [$admin, $company, $button];
    }

    public function test_el_envio_se_encola_y_responde_sin_llamar_a_telegram(): void
    {
        Queue::fake();
        Http::fake();
        [$admin, $company, $button] = $this->clienteListo();

        $this->actingAs($admin)->postJson("/api/companies/{$company->id}/send", ['button_id' => $button->id])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'queued');

        Http::assertNothingSent(); // la petición no esperó a Telegram
        Queue::assertPushedOn('telegram', SendTelegramMessage::class, fn ($job) => $job->chatId === '555' && $job->text === 'Hola cliente');
        $this->assertDatabaseHas('client_logs', ['company_id' => $company->id, 'status' => 'queued']);
    }

    public function test_el_job_marca_el_registro_como_enviado(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
        Setting::put('telegram_bot_token', 'TEST-TOKEN');
        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        $log = ClientLog::create(['company_id' => $company->id, 'direction' => 'out', 'label' => 'Hola', 'text' => 'x', 'status' => 'queued']);

        (new SendTelegramMessage('555', 'x', $log->id))->handle();

        $this->assertDatabaseHas('client_logs', ['id' => $log->id, 'status' => 'sent', 'telegram_message_id' => 77]);
    }

    public function test_el_job_marca_el_registro_como_fallido_si_telegram_rechaza(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);
        Setting::put('telegram_bot_token', 'TEST-TOKEN');
        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        $log = ClientLog::create(['company_id' => $company->id, 'direction' => 'out', 'label' => 'Hola', 'text' => 'x', 'status' => 'queued']);

        (new SendTelegramMessage('555', 'x', $log->id))->handle();

        $this->assertDatabaseHas('client_logs', ['id' => $log->id, 'status' => 'failed', 'detail' => 'Telegram rechazó: chat not found']);
    }

    public function test_el_detalle_muestra_en_cola_y_no_como_fallido(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        ClientLog::create(['company_id' => $company->id, 'direction' => 'out', 'label' => 'Hola', 'text' => 'x', 'status' => 'queued', 'detail' => 'En cola de envío.']);

        $this->actingAs($admin)->get("/companies/{$company->id}/detail")
            ->assertOk()
            ->assertSee('En cola de envío', false)
            ->assertDontSee('✗', false);
    }

    // ---- Paginación ----------------------------------------------------

    private function crearClientes(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            Company::create(['registro' => 'REG-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()->subSeconds($i)]);
        }
    }

    public function test_get_clients_pagina_de_50_por_defecto(): void
    {
        $this->crearClientes(60);

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.total', 60)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.registro', 'REG-0001'); // el más reciente primero

        $this->getJson('/api/clients?page=2')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_get_clients_respeta_per_page_y_su_maximo(): void
    {
        $this->crearClientes(5);

        $this->getJson('/api/clients?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 3);
        $this->getJson('/api/clients?per_page=500')->assertStatus(422);
        $this->getJson('/api/clients?page=0')->assertStatus(422);
    }

    public function test_cada_pagina_se_invalida_al_cambiar_un_cliente(): void
    {
        $this->crearClientes(3);
        $this->getJson('/api/clients?per_page=2&page=2')->assertJsonCount(1, 'data');

        $this->crearClientes(0);
        Company::create(['registro' => 'REG-0099', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()->subHour()]);

        $this->getJson('/api/clients?per_page=2&page=2')->assertJsonCount(2, 'data');
    }
}
