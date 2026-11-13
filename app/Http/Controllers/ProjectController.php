<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\ProjectView;
use App\Models\User;
use App\Services\Projects\ProjectFolderImporter;
use App\Services\Projects\ProjectPublisher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct(private ProjectPublisher $publisher) {}

    /**
     * Importa una carpeta completa como proyecto (modalidad aparte): ubica el index,
     * toma cada .html como vista y resuelve sus archivos; los que no existen o pesan
     * de más se listan como faltantes. Reemplaza las vistas estándar actuales.
     */
    public function importFolder(Request $request, Project $project, ProjectFolderImporter $importer)
    {
        $this->authorizeProject($project);

        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file'],
            'paths' => ['required', 'array'],
            'paths.*' => ['string'],
            'mode' => ['nullable', 'in:linked,all'],
            'strategy' => ['nullable', 'in:replace,add'],
        ]);

        $result = $importer->import($project, $request->file('files'), (array) $request->input('paths'), [
            'only_linked' => $request->input('mode', 'linked') !== 'all',
            'replace' => $request->input('strategy', 'replace') !== 'add',
        ]);

        if (! empty($result['error'])) {
            return back()->withErrors(['files' => $result['error']]);
        }

        return redirect()->route('projects.show', $project)
            ->with('status', "Carpeta importada: {$result['views']} vista(s).")
            ->with('import_missing', $result['missing']);
    }

    public function index()
    {
        // El admin ve todos los proyectos; el operador, solo los suyos.
        $projects = Project::withCount('views')->with(['domains', 'operator'])
            ->when(! $this->isAdmin(), fn ($q) => $q->where('operator_id', auth()->id()))
            ->orderByDesc('id')
            ->get();

        return view('projects.index', compact('projects'));
    }

    public function create()
    {
        return view('projects.create', ['operators' => $this->operatorOptions()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'operator_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        // El operador siempre es dueño de lo que crea; solo el admin puede asignar a otro.
        $data['operator_id'] = $this->isAdmin()
            ? ($request->input('operator_id') ?: null)
            : auth()->id();

        $project = Project::create($data);

        return redirect()
            ->route('projects.show', $project)
            ->with('status', 'Proyecto creado. Ahora agrega sus vistas.');
    }

    public function show(Project $project)
    {
        $this->authorizeProject($project);

        $project->load(['views', 'domains', 'assets', 'operator']);
        $formViews = $project->views->where('role', ProjectView::ROLE_STANDARD)->values();
        $loadingView = $project->views->firstWhere('role', ProjectView::ROLE_LOADING);
        $problems = $this->publisher->validate($project);

        return view('projects.show', [
            'project' => $project,
            'formViews' => $formViews,
            'loadingView' => $loadingView,
            'problems' => $problems,
            'operators' => $this->operatorOptions(),
            // Vista que se sirve en la raíz (la principal elegida o la de respaldo).
            'indexViewId' => $project->indexView()?->id,
        ]);
    }

    public function edit(Project $project)
    {
        $this->authorizeProject($project);

        return view('projects.edit', ['project' => $project, 'operators' => $this->operatorOptions()]);
    }

    public function update(Request $request, Project $project)
    {
        $this->authorizeProject($project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'operator_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        // Solo el admin puede reasignar el dueño; el operador no cambia la asignación.
        if ($this->isAdmin()) {
            $data['operator_id'] = $request->input('operator_id') ?: null;
        } else {
            unset($data['operator_id']);
        }

        $project->update($data);

        return redirect()->route('projects.show', $project)->with('status', 'Proyecto actualizado.');
    }

    public function destroy(Project $project)
    {
        $this->authorizeProject($project);
        $project->delete();

        return redirect()->route('projects.index')->with('status', 'Proyecto eliminado.');
    }

    public function publish(Project $project)
    {
        $this->authorizeProject($project);
        $this->publisher->publish($project);

        return back()->with('status', 'Proyecto publicado.');
    }

    public function unpublish(Project $project)
    {
        $this->authorizeProject($project);
        $this->publisher->unpublish($project);

        return back()->with('status', 'Proyecto despublicado.');
    }

    public function storeDomain(Request $request, Project $project)
    {
        $this->authorizeProject($project);

        $data = $request->validate([
            'host' => [
                'required', 'string', 'max:255',
                'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/i',
                Rule::unique('project_domains', 'host'),
            ],
        ], [], ['host' => 'subdominio']);

        $project->domains()->create([
            'host' => strtolower($data['host']),
            'is_primary' => $project->domains()->count() === 0,
        ]);

        return back()->with('status', 'Subdominio asociado.');
    }

    public function destroyDomain(Project $project, ProjectDomain $domain)
    {
        $this->authorizeProject($project);
        abort_unless($domain->project_id === $project->id, 404);
        $domain->delete();

        return back()->with('status', 'Subdominio eliminado.');
    }

    /** Aborta con 403 si el usuario no puede gestionar este proyecto. */
    private function authorizeProject(Project $project): void
    {
        abort_unless($project->managedBy(auth()->user()), 403);
    }

    private function isAdmin(): bool
    {
        return auth()->user()?->role === 'admin';
    }

    /** Lista de operadores para asignar (solo la usa el admin). */
    private function operatorOptions()
    {
        return $this->isAdmin()
            ? User::whereIn('role', ['operador', 'admin'])->orderBy('name')->get(['id', 'name', 'role'])
            : collect();
    }
}
