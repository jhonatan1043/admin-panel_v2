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
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

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
        ]);
    }

    public function test_usa_el_chat_id_global_cuando_el_cliente_no_tiene(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        Setting::put('telegram_bot_token', 'token-global');
        Setting::put('telegram_chat_id', '-100777'); // chat_id global de respaldo

        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company(['telegram_chat_id' => null]); // cliente sin chat_id propio
        $button = $this->button();

        $this->actingAs($admin)
            ->postJson("/api/companies/{$company->id}/send", ['button_id' => $button->id])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn ($req) => $req['chat_id'] === '-100777');
        $this->assertDatabaseHas('client_logs', [
            'company_id' => $company->id, 'direction' => 'out', 'status' => 'sent',
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
}
