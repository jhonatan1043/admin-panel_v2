<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\ProjectView;
use App\Services\Projects\ProjectPublisher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct(private ProjectPublisher $publisher) {}

    public function index()
    {
        $projects = Project::withCount('views')->with('domains')->orderByDesc('id')->get();

        return view('projects.index', compact('projects'));
    }

    public function create()
    {
        return view('projects.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $project = Project::create($data);

        return redirect()
            ->route('projects.show', $project)
            ->with('status', 'Proyecto creado. Ahora agrega sus vistas.');
    }

    public function show(Project $project)
    {
        $project->load(['views', 'domains', 'assets']);
        $formViews = $project->views->where('role', ProjectView::ROLE_STANDARD)->values();
        $loadingView = $project->views->firstWhere('role', ProjectView::ROLE_LOADING);
        $problems = $this->publisher->validate($project);

        return view('projects.show', compact('project', 'formViews', 'loadingView', 'problems'));
    }

    public function edit(Project $project)
    {
        return view('projects.edit', compact('project'));
    }

    public function update(Request $request, Project $project)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $project->update($data);

        return redirect()->route('projects.show', $project)->with('status', 'Proyecto actualizado.');
    }

    public function destroy(Project $project)
    {
        $project->delete();

        return redirect()->route('projects.index')->with('status', 'Proyecto eliminado.');
    }

    public function publish(Project $project)
    {
        $this->publisher->publish($project);

        return back()->with('status', 'Proyecto publicado.');
    }

    public function unpublish(Project $project)
    {
        $this->publisher->unpublish($project);

        return back()->with('status', 'Proyecto despublicado.');
    }

    public function storeDomain(Request $request, Project $project)
    {
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
        abort_unless($domain->project_id === $project->id, 404);
        $domain->delete();

        return back()->with('status', 'Subdominio eliminado.');
    }
}
