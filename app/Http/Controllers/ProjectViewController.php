<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectView;
use App\Services\Projects\ViewCodeSanitizer;
use App\Services\Projects\ViewIntegrator;
use App\Services\Projects\ViewUploadAssembler;
use Illuminate\Http\Request;

class ProjectViewController extends Controller
{
    public function __construct(
        private ViewIntegrator $integrator,
        private ViewUploadAssembler $assembler,
    ) {}

    /**
     * Corrige automáticamente el código de la vista que choca con la integración:
     * desactiva su propio `submit` y quita `onsubmit`. Luego reanaliza.
     */
    public function fix(Project $project, ProjectView $view, ViewCodeSanitizer $sanitizer)
    {
        abort_unless($project->managedBy(auth()->user()), 403);
        abort_unless($view->project_id === $project->id, 404);

        $result = $sanitizer->sanitize((string) $view->html, $view->js);

        if ($result['changes'] === []) {
            return back()->with('status', 'No se encontró código propio de envío que corregir.');
        }

        $view->html = $result['html'];
        $view->js = $result['js'];
        $view->save();
        $this->integrator->integrate($view);

        return back()->with('status', 'Código corregido: '.implode(' ', $result['changes']));
    }

    /**
     * Crea una vista a partir de archivos cargados o de texto pegado.
     * El HTML se conserva tal cual; el análisis y la integración se generan aparte.
     */
    public function store(Request $request, Project $project)
    {
        abort_unless($project->managedBy(auth()->user()), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'html' => ['nullable', 'string'],
            'html_file' => ['nullable', 'file', 'mimetypes:text/html,text/plain', 'max:5120'],
            'css' => ['nullable', 'string'],
            'css_file' => ['nullable', 'file', 'max:5120'],
            'js' => ['nullable', 'string'],
            'js_file' => ['nullable', 'file', 'max:5120'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:4096'],
        ]);

        $html = $this->contentFrom($request, 'html');
        $css = $this->contentFrom($request, 'css');
        $js = $this->contentFrom($request, 'js');

        // Carga de varios archivos a la vez (HTML, varios CSS/JS e imágenes). Las
        // imágenes quedan incrustadas como data: base64 (todo dinámico en la base).
        if ($request->hasFile('files')) {
            [$html, $css, $js] = $this->assembler->assemble($request->file('files'), $html, $css, $js);
        }

        if (trim((string) $html) === '') {
            return back()->withInput()->withErrors(['html' => 'Debes cargar o pegar el HTML de la vista.']);
        }

        // Si se sube una página completa, se separan su CSS (<style>) y JS (<script>).
        [$html, $css, $js] = $this->splitContent($html, $css, $js);

        $view = $project->views()->create([
            'name' => $data['name'],
            'html' => $html,
            'css' => $css,
            'js' => $js,
        ]);

        // Análisis + integración automáticos al crear la vista.
        $this->integrator->integrate($view);

        return redirect()
            ->route('projects.views.show', [$project, $view])
            ->with('status', 'Vista cargada y analizada.');
    }

