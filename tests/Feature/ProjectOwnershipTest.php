<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::factory()->create(['role' => 'operador']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_el_operador_que_crea_un_proyecto_queda_como_dueno(): void
    {
        $op = $this->operator();

        $this->actingAs($op)->post(route('projects.store'), ['name' => 'Mi proyecto'])->assertRedirect();

        $project = Project::firstWhere('name', 'Mi proyecto');
        $this->assertSame($op->id, $project->operator_id);
    }

    public function test_el_operador_solo_ve_sus_propios_proyectos(): void
    {
        $op1 = $this->operator();
        $op2 = $this->operator();
        Project::create(['name' => 'De op1', 'operator_id' => $op1->id]);
        Project::create(['name' => 'De op2', 'operator_id' => $op2->id]);

        $html = $this->actingAs($op1)->get(route('projects.index'))->assertOk()->getContent();

        $this->assertStringContainsString('De op1', $html);
        $this->assertStringNotContainsString('De op2', $html);
    }

    public function test_el_admin_ve_todos_los_proyectos(): void
    {
        $op = $this->operator();
        Project::create(['name' => 'Del operador', 'operator_id' => $op->id]);
        Project::create(['name' => 'Sin dueno']);

        $html = $this->actingAs($this->admin())->get(route('projects.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Del operador', $html);
        $this->assertStringContainsString('Sin dueno', $html);
    }

    public function test_el_operador_no_puede_abrir_un_proyecto_ajeno(): void
    {
        $owner = $this->operator();
        $otro = $this->operator();
        $project = Project::create(['name' => 'Ajeno', 'operator_id' => $owner->id]);

        $this->actingAs($otro)->get(route('projects.show', $project))->assertForbidden();
        $this->actingAs($otro)->post(route('projects.views.store', $project), ['name' => 'x', 'html' => '<form><input name="a"><button>Ok</button></form>'])->assertForbidden();
        $this->actingAs($otro)->delete(route('projects.destroy', $project))->assertForbidden();
    }

    public function test_el_admin_puede_asignar_un_proyecto_a_un_operador(): void
    {
        $op = $this->operator();

        $this->actingAs($this->admin())->post(route('projects.store'), [
            'name' => 'Asignado',
            'operator_id' => $op->id,
        ])->assertRedirect();

        $this->assertSame($op->id, Project::firstWhere('name', 'Asignado')->operator_id);
    }

    public function test_el_admin_puede_reasignar_el_dueno(): void
    {
        $op1 = $this->operator();
        $op2 = $this->operator();
        $project = Project::create(['name' => 'P', 'operator_id' => $op1->id]);

        $this->actingAs($this->admin())->put(route('projects.update', $project), [
            'name' => 'P',
            'operator_id' => $op2->id,
        ])->assertRedirect();

        $this->assertSame($op2->id, $project->fresh()->operator_id);
    }

    public function test_el_operador_no_puede_reasignar_el_dueno(): void
    {
        $op = $this->operator();
        $otro = $this->operator();
        $project = Project::create(['name' => 'P', 'operator_id' => $op->id]);

        // El operador intenta pasarse... digo, pasar el proyecto a otro: se ignora.
        $this->actingAs($op)->put(route('projects.update', $project), [
            'name' => 'P editado',
            'operator_id' => $otro->id,
        ])->assertRedirect();

        $this->assertSame($op->id, $project->fresh()->operator_id);
        $this->assertSame('P editado', $project->fresh()->name);
    }

    public function test_el_operador_publica_su_propio_proyecto(): void
    {
        $op = $this->operator();
        $this->actingAs($op)->post(route('projects.store'), ['name' => 'Propio']);
        $project = Project::firstWhere('name', 'Propio');
        $this->actingAs($op)->post(route('projects.views.store', $project), [
            'name' => 'Contacto',
            'html' => '<form id="f"><input name="nombre" required><button type="submit">Ir</button></form>',
        ])->assertRedirect();

        $this->actingAs($op)->post(route('projects.publish', $project))->assertRedirect();

        $this->assertSame(Project::STATUS_PUBLISHED, $project->fresh()->status);
    }
}
