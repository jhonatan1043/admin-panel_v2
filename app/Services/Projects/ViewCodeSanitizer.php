<?php

namespace App\Services\Projects;

/**
 * Ajusta (desactiva) el código de una vista que choca con la integración generada:
 *  - desactiva los manejadores propios de `submit` del JavaScript,
 *  - quita los atributos `onsubmit` del HTML.
 *
 * No borra el resto del código de la vista (estilos, JS visual, funciones): solo
 * neutraliza lo que provoca envíos dobles o errores al enviar. Es una acción
 * explícita del administrador (botón "Corregir automáticamente").
 */
class ViewCodeSanitizer
{
    /**
     * @return array{html:string, js:string, changes:array<int,string>}
     */
    public function sanitize(string $html, ?string $js): array
    {
        $changes = [];
        $newHtml = $this->stripOnsubmitAttributes($html, $changes);
        $newJs = $this->neutralizeSubmitListeners((string) $js, $changes);
        $newJs = $this->defineMissingFunctions($html, $newJs, $changes);

        return ['html' => $newHtml, 'js' => $newJs, 'changes' => $changes];
    }

    /** ¿Hay algo que el sanitizador pueda corregir en esta vista? (para mostrar el botón). */
    public function needsFix(string $html, ?string $js): bool
    {
        return $this->sanitize($html, $js)['changes'] !== [];
    }

    /**
     * Define como funciones vacías las que la vista LLAMA pero no DEFINE (p. ej.
     * `mostrarMensaje`). Así sus manejadores (input, keyup, etc.) no revientan con
     * "X is not defined" y el resto de su lógica (como el auto-avance) sigue corriendo.
     * Es conservador: no actúa si la vista carga librerías externas.
     */
    private function defineMissingFunctions(string $html, string $js, array &$changes): string
    {
        if (trim($js) === '' || str_contains($js, 'Funciones ausentes definidas por el panel')) {
            return $js;
        }
        // Con librerías externas (<script src>) no arriesgamos: sus funciones viven fuera.
        if (preg_match('/<script[^>]+src\s*=/i', $html)) {
            return $js;
        }

        preg_match_all('/\bfunction\s+([a-zA-Z_$][\w$]*)/', $js, $defs);
        preg_match_all('/\b(?:var|let|const)\s+([a-zA-Z_$][\w$]*)/', $js, $vars);
        $defined = array_merge($defs[1], $vars[1]);

        // Llamadas tipo nombre( que NO sean métodos (.nombre()).
        preg_match_all('/(?<![.\w$])([a-zA-Z_$][\w$]*)\s*\(/', $js, $calls);

        $skip = [
            'if', 'for', 'while', 'switch', 'catch', 'function', 'return', 'typeof', 'new', 'do',
            'else', 'await', 'void', 'delete', 'throw', 'super', 'in', 'of', 'yield', 'instanceof',
            'console', 'alert', 'confirm', 'prompt', 'parseInt', 'parseFloat', 'Number', 'String',
            'Boolean', 'Array', 'Object', 'JSON', 'Math', 'Date', 'fetch', 'setTimeout', 'setInterval',
            'clearTimeout', 'clearInterval', 'document', 'window', 'encodeURIComponent', 'decodeURIComponent',
            'isNaN', 'isFinite', 'RegExp', 'Promise', 'Error', 'require', 'jQuery', 'axios', 'Swal',
            'FormData', 'URL', 'URLSearchParams', 'Map', 'Set', 'Symbol', 'navigator', 'localStorage',
            'sessionStorage', 'location', 'history', 'event',
        ];

        $missing = [];
        foreach (array_unique($calls[1]) as $name) {
            if (strlen($name) < 3) {
                continue; // evita $, i, fn cortas
            }
            if (in_array($name, $skip, true) || in_array($name, $defined, true)) {
                continue;
            }
            $missing[] = $name;
        }

        if ($missing === []) {
            return $js;
        }

        $stub = "/* Funciones ausentes definidas por el panel para evitar errores */\n";
        foreach ($missing as $name) {
            $stub .= "if (typeof {$name} === 'undefined') { window.{$name} = function () {}; }\n";
        }
        $changes[] = 'Se definieron como vacías funciones que la vista usa pero no define: '.implode(', ', $missing).'.';

        return $stub.$js;
    }