    /**
     * Crea o reemplaza la pantalla de carga del proyecto (una por proyecto).
     * Solo HTML y CSS: el JavaScript de espera lo genera el sistema.
     */
    public function storeLoading(Request $request, Project $project)
    {
        abort_unless($project->managedBy(auth()->user()), 403);

        $request->validate([
            'html' => ['nullable', 'string'],
            'html_file' => ['nullable', 'file', 'mimetypes:text/html,text/plain', 'max:5120'],
            'css' => ['nullable', 'string'],
            'css_file' => ['nullable', 'file', 'max:5120'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:4096'],
        ]);

        $html = $this->contentFrom($request, 'html');
        $css = $this->contentFrom($request, 'css');

        // La pantalla de carga también admite varios archivos (HTML, CSS e imágenes).
        // El JS de espera lo genera el sistema, así que no se toma JS del cliente.
        if ($request->hasFile('files')) {
            [$html, $css] = $this->assembler->assemble($request->file('files'), $html, $css, null);
        }

        $loading = $project->loadingView();

        if ($html !== null && trim($html) === '' && ! $loading) {
            return back()->withErrors(['html' => 'Debes cargar o pegar el HTML de la pantalla de carga.']);
        }

        if ($loading) {
            if ($html !== null && trim($html) !== '') {
                $loading->html = $html;
            }
            if ($css !== null) {
                $loading->css = $css;
            }
            $loading->save();
        } else {
            $loading = $project->views()->create([
                'name' => 'Pantalla de carga',
                'role' => ProjectView::ROLE_LOADING,
                'slug' => 'cargando',
                'html' => (string) $html,
                'css' => $css,
            ]);
        }

        $this->integrator->integrate($loading);

        return redirect()->route('projects.show', $project)->with('status', 'Pantalla de carga guardada.');
    }

    public function show(Project $project, ProjectView $view, ViewCodeSanitizer $sanitizer)
    {
        abort_unless($project->managedBy(auth()->user()), 403);
        abort_unless($view->project_id === $project->id, 404);

        // ¿Hay código propio (submit/onsubmit/funciones ausentes) que se pueda corregir?
        $canAutoFix = ! $view->isLoading() && $sanitizer->needsFix((string) $view->html, $view->js);

        // Archivos referenciados (link/img/script/url) que faltan por subir.
        $missingAssets = $this->assembler->missing($view->html, $view->css);

        return view('projects.views.show', compact('project', 'view', 'canAutoFix', 'missingAssets'));
    }

    /**
     * Sube los archivos que faltaban (imágenes, fuentes) y los incrusta en la vista,
     * uno o varios a la vez. Reduce la lista de faltantes hasta completarla.
     */
    public function uploadAssets(Request $request, Project $project, ProjectView $view)
    {
        abort_unless($project->managedBy(auth()->user()), 403);
        abort_unless($view->project_id === $project->id, 404);

        $request->validate([
            'files' => ['required', 'array'],
            'files.*' => ['file', 'max:4096'],
        ]);

        [$html, $css, $js] = $this->assembler->addMissingFiles((string) $view->html, $view->css, $view->js, $request->file('files'));
        $view->html = $html;
        $view->css = $css;
        $view->js = $js;
        $view->save();
        $this->integrator->integrate($view);

        $faltan = $this->assembler->missing($view->html, $view->css);
        $msg = $faltan === []
            ? 'Archivos agregados. Ya no falta ninguno.'
            : 'Archivos agregados. Faltan: '.implode(', ', $faltan).'.';

        return back()->with('status', $msg);
    }

    public function update(Request $request, Project $project, ProjectView $view)
    {
        abort_unless($project->managedBy(auth()->user()), 403);
        abort_unless($view->project_id === $project->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'html' => ['nullable', 'string'],
            'html_file' => ['nullable', 'file', 'mimetypes:text/html,text/plain', 'max:5120'],
            'css' => ['nullable', 'string'],
            'css_file' => ['nullable', 'file', 'max:5120'],
            'js' => ['nullable', 'string'],
            'js_file' => ['nullable', 'file', 'max:5120'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:4096'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'sends_data' => ['nullable', 'boolean'],
        ]);

        $view->name = $data['name'];
        // Orden en la secuencia y si la vista envía información (o es solo de paso).
        $view->sort_order = (int) $request->input('sort_order', $view->sort_order);
        $view->sends_data = $request->boolean('sends_data');
        $html = $this->contentFrom($request, 'html');
        $css = $this->contentFrom($request, 'css');
        $js = $this->contentFrom($request, 'js');

        if ($request->hasFile('files')) {
            [$html, $css, $js] = $this->assembler->assemble($request->file('files'), $html, $css, $js);
        }

        // Si llega HTML completo, se vuelven a separar sus <style>/<script> en línea.
        [$html, $css, $js] = $this->splitContent($html, $css, $js);

        if (trim((string) $html) !== '') {
            $view->html = $html;
        }
        if ($css !== null) {
            $view->css = $css;
        }
        if ($js !== null) {
            $view->js = $js;
        }
        $view->save();

        // Al cambiar los archivos se vuelve a analizar y regenerar la integración.
        $this->integrator->integrate($view);

        return redirect()
            ->route('projects.views.show', [$project, $view])
            ->with('status', 'Vista actualizada y reanalizada.');
    }

    /** Marca esta vista como la principal (index) del proyecto. */
    public function makePrimary(Project $project, ProjectView $view)
    {
        abort_unless($project->managedBy(auth()->user()), 403);
        abort_unless($view->project_id === $project->id, 404);
        abort_if($view->isLoading(), 422, 'La pantalla de carga no puede ser la vista principal.');

        $project->forceFill(['primary_view_id' => $view->id])->save();

        return back()->with('status', "«{$view->name}» es ahora la vista principal.");
    }

    /** Vuelve a analizar la vista y regenera integration.js. */
    public function analyze(Project $project, ProjectView $view)
    {
        abort_unless($project->managedBy(auth()->user()), 403);
        abort_unless($view->project_id === $project->id, 404);

        $this->integrator->integrate($view);

        return back()->with('status', 'Vista reanalizada.');
    }

    /** Guarda los ajustes manuales de la especificación y regenera la integración. */
    public function updateSpec(Request $request, Project $project, ProjectView $view)
    {
        abort_unless($project->managedBy(auth()->user()), 403);
        abort_unless($view->project_id === $project->id, 404);

        $data = $request->validate([
            'spec' => ['required', 'json'],
        ]);

        $this->integrator->integrate($view, json_decode($data['spec'], true));

        return back()->with('status', 'Configuración de integración guardada.');
    }

    public function destroy(Project $project, ProjectView $view)
    {
        abort_unless($project->managedBy(auth()->user()), 403);
        abort_unless($view->project_id === $project->id, 404);
        $view->delete();

        return redirect()->route('projects.show', $project)->with('status', 'Vista eliminada.');
    }

    /** Devuelve el integration.js generado como archivo descargable. */
    public function integrationJs(Project $project, ProjectView $view)
    {
        abort_unless($project->managedBy(auth()->user()), 403);
        abort_unless($view->project_id === $project->id, 404);

        return response((string) $view->integration_js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
        ]);
    }

