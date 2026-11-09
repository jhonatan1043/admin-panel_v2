<?php

namespace App\Services\Projects;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Analiza una vista HTML (tal cual la entrega la empresa, sin modificarla) e
 * identifica qué información puede recopilar: formularios, campos sueltos y
 * botones. El resultado alimenta la configuración y el generador de integración.
 *
 * No toca el HTML original: solo lo lee.
 */
class HtmlAnalyzer
{
    /** Tipos de <input> que son acciones (botones), no campos de datos. */
    private const BUTTON_INPUT_TYPES = ['submit', 'button', 'reset', 'image'];

    /** Tipos de <input> que nunca se envían como dato recopilado. */
    private const IGNORED_INPUT_TYPES = ['reset', 'image'];

    /**
     * Devuelve la estructura detectada:
     * [
     *   'forms' => [ ['id','name','selector','action','method','fields'=>[...],'submit'=>[...]|null], ... ],
     *   'standalone' => ['fields'=>[...], 'buttons'=>[...]],
     *   'summary' => ['forms','fields','buttons'],
     *   'warnings' => [...],
     * ]
     */
    public function analyze(string $html, ?string $js = null): array
    {
        $warnings = $this->conflictWarnings($html, $js);

        if (trim($html) === '') {
            return $this->empty(array_merge(['La vista está vacía.'], $warnings));
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // Forzar UTF-8 y evitar que DOMDocument envuelva en <html> rompa los selectores.
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8"?>'.$html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return $this->empty(['No se pudo interpretar el HTML de la vista.']);
        }

        $xpath = new DOMXPath($dom);

        $forms = [];
        foreach ($xpath->query('//form') as $formNode) {
            /** @var DOMElement $formNode */
            $forms[] = $this->describeForm($formNode, $xpath);
        }

        // Campos y botones que NO están dentro de ningún <form>.
        $standaloneFields = [];
        foreach ($this->fieldNodes($xpath, '//input|//textarea|//select') as $node) {
            if ($this->ancestorForm($node)) {
                continue;
            }
            $field = $this->describeField($node);
            if ($field !== null) {
                $standaloneFields[] = $field;
            }
        }
        // Agrupa cajas de un mismo código (OTP) sueltas en un solo campo concatenado.
        $standaloneFields = $this->groupCodeFields($standaloneFields);

        $standaloneButtons = [];
        foreach ($this->buttonNodes($xpath) as $node) {
            if ($this->ancestorForm($node)) {
                continue;
            }
            $standaloneButtons[] = $this->describeButton($node);
        }

        if ($forms === [] && $standaloneFields === [] && $standaloneButtons === []) {
            $warnings[] = 'No se detectaron formularios, campos ni botones en la vista.';
        }
        if ($standaloneFields !== [] && $standaloneButtons === []) {
            $warnings[] = 'Hay campos sueltos sin un botón de envío asociado; podrás elegir el disparador manualmente.';
        }

        $fieldCount = count($standaloneFields);
        foreach ($forms as $form) {
            $fieldCount += count($form['fields']);
        }
        $buttonCount = count($standaloneButtons);
        foreach ($forms as $form) {
            $buttonCount += $form['submit'] !== null ? 1 : 0;
        }

        return [
            'forms' => $forms,
            'standalone' => [
                'fields' => $standaloneFields,
                'buttons' => $standaloneButtons,
            ],
            'summary' => [
                'forms' => count($forms),
                'fields' => $fieldCount,
                'buttons' => $buttonCount,
            ],
            'warnings' => $warnings,
        ];
    }

    private function describeForm(DOMElement $form, DOMXPath $xpath): array
    {
        $id = $this->attr($form, 'id');
        $name = $this->attr($form, 'name');

        $fields = [];
        foreach ($this->fieldNodes($xpath, './/input|.//textarea|.//select', $form) as $node) {
            // Un campo pertenece al <form> más cercano: evita robar campos de forms anidados (HTML inválido).
            if ($this->ancestorForm($node) !== $form) {
                continue;
            }
            $field = $this->describeField($node);
            if ($field !== null) {
                $fields[] = $field;
            }
        }
        // Agrupa cajas de un mismo código (OTP) en un solo campo concatenado.
        $fields = $this->groupCodeFields($fields);

        $submit = null;
        foreach ($this->buttonNodes($xpath, $form) as $node) {
            if ($this->ancestorForm($node) !== $form) {
                continue;
            }
            $button = $this->describeButton($node);
            // El botón de envío preferente es el primero de tipo submit; si no, el primer botón.
            if ($submit === null || $button['is_submit']) {
                $submit = $button;
                if ($button['is_submit']) {
                    break;
                }
            }
        }

        return [
            'id' => $id,
            'name' => $name,
            'selector' => $this->nodeSelector($form, 'form'),
            'action' => $this->attr($form, 'action'),
            'method' => strtolower($this->attr($form, 'method') ?: 'get'),
            'fields' => $fields,
            'submit' => $submit,
        ];
    }

