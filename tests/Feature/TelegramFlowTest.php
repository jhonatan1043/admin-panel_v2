<?php

namespace Tests\Feature;

use App\Models\Button;
use App\Models\ClientLog;
use App\Models\Company;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Flujo de envío a Telegram de punta a punta. En pruebas la cola es "sync", así que
 * el job SendTelegramMessage se ejecuta en línea y podemos verificar la llamada real
 * a la API (simulada con Http::fake). Sirve para distinguir un fallo de CÓDIGO de uno
 * de ENTORNO (bot/chat sin configurar, o el worker de cola sin procesar la cola).
 */
class TelegramFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function textButton(): Button
    {
        // Botón de TEXTO: sin vista y sin URL -> va a Telegram.
        return Button::create([
            'label' => 'AVISO',
            'text' => 'Tienes una solicitud nueva',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_un_boton_de_texto_envia_a_telegram_y_queda_en_sent(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 555]], 200),
        ]);

        Setting::put('telegram_bot_token', 'BOT123');
        Setting::put('telegram_chat_id', '-100999'); // chat global

        $admin = $this->admin();
        $company = Company::create([
            'empresa' => 'Embed', 'registro' => 'REG-1', 'fecha' => now(), 'last_activity_at' => now(),
        ]);
        $button = $this->textButton();

        $res = $this->actingAs($admin)->post(route('api.companies.send', $company), [
            'button_id' => $button->id,
        ])->assertOk();
        $res->assertJson(['ok' => true]);

        // Se llamó a la API de Telegram con el chat global y el texto del botón.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/botBOT123/sendMessage')
                && $request['chat_id'] === '-100999'
                && $request['text'] === 'Tienes una solicitud nueva';
        });

        // El registro queda "sent" con el message_id devuelto por Telegram.
        $log = ClientLog::where('company_id', $company->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertSame(555, $log->telegram_message_id);
    }

    public function test_usa_el_chat_propio_del_cliente_si_lo_tiene(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        Setting::put('telegram_bot_token', 'BOT123');
        Setting::put('telegram_chat_id', '-100999');

        $admin = $this->admin();
        $company = Company::create([
            'empresa' => 'X', 'registro' => 'REG-2', 'fecha' => now(), 'last_activity_at' => now(),
            'messenger_id' => '424242',
        ]);

        $this->actingAs($admin)->post(route('api.companies.send', $company), [
            'button_id' => $this->textButton()->id,
        ])->assertOk();

        // Prefiere el messenger_id del cliente, no el chat global.
        Http::assertSent(fn ($request) => $request['chat_id'] === '424242');
    }

    public function test_sin_bot_configurado_no_envia_y_queda_failed(): void
    {
        Http::fake();
        // Sin telegram_bot_token.
        $admin = $this->admin();
        $company = Company::create(['empresa' => 'X', 'registro' => 'REG-3', 'fecha' => now(), 'last_activity_at' => now()]);

        $this->actingAs($admin)->post(route('api.companies.send', $company), [
            'button_id' => $this->textButton()->id,
        ])->assertStatus(422);

        Http::assertNothingSent();
        $log = ClientLog::where('company_id', $company->id)->latest('id')->first();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('bot', mb_strtolower($log->detail));
    }

    public function test_sin_chat_propio_ni_global_no_envia_y_queda_failed(): void
    {
        Http::fake();
        Setting::put('telegram_bot_token', 'BOT123'); // hay bot, pero no hay chat alguno

        $admin = $this->admin();
        $company = Company::create(['empresa' => 'X', 'registro' => 'REG-4', 'fecha' => now(), 'last_activity_at' => now()]);

        $this->actingAs($admin)->post(route('api.companies.send', $company), [
            'button_id' => $this->textButton()->id,
        ])->assertStatus(422);

        Http::assertNothingSent();
        $log = ClientLog::where('company_id', $company->id)->latest('id')->first();
        $this->assertSame('failed', $log->status);
    }

    public function test_el_envio_de_una_vista_del_proyecto_notifica_a_telegram(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]], 200)]);
        Setting::put('telegram_bot_token', 'BOT123');
        Setting::put('telegram_chat_id', '-100777');

        $project = Project::create(['name' => 'Proyecto Demo']);
        $view = $project->views()->create(['name' => 'Registro', 'slug' => 'registro', 'html' => '<form></form>']);

        // El cliente embebido envía los datos de la vista.
        $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", [
            'data' => ['nombre' => 'Juan', 'codigo' => '123456'],
        ])->assertCreated();

        // Se mandó a Telegram con el nombre de la vista y los datos.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMessage')
                && $request['chat_id'] === '-100777'
                && str_contains($request['text'], 'Registro')
                && str_contains($request['text'], 'Juan')
                && str_contains($request['text'], '123456');
        });

        // El valor del cliente queda como ENTRANTE (aparece en el detalle de la tarjeta).
        $log = ClientLog::where('direction', 'in')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('123456', $log->text);
    }

    public function test_el_envio_de_vista_sin_telegram_igual_recibe_los_datos(): void
    {
        Http::fake(); // sin bot ni chat configurados

        $project = Project::create(['name' => 'Proyecto Sin TG']);
        $view = $project->views()->create(['name' => 'Registro', 'slug' => 'registro', 'html' => '<form></form>']);

        $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", [
            'data' => ['x' => '1'],
        ])->assertCreated();

        Http::assertNothingSent();
    }
}