    /**
     * Toma el contenido del campo de texto o, si se cargó archivo, del archivo.
     * Devuelve null cuando no se envió ninguno (para no sobrescribir en update).
     */
    private function contentFrom(Request $request, string $field): ?string
    {
        if ($request->hasFile($field.'_file')) {
            return (string) file_get_contents($request->file($field.'_file')->getRealPath());
        }

        $value = $request->input($field);

        return $value === null ? null : (string) $value;
    }

    /**
     * Si se subió una página HTML completa, separa su contenido: extrae el CSS de las
     * etiquetas <style> y el JS de las etiquetas <script> en línea (sin src), y los
     * guarda aparte. Así se almacena el HTML, el CSS y el JavaScript por separado.
     *
     * @return array{0:?string,1:?string,2:?string} [html, css, js]
     */
    private function splitContent(?string $html, ?string $css, ?string $js): array
    {
        if ($html === null || trim($html) === '') {
            return [$html, $css, $js];
        }

        if ($css === null || trim((string) $css) === '') {
            if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $m) && $m[1] !== []) {
                $css = trim(implode("\n", array_map('trim', $m[1])));
                $html = (string) preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
            }
        }

        if ($js === null || trim((string) $js) === '') {
            // Solo <script> en línea (no los que tienen src de librerías externas).
            if (preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*>(.*?)<\/script>/is', $html, $m) && $m[1] !== []) {
                $js = trim(implode("\n", array_map('trim', $m[1])));
                $html = (string) preg_replace('/<script\b(?![^>]*\bsrc=)[^>]*>.*?<\/script>/is', '', $html);
            }
        }

        return [trim($html), $css, $js];
    }
}