    private function describeField(DOMElement $node): ?array
    {
        $tag = strtolower($node->nodeName);
        $type = $tag === 'input' ? strtolower($this->attr($node, 'type') ?: 'text') : $tag;

        if ($tag === 'input' && in_array($type, self::BUTTON_INPUT_TYPES, true)) {
            return null; // es un botón, no un dato
        }
        if ($tag === 'input' && in_array($type, self::IGNORED_INPUT_TYPES, true)) {
            return null;
        }

        $name = $this->attr($node, 'name');
        $id = $this->attr($node, 'id');

        // Clave con la que viajará el dato: name tiene prioridad; si no, el id.
        $key = $name !== null && $name !== '' ? $name : $id;
        if (($key === null || $key === '') && $type !== 'hidden') {
            // Sin name ni id no hay forma estable de capturarlo; se avisa pero se omite.
            return null;
        }
        if ($key === null || $key === '') {
            return null;
        }

        return [
            'tag' => $tag,
            'type' => $type,
            'name' => $name,
            'id' => $id,
            'key' => $key,
            'required' => $node->hasAttribute('required'),
            'hidden' => $type === 'hidden',
            'maxlength' => $this->attr($node, 'maxlength'),
            'placeholder' => $this->attr($node, 'placeholder'),
            'selector' => $this->fieldSelector($node, $name, $id),
            // Config por defecto (la UI puede ajustarla): se envía y conserva su clave.
            'include' => true,
            'send_as' => $key,
            'group' => false,
        ];
    }

    /**
     * Colapsa las cajas de un mismo código (p. ej. 6 inputs de 1 dígito) en un solo
     * campo cuyo valor es la concatenación de todas. Detecta dos patrones:
     *  - varios inputs que comparten el mismo `name`;
     *  - varias cajas de un carácter (maxlength="1").
     *
     * @param  array  $fields  Campos ya detectados en este ámbito (formulario o sueltos).
     */
    private function groupCodeFields(array $fields): array
    {
        if (count($fields) < 2) {
            return $fields;
        }

        $nameCounts = [];
        foreach ($fields as $f) {
            if (! empty($f['name'])) {
                $nameCounts[$f['name']] = ($nameCounts[$f['name']] ?? 0) + 1;
            }
        }

        $order = [];      // groupKey => posición en el resultado
        $members = [];    // groupKey => campos del grupo
        $result = [];

        foreach ($fields as $f) {
            if (! empty($f['hidden'])) { // los ocultos no se agrupan
                $result[] = $f;

                continue;
            }

            $groupKey = null;
            if (! empty($f['name']) && ($nameCounts[$f['name']] ?? 0) >= 2) {
                $groupKey = 'name:'.$f['name'];
            } elseif (($f['maxlength'] ?? null) === '1') {
                $groupKey = 'digits';
            }

            if ($groupKey === null) {
                $result[] = $f;

                continue;
            }

            if (! isset($order[$groupKey])) {
                $order[$groupKey] = count($result);
                $result[] = null; // reservar el lugar del grupo
                $members[$groupKey] = [];
            }
            $members[$groupKey][] = $f;
        }

        foreach ($members as $key => $groupFields) {
            // Un solo miembro no forma grupo: se deja como campo normal.
            $result[$order[$key]] = count($groupFields) < 2
                ? $groupFields[0]
                : $this->collapseGroup($groupFields);
        }

        return array_values(array_filter($result, fn ($x) => $x !== null));
    }

    private function collapseGroup(array $members): array
    {
        $first = $members[0];

        $names = array_map(fn ($m) => $m['name'] ?? null, $members);
        $allSameName = $first['name'] && count(array_unique($names)) === 1;

        if ($allSameName) {
            $sendAs = $first['name'];
        } else {
            // Clave a partir de la primera caja, quitando el número final (otp1 -> otp).
            $sendAs = preg_replace('/[\s_\-]*\d+$/', '', (string) $first['key']);
            if (strlen((string) $sendAs) < 2) {
                $sendAs = 'codigo';
            }
        }

        // Selectores de las cajas. Si todas comparten selector (mismo name), uno solo
        // los agarra a todas; si no, se listan los individuales (por id o name).
        $selectors = array_values(array_unique(array_map(fn ($m) => $m['selector'], $members)));

        return [
            'tag' => $first['tag'],
            'type' => $first['type'],
            'name' => $allSameName ? $first['name'] : null,
            'id' => null,
            'key' => $sendAs,
            'required' => (bool) ($first['required'] ?? false),
            'hidden' => false,
            'maxlength' => $first['maxlength'] ?? null,
            'placeholder' => null,
            'selector' => $selectors[0],
            'selectors' => $selectors,
            'count' => count($members),
            'group' => true,
            'include' => true,
            'send_as' => $sendAs,
        ];
    }

