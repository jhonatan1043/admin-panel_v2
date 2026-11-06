<?php

namespace App\Services\Projects;

use App\Models\ProjectView;

/**
 * Arma el HTML final que se sirve al público: la plantilla original INTACTA
 * más, añadido aparte, el CSS y el JS propios y el integration.js generado.
 *
 * Regla: no se altera el HTML/CSS/JS recibido; solo se inyectan etiquetas
 * adicionales (una hoja de estilo, el script original y el de integración).
 */
class ProjectRenderer
{
    public function render(ProjectView $view): string
    {
        $html = (string) $view->html;

        $head = '';
        if (filled($view->css)) {
            $head .= "\n<style data-project-view=\"css\">\n".$view->css."\n</style>\n";
        }
        // meta csrf para que integration.js pueda enviar el token si la vista lo necesita.
        $head .= '<meta name="csrf-token" content="'.e(csrf_token()).'">'."\n";

        $body = '';
        if (filled($view->js)) {
            $body .= "\n<script data-project-view=\"js\">\n".$view->js."\n</script>\n";
        }
        if (filled($view->integration_js)) {
            $body .= "\n<script data-project-view=\"integration\">\n".$view->integration_js."\n</script>\n";
        }

        $html = $this->injectBefore($html, '</head>', $head);
        $html = $this->injectBefore($html, '</body>', $body);

        return $html;
    }

    /**
     * Inserta $fragment justo antes de la primera aparición de $tag.
     * Si la plantilla no trae ese tag (fragmento suelto), lo añade al final
     * sin tocar nada de lo existente.
     */
    private function injectBefore(string $html, string $tag, string $fragment): string
    {
        if ($fragment === '') {
            return $html;
        }

        $pos = stripos($html, $tag);
        if ($pos === false) {
            return $html.$fragment;
        }

        return substr($html, 0, $pos).$fragment.substr($html, $pos);
    }
}
