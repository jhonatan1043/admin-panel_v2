<?php

namespace App\Http\Controllers;

use App\Models\Button;
use App\Models\ClientLog;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            'Conversación · '.($company->name ?: $company->registro).' ('.$company->registro.')',
            'Empresa: '.($company->empresa ?: '-'),
            'Exportado: '.now()->format('d/m/Y H:i'),
            str_repeat('-', 48),
        ];

        foreach ($logs as $log) {
            $hora = $log->created_at->format('d/m/Y H:i');
            if ($log->direction === 'in') {
                $lines[] = "[{$hora}] Cliente: {$log->text}";
            } else {
                $estado = match ($log->status) {
                    'sent' => 'OK',
                    'queued' => 'EN COLA',
                    default => 'FALLÓ',
                };
                $lines[] = "[{$hora}] Panel ({$log->label}) [{$estado}]: {$log->text}";
                if ($log->detail && $log->status === 'failed') {
                    $lines[] = "          · {$log->detail}";
                }
            }
        }

        if ($logs->isEmpty()) {
            $lines[] = '(Sin mensajes)';
        }

        $content = implode("\n", $lines)."\n";
        $filename = 'conversacion-'.$company->registro.'.txt';

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Marca como "atendido" el código pendiente del cliente: a partir de aquí, ese
     * valor ya no pone la tarjeta morada ni suena. Solo un código posterior reactiva.
     */
    public function attend(Company $company)
    {
        abort_unless(
            auth()->user()->role === 'admin' || $company->operator_id === auth()->id(),
            403,
            'No tienes este cliente asignado.'
        );

        $maxIn = (int) $company->logs()->where('direction', 'in')->max('id');
        $company->forceFill(['code_attended_id' => $maxIn])->save();

        return response()->json(['ok' => true]);
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
        $buttons = Button::active(); // desde caché

        // Operadores disponibles para asignar (solo lo usa el admin).
        $operators = auth()->user()->role === 'admin'
            ? User::where('role', 'operador')->orderBy('name')->get()
            : collect();

        // Momento de vencimiento del último valor recibido (para la alarma sonora).
        // Al calcularlo, computeAlarmAt marca en los logs cuáles son respuestas "a tiempo"
        // (código, morado): con esos armamos una señal aparte para un sonido distinto.
        $expiryByLabel = $this->expiryByLabel();
        $codeSignal = 0;
        foreach ($companies as $company) {
            $company->alarm_at = $this->computeAlarmAt($company, $expiryByLabel);
            foreach ($company->logs as $log) {
                if (! empty($log->is_timed_reply)) {
                    $codeSignal = max($codeSignal, (int) $log->id);
                }
            }
        }

        // Tablero dividido por empresa; cada grupo mantiene su orden por actividad.
        $companiesByEmpresa = $companies
            ->groupBy(fn (Company $c) => $c->empresa ?: 'Sin empresa')
            ->sortKeys();

        // Señal de "llegó algo nuevo": cambia cuando entra un cliente nuevo o una
        // respuesta entrante de los clientes visibles. El tablero la compara entre
        // refrescos para disparar el sonido de aviso.
        $lastIncoming = (int) ClientLog::whereIn('company_id', $companies->pluck('id'))
            ->where('direction', 'in')->max('id');
        $arrivalSignal = (int) $companies->max('id').'-'.$lastIncoming;
        $soundEnabled = Setting::get('sound_enabled', '1') === '1';

        return compact(
            'companies', 'buttons', 'operators', 'companiesByEmpresa',
            'arrivalSignal', 'codeSignal', 'soundEnabled'
        );
    }

    /** Mapa etiqueta-de-botón => segundos de vencimiento (solo los que vencen). */
    private function expiryByLabel()
    {
        return Button::expiryByLabel(); // desde caché
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
    private function computeAlarmAt(Company $company, $expiryByLabel): ?Carbon
    {
        // Solo los clientes EN LÍNEA tienen cronómetro, morado y alarma.
        // Pendiente / desconectado / borrable: ningún efecto.
        if ($expiryByLabel->isEmpty() || $company->current_status !== 'active') {
            return null;
        }

        $alarmAt = null;
        $pendingSeconds = null; // segundos del botón con cronómetro que espera respuesta
        $pendingViewId = null;  // vista pedida por ese botón (para emparejar por vista)

        // Los logs vienen más recientes primero: se recorren en orden cronológico.
        foreach ($company->logs->reverse() as $log) {
            // Valor del cliente: mensaje entrante de Telegram, el envío de una vista del
            // proyecto, o el código de send-test-code (queda como "Código de prueba").
            $esValor = $log->direction === 'in' || $log->label === ClientLog::TEST_CODE_LABEL;

            if (! $esValor) {
                $pendingSeconds = isset($expiryByLabel[$log->label])
                    ? (int) $expiryByLabel[$log->label]
                    : null;
                $pendingViewId = $pendingSeconds !== null ? $log->project_view_id : null;
                if ($pendingSeconds !== null) {
                    $alarmAt = null; // nueva opción con cronómetro: espera su propio valor
                }

                continue;
            }

            if ($pendingSeconds !== null) {
                // Empareja por VISTA cuando el botón pidió una vista del proyecto (el valor
                // ya no tiene que ser numérico); si no hay vista, vale el valor numérico
                // (flujo de Telegram de siempre).
                $empareja = $pendingViewId !== null
                    ? (int) $log->project_view_id === (int) $pendingViewId
                    : $this->isNumeric($log->text);

                // Si este valor ya fue marcado como "atendido", no vuelve a poner morado
                // ni a sonar; solo un código posterior (id mayor) reactiva la alarma.
                $yaAtendido = (int) $log->id <= (int) $company->code_attended_id;

                if ($empareja && ! $yaAtendido) {
                    $log->is_timed_reply = true;
                    $alarmAt = $log->created_at->copy()->addSeconds($pendingSeconds);
                }
            }
            $pendingSeconds = null; // solo cuenta el mensaje inmediatamente siguiente
            $pendingViewId = null;
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
        return view('companies.create', ['company' => new Company]);
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
        // Solo el administrador elimina registros; el operador no borra nada.
        abort_unless(auth()->user()->role === 'admin', 403, 'Solo el administrador puede eliminar registros.');

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
                'unique:companies,registro'.($company ? ','.$company->id : ''),
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
