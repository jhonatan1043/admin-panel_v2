<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\MessageTemplate;
use Illuminate\Http\Request;

class MessageTemplateController extends Controller
{
    public function store(Request $request, Company $company)
    {
        $data = $this->validateData($request);
        $company->messageTemplates()->create($data);

        return back()->with('status', 'Botón agregado.');
    }

    public function update(Request $request, Company $company, MessageTemplate $template)
    {
        abort_if($template->company_id !== $company->id, 404);

        $template->update($this->validateData($request));

        return back()->with('status', 'Botón actualizado.');
    }

    public function destroy(Company $company, MessageTemplate $template)
    {
        abort_if($template->company_id !== $company->id, 404);

        $template->delete();

        return back()->with('status', 'Botón eliminado.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:4000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => $request->boolean('is_active')];
    }
}
