<?php

namespace App\Http\Controllers;

use App\Models\Button;
use Illuminate\Http\Request;

class ButtonController extends Controller
{
    public function index()
    {
        $buttons = Button::orderBy('sort_order')->orderBy('id')->get();

        return view('buttons.index', compact('buttons'));
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
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => $request->boolean('is_active'), 'sort_order' => (int) $request->input('sort_order', 0)];
    }
}