    private function stripOnsubmitAttributes(string $html, array &$changes): string
    {
        $count = 0;
        $out = preg_replace('/\sonsubmit\s*=\s*"[^"]*"/i', '', $html, -1, $c1);
        $out = preg_replace("/\sonsubmit\s*=\s*'[^']*'/i", '', $out, -1, $c2);
        $count = ($c1 ?? 0) + ($c2 ?? 0);

        if ($count > 0) {
            $changes[] = "Se quitó el atributo onsubmit del HTML ({$count}).";
        }

        return $out;
    }

    /**
     * Reemplaza el manejador de cada `addEventListener('submit', ...)` por uno
     * inofensivo que solo evita el envío nativo. Conserva el objeto y la llamada,
     * así que no rompe la sintaxis ni el resto del archivo.
     */
    private function neutralizeSubmitListeners(string $js, array &$changes): string
    {
        if ($js === '') {
            return $js;
        }

        $needle = 'addEventListener';
        $len = strlen($js);
        $result = '';
        $i = 0;
        $count = 0;

        while (($pos = strpos($js, $needle, $i)) !== false) {
            $after = $pos + strlen($needle);
            $p = $after;
            while ($p < $len && ctype_space($js[$p])) {
                $p++;
            }

            // No es una llamada addEventListener(...): se copia tal cual y se sigue.
            if ($p >= $len || $js[$p] !== '(') {
                $result .= substr($js, $i, $after - $i);
                $i = $after;

                continue;
            }

            $close = $this->matchParen($js, $p);
            if ($close === -1) {
                break; // paréntesis sin cerrar: no se toca el resto
            }

            $args = substr($js, $p + 1, $close - $p - 1);
            if (preg_match('/^\s*[\'"`]submit[\'"`]/', $args)) {
                // Ya neutralizado antes: se deja igual (evita re-corregir y mostrar el botón).
                if (str_contains($args, 'submit desactivado por el panel')) {
                    $result .= substr($js, $i, $close + 1 - $i);
                    $i = $close + 1;

                    continue;
                }
                // Texto previo (incluye el objeto y el punto) + llamada neutralizada.
                $result .= substr($js, $i, $pos - $i);
                $result .= $needle."('submit', function (ev) { if (ev && ev.preventDefault) ev.preventDefault(); } /* submit desactivado por el panel */)";
                $i = $close + 1;
                $count++;
            } else {
                $result .= substr($js, $i, $close + 1 - $i);
                $i = $close + 1;
            }
        }

        $result .= substr($js, $i);

        if ($count > 0) {
            $changes[] = "Se desactivó el manejador de 'submit' propio de la vista ({$count}).";
        }

        return $result;
    }

    /** Devuelve el índice del ')' que cierra el '(' en $open, saltando cadenas y comentarios. */
    private function matchParen(string $s, int $open): int
    {
        $depth = 0;
        $len = strlen($s);

        for ($i = $open; $i < $len; $i++) {
            $ch = $s[$i];

            if ($ch === '"' || $ch === "'" || $ch === '`') {
                $i = $this->skipString($s, $i, $ch);

                continue;
            }
            if ($ch === '/' && $i + 1 < $len && $s[$i + 1] === '/') {
                $nl = strpos($s, "\n", $i);
                if ($nl === false) {
                    return -1;
                }
                $i = $nl;

                continue;
            }
            if ($ch === '/' && $i + 1 < $len && $s[$i + 1] === '*') {
                $end = strpos($s, '*/', $i);
                if ($end === false) {
                    return -1;
                }
                $i = $end + 1;

                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }

    private function skipString(string $s, int $i, string $quote): int
    {
        $len = strlen($s);
        for ($j = $i + 1; $j < $len; $j++) {
            if ($s[$j] === '\\') {
                $j++;

                continue;
            }
            if ($s[$j] === $quote) {
                return $j;
            }
        }

        return $len - 1;
    }
}
