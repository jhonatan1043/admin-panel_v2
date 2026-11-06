<?php

namespace App\Services\Projects;

use App\Models\Project;
use Illuminate\Validation\ValidationException;

/**
 * Publica y despublica proyectos. No se publica un proyecto con errores de
 * integración o configuración: debe tener al menos una vista y todas sus
 * vistas deben tener la integración generada.
 */
class ProjectPublisher
{
    /**
     * @return array<int,string> Problemas que impiden publicar (vacío = listo).
     */
    public function validate(Project $project): array
    {
        $problems = [];

        $views = $project->views()->get();
        if ($views->isEmpty()) {
            $problems[] = 'El proyecto no tiene vistas.';
        }

        foreach ($views as $view) {
            if (! $view->isIntegrated()) {
                $problems[] = "La vista «{$view->name}» no tiene integración generada (analízala primero).";
            }
        }

        return $problems;
    }

    public function publish(Project $project): Project
    {
        $problems = $this->validate($project);
        if ($problems !== []) {
            throw ValidationException::withMessages(['publish' => $problems]);
        }

        $project->forceFill([
            'status' => Project::STATUS_PUBLISHED,
            'published_at' => now(),
        ])->save();

        return $project;
    }

    public function unpublish(Project $project): Project
    {
        $project->forceFill(['status' => Project::STATUS_UNPUBLISHED])->save();

        return $project;
    }
}
