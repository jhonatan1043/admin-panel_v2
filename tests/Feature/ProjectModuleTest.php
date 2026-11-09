<?php

namespace Tests\Feature;

use App\Models\Button;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectModuleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function contactForm(): string
    {
        return <<<'HTML'
        <!doctype html><html><head><title>Contacto</title></head><body>
        <form id="formContactos">
            <input type="text" name="nombre" required>
            <input type="email" name="correo">
            <input type="text" name="telefono">
            <button type="submit">Guardar</button>
        </form>
        </body></html>
        HTML;
    }

    private function makeIntegratedProject(): array
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), [
            'name' => 'Call Center Norte',
            'description' => 'Vistas del norte',
        ])->assertRedirect();

        $project = Project::firstWhere('name', 'Call Center Norte');

        $this->actingAs($admin)->post(route('projects.views.store', $project), [
            'name' => 'Registrar contacto',
            'html' => $this->contactForm(),
        ])->assertRedirect();

        return [$admin, $project->fresh('views')];
    }

    public function test_crear_proyecto_genera_slug_y_queda_en_borrador(): void
    {
        [, $project] = $this->makeIntegratedProject();

        $this->assertSame('call-center-norte', $project->slug);
        $this->assertSame(Project::STATUS_DRAFT, $project->status);
    }

    public function test_cargar_una_vista_analiza_y_genera_la_integracion_sin_tocar_el_html(): void
    {
        [, $project] = $this->makeIntegratedProject();
        $view = $project->views->first();

        // El HTML original se conserva íntegro.
        $this->assertSame($this->contactForm(), $view->html);
        // Se detectaron los tres campos.
        $this->assertSame(3, $view->analysis['summary']['fields']);
        // Se generó integration.js con el endpoint correcto.
        $this->assertTrue($view->isIntegrated());
        $this->assertStringContainsString("/api/sitio/call-center-norte/{$view->slug}/submit", $view->integration_js);
    }

    public function test_no_publica_un_proyecto_sin_vistas(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Vacío'])->assertRedirect();
        $project = Project::firstWhere('name', 'Vacío');

        $this->actingAs($admin)->post(route('projects.publish', $project))->assertSessionHasErrors('publish');
        $this->assertSame(Project::STATUS_DRAFT, $project->fresh()->status);
    }

    public function test_publica_un_proyecto_con_vista_integrada(): void
    {
        [$admin, $project] = $this->makeIntegratedProject();

        $this->actingAs($admin)->post(route('projects.publish', $project))->assertRedirect();

        $this->assertSame(Project::STATUS_PUBLISHED, $project->fresh()->status);
        $this->assertNotNull($project->fresh()->published_at);
    }

    public function test_sirve_la_vista_publicada_conservando_el_html_e_inyectando_integracion(): void
    {
        [$admin, $project] = $this->makeIntegratedProject();
        $this->actingAs($admin)->post(route('projects.publish', $project));
        $view = $project->views->first();

        $html = $this->get(route('sitio.serve', [$project, $view->slug]))->assertOk()->getContent();

        $this->assertStringContainsString('<form id="formContactos">', $html); // original intacto
        $this->assertStringContainsString('data-project-view="integration"', $html); // integración añadida
        $this->assertStringContainsString('<meta name="csrf-token"', $html);
    }

    public function test_no_sirve_la_vista_si_el_proyecto_no_esta_publicado(): void
    {
        [, $project] = $this->makeIntegratedProject();
        $view = $project->views->first();

        $this->get(route('sitio.serve', [$project, $view->slug]))->assertNotFound();
    }

    public function test_el_envio_de_datos_registra_un_cliente_reutilizando_el_mecanismo_existente(): void
    {
        [$admin, $project] = $this->makeIntegratedProject();
        $this->actingAs($admin)->post(route('projects.publish', $project));
        $view = $project->views->first();

        $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", [
            'view_target' => 'formContactos',
            'data' => ['nombre' => 'Carlos Pérez', 'correo' => 'carlos@email.com', 'telefono' => '3001234567'],
        ])->assertCreated()->assertJson(['ok' => true]);

        $company = Company::firstWhere('empresa', 'Call Center Norte');
        $this->assertNotNull($company);
        $this->assertSame('Carlos Pérez', $company->name);
        $this->assertSame('carlos@email.com', $company->data['correo']);
        $this->assertSame($view->slug, $company->data['_vista']);
    }

    public function test_un_campo_unico_envia_solo_el_valor(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Solicitudes']);
        $project = Project::firstWhere('name', 'Solicitudes');
        $this->actingAs($admin)->post(route('projects.views.store', $project), [
            'name' => 'Numero',
            'html' => '<input type="text" id="numeroSolicitud" name="numeroSolicitud"><button id="btnEnviar">Continuar</button>',
        ]);
        $view = $project->views()->first();
        $this->assertSame('single', $view->spec['targets'][0]['mode']);

        $this->actingAs($admin)->post(route('projects.publish', $project));

        $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", [
            'view_target' => 'standalone',
            'value' => 'SOL-123',
        ])->assertCreated();

        $company = Company::where('empresa', 'Solicitudes')->first();
        $this->assertSame('SOL-123', $company->data['value']);
    }

    public function test_un_boton_existente_puede_enlazar_una_vista_de_proyecto(): void
    {
        [$admin, $project] = $this->makeIntegratedProject();
        $this->actingAs($admin)->post(route('projects.publish', $project));
        $view = $project->views->first();

        $this->actingAs($admin)->post(route('buttons.store'), [
            'label' => 'CONTACTOS',
            'text' => 'Abre el formulario de contacto',
            'project_view_id' => $view->id,
            'is_active' => '1',
        ])->assertRedirect();

        $button = Button::firstWhere('label', 'CONTACTOS');
        $this->assertSame($view->id, $button->project_view_id);
        $this->assertTrue($button->projectView->is($view));
    }

    public function test_la_pantalla_de_botones_lista_las_vistas_publicadas(): void
    {
        [$admin, $project] = $this->makeIntegratedProject();
        $this->actingAs($admin)->post(route('projects.publish', $project));

        $html = $this->actingAs($admin)->get(route('buttons.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Registrar contacto', $html);
        $this->assertStringContainsString('name="project_view_id"', $html);
    }

    public function test_asociar_subdominio_y_servir_por_host(): void
    {
        [$admin, $project] = $this->makeIntegratedProject();
        $this->actingAs($admin)->post(route('projects.publish', $project));

        $this->actingAs($admin)->post(route('projects.domains.store', $project), [
            'host' => 'contactos.midominio.com',
        ])->assertRedirect();

        $this->assertDatabaseHas('project_domains', [
            'host' => 'contactos.midominio.com',
            'is_primary' => true,
        ]);

        // Al entrar por el host del proyecto se sirve su vista principal.
        $html = $this->get('http://contactos.midominio.com/')->assertOk()->getContent();
        $this->assertStringContainsString('<form id="formContactos">', $html);
    }

    public function test_corregir_automaticamente_desactiva_el_submit_propio(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'OTP']);
        $project = Project::firstWhere('name', 'OTP');

        $this->actingAs($admin)->post(route('projects.views.store', $project), [
            'name' => 'Codigo',
            'html' => '<form id="otp"><input name="codigo" maxlength="1"><input name="codigo" maxlength="1"><button type="submit">Ir</button></form>',
            'js' => 'document.getElementById("otp").addEventListener("submit", function (e) { e.preventDefault(); mostrarError(); });',
        ]);
        $view = $project->views()->first();

        // El análisis avisa del submit propio.
        $this->assertTrue(collect($view->analysis['warnings'])->contains(fn ($w) => str_contains($w, 'su propio manejo de envío')));

        $this->actingAs($admin)->post(route('projects.views.fix', [$project, $view]))->assertRedirect();

        $view->refresh();
        $this->assertStringNotContainsString('mostrarError();', $view->js);
        $this->assertStringContainsString('submit desactivado por el panel', $view->js);
    }

    public function test_un_operador_entra_a_proyectos(): void
    {
        $operator = User::factory()->create(['role' => 'operador']);

        $this->actingAs($operator)->get(route('projects.index'))->assertOk();
    }
}
