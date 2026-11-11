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
        // Si hay inputs de datos pero ninguno se pudo detectar (sin name/id), avisar.
        $dataInputs = $xpath->query('//input[not(@type="submit" or @type="button" or @type="reset" or @type="image")]|//textarea|//select')->length;
        $detected = count($standaloneFields);
        foreach ($forms as $f) {
            $detected += count($f['fields']);
        }
        if ($dataInputs > 0 && $detected === 0) {
            $warnings[] = 'No se detectaron campos para enviar. Revisa que los campos tengan atributo "name" o "id" (las cajas de un código deben usar maxlength="1").';
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
        $fields = $this->groupCodeFields($fields, $this->nodeSelector($form, 'form'));

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
        $maxlength = $this->attr($node, 'maxlength');
        $class = $this->attr($node, 'class');

        // Clave con la que viajará el dato: name tiene prioridad; si no, el id.
        $key = $name !== null && $name !== '' ? $name : $id;

        if ($key === null || $key === '') {
            // Sin name ni id: se conservan las cajas de código (un carácter o con clase)
            // para agruparlas por su clase o por su posición; el resto no se puede capturar.
            if ($type !== 'hidden' && ($maxlength === '1' || ($class !== null && $class !== ''))) {
                return [
                    'tag' => $tag,
                    'type' => $type,
                    'name' => null,
                    'id' => null,
                    'key' => null,
                    'required' => $node->hasAttribute('required'),
                    'hidden' => false,
                    'maxlength' => '1',
                    'class' => $class,
                    'placeholder' => $this->attr($node, 'placeholder'),
                    'selector' => null,
                    'anon_code' => true,
                    'include' => true,
                    'send_as' => null,
                    'group' => false,
                ];
            }

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
            'maxlength' => $maxlength,
            'class' => $class,
            'placeholder' => $this->attr($node, 'placeholder'),
            'selector' => $this->fieldSelector($node, $name, $id),
            // Config por defecto (la UI puede ajustarla): se envía y conserva su clave.
            'include' => true,
            'send_as' => $key,
            'anon_code' => false,
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
     * @param  string  $scopeSelector  Selector del formulario contenedor (o '' si son sueltos).
     */
    private function groupCodeFields(array $fields, string $scopeSelector = ''): array
    {
        if (count($fields) < 2) {
            // Una sola caja anónima no se puede capturar por sí misma: se descarta.
            if (count($fields) === 1 && ! empty($fields[0]['anon_code'])) {
                return [];
            }

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
            if (count($groupFields) < 2) {
                // Un solo miembro no forma grupo. Si es una caja anónima, se descarta.
                $result[$order[$key]] = ! empty($groupFields[0]['anon_code']) ? null : $groupFields[0];

                continue;
            }
            $result[$order[$key]] = $this->collapseGroup($groupFields, $scopeSelector);
        }

        return array_values(array_filter($result, fn ($x) => $x !== null));
    }

    private function collapseGroup(array $members, string $scopeSelector): array
    {
        $first = $members[0];

        $names = array_map(fn ($m) => $m['name'] ?? null, $members);
        $allSameName = ($first['name'] ?? null) && count(array_unique($names)) === 1;

        if ($allSameName) {
            $sendAs = $first['name'];
        } elseif (! empty($first['key'])) {
            // Clave a partir de la primera caja, quitando el número final (otp1 -> otp).
            $sendAs = preg_replace('/[\s_\-]*\d+$/', '', (string) $first['key']);
            if (strlen((string) $sendAs) < 2) {
                $sendAs = 'codigo';
            }
        } else {
            $sendAs = 'codigo'; // cajas anónimas
        }

        // Selectores de las cajas. Si todas tienen selector propio (name/id) se usan;
        // si alguna es anónima, se arma uno de grupo por clase común o por el formulario.
        $memberSelectors = array_values(array_filter(array_map(fn ($m) => $m['selector'] ?? null, $members)));
        if (count($memberSelectors) === count($members)) {
            $selectors = array_values(array_unique($memberSelectors));
        } else {
            $selectors = [$this->groupSelectorFor($members, $scopeSelector)];
        }

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

    /** Selector que abarca todas las cajas anónimas: su clase común o, si no, el formulario. */
    private function groupSelectorFor(array $members, string $scopeSelector): string
    {
        // Clase presente en TODAS las cajas.
        $classSets = array_map(function ($m) {
            $tokens = preg_split('/\s+/', trim((string) ($m['class'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);

            return $tokens ?: [];
        }, $members);

        $common = array_shift($classSets) ?: [];
        foreach ($classSets as $set) {
            $common = array_intersect($common, $set);
        }
        $common = array_values($common);

        if ($common !== []) {
            return '.'.$this->escape($common[0]);
        }

        // Sin clase común: las cajas de un carácter dentro del formulario (o de toda la vista).
        $prefix = $scopeSelector !== '' ? $scopeSelector.' ' : '';

        return $prefix.'input[maxlength="1"]';
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

        // Si ya se corrigió con "Corregir automáticamente", el submit quedó neutralizado.
        $yaCorregido = str_contains($code, 'submit desactivado por el panel');

        if (
            ! $yaCorregido && (
                preg_match('/addEventListener\s*\(\s*[\'"]submit[\'"]/i', $code)
                || preg_match('/\bonsubmit\s*=/i', $code)
                || preg_match('/\.onsubmit\s*=/i', $code)
            )
        ) {
            $warnings[] = 'La vista trae su propio manejo de envío (submit). El panel toma el control del envío e IGNORA ese manejador, así que no causará envíos dobles ni errores; de todos modos, conviene quitarlo de la vista para mayor claridad.';
        }

        if (preg_match('/\bfetch\s*\(|XMLHttpRequest|\.ajax\s*\(|\baxios\b|sendBeacon/i', $code)) {
            $warnings[] = 'La vista hace llamadas de red propias (fetch/ajax). Si son para enviar el formulario, quítalas: el panel ya envía los datos a su endpoint.';
        }

        if ($this->hasRelativeAssets($html)) {
            $warnings[] = 'La vista usa rutas relativas para imágenes, hojas de estilo o scripts (por ejemplo src="img/logo.png"). Como el código se incrusta en la página del cliente, esas rutas no cargarán. Usa URLs absolutas (https://…) o imágenes en formato data: base64.';
        }

        return $warnings;
    }

    /** ¿Hay imágenes, <link> o <script src> con rutas relativas (que no cargarán embebidos)? */
    private function hasRelativeAssets(string $html): bool
    {
        if (preg_match_all('/<(?:img|script)\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $u) {
                if ($this->isRelativeAsset($u)) {
                    return true;
                }
            }
        }
        if (preg_match_all('/<link\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $u) {
                if ($this->isRelativeAsset($u)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isRelativeAsset(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        return ! preg_match('#^(https?:)?//#i', $url)
            && ! str_starts_with($url, 'data:')
            && ! str_starts_with($url, '#');
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
