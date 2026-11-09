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

        return ['html' => $newHtml, 'js' => $newJs, 'changes' => $changes];
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