    private function describeButton(DOMElement $node): array
    {
        $tag = strtolower($node->nodeName);
        $type = strtolower($this->attr($node, 'type') ?: ($tag === 'button' ? 'submit' : ''));
        $text = $tag === 'button'
            ? trim($node->textContent)
            : (string) $this->attr($node, 'value');

        return [
            'id' => $this->attr($node, 'id'),
            'name' => $this->attr($node, 'name'),
            'text' => $text,
            'is_submit' => $type === 'submit' || ($tag === 'button' && $type === ''),
            'selector' => $this->nodeSelector($node, $tag),
        ];
    }

    /** @return DOMElement[] */
    private function fieldNodes(DOMXPath $xpath, string $query, ?DOMElement $context = null): array
    {
        $nodes = [];
        foreach ($xpath->query($query, $context) as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /** @return DOMElement[] */
    private function buttonNodes(DOMXPath $xpath, ?DOMElement $context = null): array
    {
        $query = $context
            ? './/button|.//input[@type="submit"]|.//input[@type="button"]|.//input[@type="image"]'
            : '//button|//input[@type="submit"]|//input[@type="button"]|//input[@type="image"]';

        return $this->fieldNodes($xpath, $query, $context);
    }

    /** Devuelve el <form> ancestro más cercano, o null si el nodo está suelto. */
    private function ancestorForm(DOMElement $node): ?DOMElement
    {
        $parent = $node->parentNode;
        while ($parent instanceof DOMElement) {
            if (strtolower($parent->nodeName) === 'form') {
                return $parent;
            }
            $parent = $parent->parentNode;
        }

        return null;
    }

    private function fieldSelector(DOMElement $node, ?string $name, ?string $id): string
    {
        if ($id !== null && $id !== '') {
            return '#'.$this->escape($id);
        }
        if ($name !== null && $name !== '') {
            return strtolower($node->nodeName).'[name="'.$this->escape($name).'"]';
        }

        return strtolower($node->nodeName);
    }

    private function nodeSelector(DOMElement $node, string $tag): string
    {
        $id = $this->attr($node, 'id');
        if ($id !== null && $id !== '') {
            return '#'.$this->escape($id);
        }
        $name = $this->attr($node, 'name');
        if ($name !== null && $name !== '') {
            return $tag.'[name="'.$this->escape($name).'"]';
        }

        return $tag;
    }

    private function attr(DOMElement $node, string $name): ?string
    {
        if (! $node->hasAttribute($name)) {
            return null;
        }
        $value = $node->getAttribute($name);

        return $value === '' ? ($name === 'id' || $name === 'name' ? null : '') : $value;
    }

    private function escape(string $value): string
    {
        return str_replace(['"', '\\'], ['\\"', '\\\\'], $value);
    }

    /**
     * Detecta en el HTML/JS propios de la vista código que chocará con la integración
     * generada: su propio manejo de envío (submit) o llamadas de red (fetch/ajax).
     * Correrían ADEMÁS del integration.js y suelen provocar envíos dobles o errores.
     *
     * @return array<int,string>
     */
    private function conflictWarnings(string $html, ?string $js): array
    {
        $code = $html.' '.(string) $js;
        $warnings = [];

        if (
            preg_match('/addEventListener\s*\(\s*[\'"]submit[\'"]/i', $code)
            || preg_match('/\bonsubmit\s*=/i', $code)
            || preg_match('/\.onsubmit\s*=/i', $code)
        ) {
            $warnings[] = 'La vista trae su propio manejo de envío (submit). El panel toma el control del envío e IGNORA ese manejador, así que no causará envíos dobles ni errores; de todos modos, conviene quitarlo de la vista para mayor claridad.';
        }

        if (preg_match('/\bfetch\s*\(|XMLHttpRequest|\.ajax\s*\(|\baxios\b|sendBeacon/i', $code)) {
            $warnings[] = 'La vista hace llamadas de red propias (fetch/ajax). Si son para enviar el formulario, quítalas: el panel ya envía los datos a su endpoint.';
        }

        return $warnings;
    }

    private function empty(array $warnings): array
    {
        return [
            'forms' => [],
            'standalone' => ['fields' => [], 'buttons' => []],
            'summary' => ['forms' => 0, 'fields' => 0, 'buttons' => 0],
            'warnings' => $warnings,
        ];
    }
}
