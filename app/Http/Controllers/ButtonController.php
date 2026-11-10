<?php

namespace App\Http\Controllers;

use App\Models\Button;
use App\Models\ProjectView;
use Illuminate\Http\Request;

class ButtonController extends Controller
{
    public function index()
    {
        $buttons = Button::catalog();

        // Vistas de proyectos (todo es dinámico; ya no hay "publicación"), para que
        // un botón pueda abrir una de ellas. Se excluyen las vistas de carga.
        $projectViews = ProjectView::with('project')
            ->where('role', '!=', ProjectView::ROLE_LOADING)
            ->whereHas('project')
            ->get()
            ->sortBy(fn (ProjectView $v) => optional($v->project)->name)
            ->groupBy(fn (ProjectView $v) => optional($v->project)->name);

        return view('buttons.index', compact('buttons', 'projectViews'));
    }

    public function store(Request $request)
    {
        Button::create($this->validated($request));

        return back()->with('status', 'Botón creado.');
    }

    public function update(Request $request, Button $button)
    {
        $button->update($this->validated($request));

        return back()->with('status', 'Botón actualizado.');
    }

    public function destroy(Button $button)
    {
        $button->delete();

        return back()->with('status', 'Botón eliminado.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'text' => ['required', 'string', 'max:4000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'project_view_id' => ['nullable', 'integer', 'exists:project_views,id'],
            'expires_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]) + [
            'is_active' => $request->boolean('is_active'),
            'sort_order' => (int) $request->input('sort_order', 0),
            'project_view_id' => $request->input('project_view_id') ?: null,
        ];
    }
}
