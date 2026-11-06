<?php

namespace App\Services\Projects;

use App\Models\ProjectView;

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

        $analysis = $this->analyzer->analyze((string) $view->html);

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
        $view->spec = ['role' => 'loading', 'project' => $projectSlug];
        $view->integration_js = $this->loadingJsGenerator->generate(['project' => $projectSlug]);
        $view->save();

        return $view;
    }

    /** Endpoint público al que integration.js envía los datos capturados. */
    public function endpointFor(ProjectView $view): string
    {
        return "/api/sitio/{$this->projectSlug($view)}/{$view->slug}/submit";
    }

    private function projectSlug(ProjectView $view): ?string
    {
        return $view->project?->slug ?? $view->project()->first()?->slug;
    }
}
