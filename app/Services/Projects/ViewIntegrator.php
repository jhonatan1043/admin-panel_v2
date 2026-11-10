<?php

namespace App\Services\Projects;

use App\Models\ProjectView;
use App\Models\Setting;

/**
 * Orquesta el análisis de una vista y la generación de su integración:
 * analiza el HTML, construye la especificación y genera integration.js,
 * guardándolo todo en la vista. No modifica el HTML/CSS/JS original.
 */
class ViewIntegrator
{
    public function __construct(
        private HtmlAnalyzer $analyzer,
        private IntegrationSpecBuilder $specBuilder,
        private IntegrationJsGenerator $jsGenerator,
        private LoadingJsGenerator $loadingJsGenerator,
    ) {}

    /**
     * Analiza y regenera la integración de la vista.
     *
     * @param  array|null  $specOverride  Especificación ajustada manualmente (opcional).
     */
    public function integrate(ProjectView $view, ?array $specOverride = null): ProjectView
    {
        // La pantalla de carga no recopila datos: solo necesita su script de espera.
        if ($view->isLoading()) {
            return $this->integrateLoading($view);
        }

        // Se pasa también el JS propio para avisar si trae su propio envío/red.
        $analysis = $this->analyzer->analyze((string) $view->html, $view->js);

        $spec = $specOverride ?? $this->specBuilder->fromAnalysis($analysis, $this->endpointFor($view));
        // La URL de envío siempre la fija el servidor, aunque llegue una spec manual.
        $spec['endpoint'] = $this->endpointFor($view);

        $view->analysis = $analysis;
        $view->spec = $spec;
        $view->integration_js = $this->jsGenerator->generate($spec);
        $view->save();

        return $view;
    }

    /** Genera el script de espera para la vista de carga. */
    private function integrateLoading(ProjectView $view): ProjectView
    {
        $projectSlug = $this->projectSlug($view);

        $view->analysis = ['summary' => ['forms' => 0, 'fields' => 0, 'buttons' => 0], 'role' => 'loading'];
        $view->spec = ['role' => 'loading', 'project' => $projectSlug, 'api_base' => self::apiBase()];
        $view->integration_js = $this->loadingJsGenerator->generate([
            'project' => $projectSlug,
            'api_base' => self::apiBase(),
        ]);
        $view->save();

        return $view;
    }

    /**
     * Endpoint ABSOLUTO al que integration.js envía los datos. Es absoluto porque el
     * JS se ejecuta en páginas alojadas en OTRO dominio (el hosting del cliente).
     */
    public function endpointFor(ProjectView $view): string
    {
        return self::apiBase()."/api/sitio/{$this->projectSlug($view)}/{$view->slug}/submit";
    }

    /** Dominio público del panel (donde vive la API). Configurable; por defecto APP_URL. */
    public static function apiBase(): string
    {
        $base = Setting::get('public_api_url') ?: config('app.url');

        return rtrim((string) $base, '/');
    }

    private function projectSlug(ProjectView $view): ?string
    {
        return $view->project?->slug ?? $view->project()->first()?->slug;
    }
}
