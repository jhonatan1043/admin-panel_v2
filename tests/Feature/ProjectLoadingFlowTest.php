<?php

namespace Tests\Feature;

use App\Models\Button;
use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectView;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProjectLoadingFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Proyecto publicado con una vista de contacto y una pantalla de carga. */
    private function publishedProject(): array
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Call Center Norte']);
        $project = Project::firstWhere('name', 'Call Center Norte');

        $this->actingAs($admin)->post(route('projects.views.store', $project), [
            'name' => 'Registrar contacto',
            'html' => '<form id="f"><input type="text" name="nombre" required><button type="submit">Ir</button></form>',
        ]);

        $this->actingAs($admin)->post(route('projects.loading.store', $project), [
            'html' => '<div class="wait">Procesando...</div>',
            'css' => '.wait{color:#0f0}',
        ]);

        $this->actingAs($admin)->post(route('projects.publish', $project));

        return [$admin, $project->fresh(['views'])];
    }

    public function test_la_pantalla_de_carga_se_crea_con_rol_loading_y_script_de_espera(): void
    {
        [, $project] = $this->publishedProject();
        $loading = $project->loadingView();

        $this->assertNotNull($loading);
        $this->assertTrue($loading->isLoading());
        $this->assertStringContainsString('/next', $loading->integration_js);
        // El HTML de la pantalla de carga se conserva intacto.
        $this->assertStringContainsString('Procesando...', $loading->html);
    }

    public function test_solo_hay_una_pantalla_de_carga_por_proyecto(): void
    {
        [$admin, $project] = $this->publishedProject();

        $this->actingAs($admin)->post(route('projects.loading.store', $project), [
            'html' => '<div>Otra espera</div>',
        ]);

        $this->assertSame(1, $project->views()->where('role', ProjectView::ROLE_LOADING)->count());
        $this->assertStringContainsString('Otra espera', $project->loadingView()->html);
    }

    public function test_al_enviar_el_formulario_la_respuesta_redirige_a_la_pantalla_de_carga(): void
    {
        [, $project] = $this->publishedProject();
        $view = $project->formViews()->first();

        $res = $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", [
            'data' => ['nombre' => 'Ana'],
        ])->assertCreated();

        $token = $res->json('loading_token');
        $this->assertNotNull($token);
        $base = rtrim((string) config('app.url'), '/');
        $this->assertSame("{$base}/sitio/{$project->slug}/cargando?s=".urlencode($token), $res->json('loading_url'));
    }

    public function test_un_segundo_envio_con_la_misma_sesion_no_crea_otro_cliente(): void
    {
        [, $project] = $this->publishedProject();
        $view = $project->formViews()->first();

        $token = $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", [
            'data' => ['nombre' => 'Ana'],
        ])->json('loading_token');

        $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", [
            'session' => $token,
            'data' => ['telefono' => '300'],
        ])->assertCreated();

        $this->assertSame(1, Company::count());
        $company = Company::first();
        $this->assertSame('Ana', $company->name);
        $this->assertSame('300', $company->data['telefono']);
    }

    public function test_el_boton_amarrado_a_una_vista_pide_esa_vista_al_cliente(): void
    {
        [$admin, $project] = $this->publishedProject();
        $view = $project->formViews()->first();

        $token = $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", [
            'data' => ['nombre' => 'Ana'],
        ])->json('loading_token');
        $company = Company::firstWhere('loading_token', $token);

        $button = Button::create([
            'label' => 'Pedir teléfono',
            'text' => '-',
            'project_view_id' => $view->id,
            'is_active' => true,
        ]);

        // El operador presiona el botón en la tarjeta del cliente.
        $this->actingAs($admin)
            ->postJson("/api/companies/{$company->id}/send", ['button_id' => $button->id])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'next_view_set']);

        $this->assertSame($view->id, $company->fresh()->next_view_id);
    }

    public function test_el_sondeo_next_devuelve_la_vista_pedida_una_sola_vez(): void
    {
        [$admin, $project] = $this->publishedProject();
        $view = $project->formViews()->first();
        $token = $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", [
            'data' => ['nombre' => 'Ana'],
        ])->json('loading_token');
        $company = Company::firstWhere('loading_token', $token);
        $company->forceFill(['next_view_id' => $view->id, 'next_view_at' => now()])->save();

        // Primera consulta: entrega la URL con la sesión.
        $this->getJson("/api/sitio/{$project->slug}/{$token}/next")
            ->assertOk()
            ->assertJson(['url' => rtrim((string) config('app.url'), '/')."/sitio/{$project->slug}/{$view->slug}?s=".urlencode($token), 'view' => $view->slug]);

        // Segunda consulta: ya consumida, no se repite.
        $this->getJson("/api/sitio/{$project->slug}/{$token}/next")
            ->assertOk()
            ->assertJson(['url' => null]);
    }

    public function test_el_sondeo_por_sesion_devuelve_la_vista_pedida(): void
    {
        [$admin, $project] = $this->publishedProject();
        $view = $project->formViews()->first();
        $token = $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", [
            'data' => ['nombre' => 'Ana'],
        ])->json('loading_token');
        $company = Company::firstWhere('loading_token', $token);
        $company->forceFill(['next_view_id' => $view->id, 'next_view_at' => now()])->save();

        // Endpoint por token de sesión (sin proyecto en la URL): identifica al usuario exacto.
        $this->getJson("/api/solicitud/{$token}/next")
            ->assertOk()
            ->assertJson(['url' => rtrim((string) config('app.url'), '/')."/sitio/{$project->slug}/{$view->slug}?s=".urlencode($token), 'view' => $view->slug]);

        // Se consume: la segunda vez ya no la repite.
        $this->getJson("/api/solicitud/{$token}/next")->assertOk()->assertJson(['url' => null]);
    }

    public function test_next_sin_vista_pendiente_devuelve_null(): void
    {
        [, $project] = $this->publishedProject();
        $view = $project->formViews()->first();
        $token = $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", [
            'data' => ['nombre' => 'Ana'],
        ])->json('loading_token');

        $this->getJson("/api/sitio/{$project->slug}/{$token}/next")
            ->assertOk()
            ->assertJson(['url' => null]);
    }

    public function test_un_boton_de_texto_sin_vista_sigue_enviando_por_telegram(): void
    {
        [$admin, $project] = $this->publishedProject();
        $view = $project->formViews()->first();
        $token = $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", [
            'data' => ['nombre' => 'Ana'],
        ])->json('loading_token');
        $company = Company::firstWhere('loading_token', $token);
        $company->forceFill(['messenger_id' => '123'])->save();
        Setting::put('telegram_bot_token', 'x');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $button = Button::create(['label' => 'Hola', 'text' => 'Mensaje', 'is_active' => true]);

        $this->actingAs($admin)
            ->postJson("/api/companies/{$company->id}/send", ['button_id' => $button->id])
            ->assertOk()
            ->assertJson(['status' => 'queued']);

        $this->assertNull($company->fresh()->next_view_id);
    }
}
