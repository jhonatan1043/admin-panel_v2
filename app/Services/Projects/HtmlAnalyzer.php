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
    public function analyze(string $html): array
    {
        $warnings = [];

        if (trim($html) === '') {
            return $this->empty(['La vista está vacía.']);
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
            'placeholder' => $this->attr($node, 'placeholder'),
            'selector' => $this->fieldSelector($node, $name, $id),
            // Config por defecto (la UI puede ajustarla): se envía y conserva su clave.
            'include' => true,
            'send_as' => $key,
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
