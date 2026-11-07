<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectPrimaryViewTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Proyecto publicado con dos vistas de formulario. */
    private function projectWithTwoViews(): array
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Norte']);
        $project = Project::firstWhere('name', 'Norte');

        $this->actingAs($admin)->post(route('projects.views.store', $project), [
            'name' => 'Contacto', 'html' => '<div id="A"><form id="a"><input name="x"><button type="submit">Ir</button></form></div>',
        ]);
        $this->actingAs($admin)->post(route('projects.views.store', $project), [
            'name' => 'Solicitud', 'html' => '<div id="B"><form id="b"><input name="y"><button type="submit">Ir</button></form></div>',
        ]);
        $this->actingAs($admin)->post(route('projects.publish', $project));

        return [$admin, $project->fresh('views')];
    }

    public function test_por_defecto_la_raiz_sirve_la_primera_vista(): void
    {
        [, $project] = $this->projectWithTwoViews();

        $html = $this->get(route('sitio.serve', $project))->assertOk()->getContent();
        $this->assertStringContainsString('id="A"', $html); // la primera vista
    }

    public function test_marcar_una_vista_como_principal_la_sirve_en_la_raiz(): void
    {
        [$admin, $project] = $this->projectWithTwoViews();
        $segunda = $project->formViews()->orderBy('id')->get()->last();

        $this->actingAs($admin)
            ->post(route('projects.views.primary', [$project, $segunda]))
            ->assertRedirect();

        $this->assertSame($segunda->id, $project->fresh()->primary_view_id);

        $html = $this->get(route('sitio.serve', $project))->assertOk()->getContent();
        $this->assertStringContainsString('id="B"', $html); // ahora la segunda
    }

    public function test_la_pantalla_de_carga_no_puede_ser_principal(): void
    {
        [$admin, $project] = $this->projectWithTwoViews();
        $this->actingAs($admin)->post(route('projects.loading.store', $project), ['html' => '<div>wait</div>']);
        $loading = $project->loadingView();

        $this->actingAs($admin)
            ->post(route('projects.views.primary', [$project, $loading]))
            ->assertStatus(422);
    }

    public function test_un_operador_ajeno_no_puede_marcar_la_principal(): void
    {
        [, $project] = $this->projectWithTwoViews();
        $otro = User::factory()->create(['role' => 'operador']);
        $vista = $project->formViews()->first();

        $this->actingAs($otro)
            ->post(route('projects.views.primary', [$project, $vista]))
            ->assertForbidden();
    }

    public function test_si_se_borra_la_principal_la_raiz_cae_en_la_primera(): void
    {
        [$admin, $project] = $this->projectWithTwoViews();
        $segunda = $project->formViews()->orderBy('id')->get()->last();
        $this->actingAs($admin)->post(route('projects.views.primary', [$project, $segunda]));

        // Al borrar la vista principal, la FK queda null y la raíz usa la primera.
        $this->actingAs($admin)->delete(route('projects.views.destroy', [$project, $segunda]))->assertRedirect();

        $this->assertNull($project->fresh()->primary_view_id);
        $html = $this->get(route('sitio.serve', $project))->assertOk()->getContent();
        $this->assertStringContainsString('id="A"', $html);
    }
}
