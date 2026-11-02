<?php

namespace App\Http\Controllers;

use App\Models\Button;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CompanyController extends Controller
{
    public function index()
    {
        $companies = Company::with(['operator', 'logs' => fn ($q) => $q->limit(15)])
            // El operador solo ve los clientes asignados a su id; el admin ve todos.
            ->when(auth()->user()->role !== 'admin', fn ($q) => $q->where('operator_id', auth()->id()))
            // Actividad más reciente primero (más "verde" arriba); el estado se calcula por tiempo.
            ->orderByDesc('last_activity_at')
            ->get();

        // Catálogo global de botones, igual para todos los clientes.
        $buttons = Button::activeOrdered()->get();

        // Operadores disponibles para asignar (solo lo usa el admin).
        $operators = auth()->user()->role === 'admin'
            ? User::where('role', 'operador')->orderBy('name')->get()
            : collect();

        // Tablero dividido por empresa; cada grupo mantiene su orden por actividad.
        $companiesByEmpresa = $companies
            ->groupBy(fn (Company $c) => $c->empresa ?: 'Sin empresa')
            ->sortKeys();

        return view('companies.index', compact('companies', 'buttons', 'operators', 'companiesByEmpresa'));
    }

    /** Asigna (o quita) el operador de un cliente. Solo admin. */
    public function assign(Request $request, Company $company)
    {
        $data = $request->validate([
            'operator_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        // Solo se permite asignar a un operador, o al propio admin, o dejar sin asignar.
        if (! empty($data['operator_id'])) {
            $target = User::find($data['operator_id']);
            abort_unless($target && ($target->role === 'operador' || $target->id === auth()->id()), 422, 'Operador inválido.');
        }

        $company->update(['operator_id' => $data['operator_id'] ?: null]);

        return back()->with('status', 'Operador asignado.');
    }

    public function create()
    {
        return view('companies.create', ['company' => new Company()]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['loading_image_path'] = $this->storeImage($request);

        Company::create($data);

        return redirect()->route('companies.index')
            ->with('status', 'Registro creado.');
    }

    public function edit(Company $company)
    {
        return view('companies.edit', compact('company'));
    }

    public function update(Request $request, Company $company)
    {
        $data = $this->validateData($request, $company);

        if ($path = $this->storeImage($request)) {
            if ($company->loading_image_path) {
                Storage::disk('public')->delete($company->loading_image_path);
            }
            $data['loading_image_path'] = $path;
        }

        $company->update($data);

        return redirect()->route('companies.index')
            ->with('status', 'Registro actualizado.');
    }

    public function destroy(Company $company)
    {
        if ($company->loading_image_path) {
            Storage::disk('public')->delete($company->loading_image_path);
        }
        $company->delete();

        return redirect()->route('companies.index')
            ->with('status', 'Registro eliminado.');
    }

    private function validateData(Request $request, ?Company $company = null): array
    {
        return $request->validate([
            'registro' => [
                'required', 'string', 'max:255',
                'unique:companies,registro' . ($company ? ',' . $company->id : ''),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'empresa' => ['nullable', 'string', 'max:255'],
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,pending,offline,deletable'],
            'loading_image' => ['nullable', 'image', 'max:4096'],
        ]);
    }

    private function storeImage(Request $request): ?string
    {
        if (! $request->hasFile('loading_image')) {
            return null;
        }

        return $request->file('loading_image')->store('loading-images', 'public');
    }
}
