<?php

namespace App\Services\Projects;

/**
 * A partir del análisis de una vista construye la "especificación de integración":
 * qué disparadores (botones/envíos) existen, qué campos recoge cada uno y cómo se
 * arma el dato que se enviará. Es la configuración automática; la administración
 * puede sobrescribir cualquier valor antes de generar el JavaScript.
 */
class IntegrationSpecBuilder
{
    /**
     * @param  array  $analysis  Resultado de HtmlAnalyzer::analyze()
     * @param  string  $endpoint  URL a la que integration.js enviará los datos
     */
    public function fromAnalysis(array $analysis, string $endpoint): array
    {
        $targets = [];

        foreach ($analysis['forms'] ?? [] as $i => $form) {
            $fields = $this->mapFields($form['fields'] ?? []);
            if ($fields === []) {
                continue;
            }

            $targets[] = [
                'key' => $form['id'] ?: $form['name'] ?: 'form_'.($i + 1),
                'label' => $form['id'] ?: $form['name'] ?: 'Formulario '.($i + 1),
                // En un <form> el disparador natural es su evento submit.
                'trigger' => ['type' => 'submit', 'selector' => $form['selector']],
                'mode' => 'map',
                'fields' => $fields,
            ];
        }

        $standaloneFields = $this->mapFields($analysis['standalone']['fields'] ?? []);
        $standaloneButtons = $analysis['standalone']['buttons'] ?? [];

        if ($standaloneFields !== []) {
            $trigger = $standaloneButtons !== []
                ? ['type' => 'click', 'selector' => $standaloneButtons[0]['selector']]
                : null;

            $targets[] = [
                'key' => 'standalone',
                'label' => count($standaloneFields) === 1
                    ? 'Campo: '.$standaloneFields[0]['send_as']
                    : 'Campos sueltos',
                'trigger' => $trigger,
                // Un único campo suelto envía solo su valor; varios, un objeto.
                'mode' => count($standaloneFields) === 1 ? 'single' : 'map',
                'fields' => $standaloneFields,
            ];
        }

        return [
            'endpoint' => $endpoint,
            'targets' => $targets,
            'on_success' => [
                'message' => 'Información enviada correctamente.',
                'redirect' => null,
            ],
        ];
    }

    private function mapFields(array $fields): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            if (($field['include'] ?? true) === false) {
                continue;
            }
            $entry = [
                'selector' => $field['selector'],
                'send_as' => $field['send_as'] ?? $field['key'],
                'type' => $field['type'] ?? 'text',
                'required' => (bool) ($field['required'] ?? false),
            ];
            // Campo agrupado (cajas de un código): se concatenan todas sus cajas.
            if (! empty($field['group'])) {
                $entry['group'] = true;
                $entry['selectors'] = $field['selectors'] ?? [$field['selector']];
            }
            $mapped[] = $entry;
        }

        return $mapped;
    }
}
