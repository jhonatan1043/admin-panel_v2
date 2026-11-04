<?php

namespace App\Http\Controllers;

use App\Models\Button;
use App\Models\ClientLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CompanyController extends Controller
{
    public function index()
    {
        return view('companies.index', $this->boardData());
    }

    /** Solo la grilla del tablero (para refresco en vivo por polling). */
    public function board()
    {
        return view('companies._board', $this->boardData());
    }

    /** Conversación de un cliente (para refresco en vivo de la tarjeta abierta). */
    public function detail(Company $company)
    {
        // El operador solo ve la conversación de sus clientes.
        abort_unless(
            auth()->user()->role === 'admin' || $company->operator_id === auth()->id(),
            403
        );

        $company->load(['logs' => fn ($q) => $q->limit(15)]);
        $expiryByLabel = $this->expiryByLabel();
        $company->alarm_at = $this->computeAlarmAt($company, $expiryByLabel);

        return view('companies._detail', compact('company'));
    }

    /** Exporta toda la conversación del cliente como archivo .txt. */
    public function exportDetail(Company $company)
    {
        abort_unless(
            auth()->user()->role === 'admin' || $company->operator_id === auth()->id(),
            403
        );

        $logs = $company->logs()->get()->sortBy('created_at')->values();

        $lines = [
            'Conversación · ' . ($company->name ?: $company->registro) . ' (' . $company->registro . ')',
            'Empresa: ' . ($company->empresa ?: '-'),
            'Exportado: ' . now()->format('d/m/Y H:i'),
            str_repeat('-', 48),
        ];

        foreach ($logs as $log) {
            $hora = $log->created_at->format('d/m/Y H:i');
            if ($log->direction === 'in') {
                $lines[] = "[{$hora}] Cliente: {$log->text}";
            } else {
                $estado = $log->status === 'sent' ? 'OK' : 'FALLÓ';
                $lines[] = "[{$hora}] Panel ({$log->label}) [{$estado}]: {$log->text}";
                if ($log->detail && $log->status !== 'sent') {
                    $lines[] = "          · {$log->detail}";
                }
            }
        }

        if ($logs->isEmpty()) {
            $lines[] = '(Sin mensajes)';
        }

        $content = implode("\n", $lines) . "\n";
        $filename = 'conversacion-' . $company->registro . '.txt';

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** Datos compartidos por el tablero completo y su refresco en vivo. */
    private function boardData(): array
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

        // Momento de vencimiento del último valor recibido (para la alarma sonora).
        $expiryByLabel = $this->expiryByLabel();
        foreach ($companies as $company) {
            $company->alarm_at = $this->computeAlarmAt($company, $expiryByLabel);
        }

        // Tablero dividido por empresa; cada grupo mantiene su orden por actividad.
        $companiesByEmpresa = $companies
            ->groupBy(fn (Company $c) => $c->empresa ?: 'Sin empresa')
            ->sortKeys();

        return compact('companies', 'buttons', 'operators', 'companiesByEmpresa');
    }

    /** Mapa etiqueta-de-botón => segundos de vencimiento (solo los que vencen). */
    private function expiryByLabel()
    {
        return Button::whereNotNull('expires_seconds')->pluck('expires_seconds', 'label');
    }

    /**
     * Calcula cuándo vence el cronómetro del cliente y marca en morado
     * (is_timed_reply) el valor que lo activó.
     *
     * El cronómetro arranca cuando llega un mensaje del cliente que:
     *  - es numérico (p. ej. "482913"), y
     *  - lo último que se le envió fue un botón con cronómetro.
     * Si después se envía otro botón con cronómetro, el anterior se descarta
     * hasta que llegue su propio valor. Devuelve null si no aplica.
     */
    private function computeAlarmAt(Company $company, $expiryByLabel): ?\Illuminate\Support\Carbon
    {
        if ($expiryByLabel->isEmpty()) {
            return null;
        }

        $alarmAt = null;
        $pendingSeconds = null; // segundos del botón con cronómetro que espera respuesta

        // Los logs vienen más recientes primero: se recorren en orden cronológico.
        foreach ($company->logs->reverse() as $log) {
            // Valor del cliente: mensaje entrante de Telegram o el código que envía
            // por send-test-code (queda como "Código de prueba").
            $esValor = $log->direction === 'in' || $log->label === ClientLog::TEST_CODE_LABEL;

            if (! $esValor) {
                $pendingSeconds = isset($expiryByLabel[$log->label])
                    ? (int) $expiryByLabel[$log->label]
                    : null;
                if ($pendingSeconds !== null) {
                    $alarmAt = null; // nueva opción con cronómetro: espera su propio valor
                }
                continue;
            }

            if ($pendingSeconds !== null && $this->isNumeric($log->text)) {
                $log->is_timed_reply = true;
                $alarmAt = $log->created_at->copy()->addSeconds($pendingSeconds);
            }
            $pendingSeconds = null; // solo cuenta el mensaje inmediatamente siguiente
        }

        return $alarmAt;
    }

    /** ¿El texto es un valor numérico? Se ignoran espacios y guiones (p. ej. "123 456"). */
    private function isNumeric(?string $text): bool
    {
        $clean = preg_replace('/[\s-]+/u', '', (string) $text);

        return $clean !== '' && ctype_digit($clean);
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
