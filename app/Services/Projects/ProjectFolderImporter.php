<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\ProjectView;
use Illuminate\Http\UploadedFile;

/**
 * Importa una CARPETA completa como proyecto:
 *  - ubica el index (vista inicial) y toma cada .html como una vista,
 *  - por cada vista resuelve sus archivos referenciados (link/script/img/url),
 *    relativos a la carpeta de esa vista: imágenes/fuentes se incrustan, css/js se
 *    fusionan y se quita su referencia,
 *  - lo que no existe o pesa de más NO rompe el proceso: se lista como faltante.
 *
 * Es una modalidad aparte de la carga por vista; aquí se reemplazan las vistas
 * estándar del proyecto por las de la carpeta (el index queda como principal).
 */
class ProjectFolderImporter
{
    /** Tamaño máximo por archivo para incrustar (KB). Por encima se lista como faltante. */
    private const MAX_KB = 4096;

    private const IMAGE_MIME = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'bmp' => 'image/bmp',
        'avif' => 'image/avif',
    ];

    private const FONT_MIME = [
        'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
    ];

    public function __construct(private ViewIntegrator $integrator) {}

    /**
     * @param  array<int, UploadedFile|null>  $files
     * @param  array<int, string>  $paths  ruta relativa de cada archivo (paralela a $files)
     * @return array{views:int, created:array<int,string>, missing:array<int,string>, error?:string}
     */
    public function import(Project $project, array $files, array $paths, array $options = []): array
    {
        $onlyLinked = $options['only_linked'] ?? true;
        $replace = $options['replace'] ?? true;

        [$byPath, $oversizePaths] = $this->indexFiles($files, $paths);

        $htmlSet = [];
        foreach (array_keys($byPath) as $p) {
            if (preg_match('/\.html?$/i', $p)) {
                $htmlSet[$p] = true;
            }
        }
        if ($htmlSet === []) {
            return ['views' => 0, 'created' => [], 'missing' => [], 'error' => 'No se encontró ningún archivo HTML en la carpeta.'];
        }

        $indexPath = $this->pickIndex(array_keys($htmlSet));

        // "Solo enlazadas": una .html es vista solo si el index la llama (grafo de enlaces).
        // "Todas": cada .html es una vista (index primero, luego por ruta).
        $views = $onlyLinked
            ? $this->reachableViews($byPath, $htmlSet, $indexPath)
            : $this->allOrdered(array_keys($htmlSet), $indexPath);

        if ($replace) {
            $project->views()->where('role', ProjectView::ROLE_STANDARD)->delete();
        }

        $order = $replace ? 0 : ((int) $project->views()->where('role', ProjectView::ROLE_STANDARD)->max('sort_order') + 1);

        // Pase 1: construir y crear cada vista; recordar ruta => slug para los enlaces.
        $missing = [];
        $records = []; // [ ['path'=>, 'view'=>, 'built'=>] ]
        $pathToSlug = [];

        foreach ($views as $path) {
            $built = $this->buildView($byPath, $oversizePaths, $path);
            foreach ($built['missing'] as $miss) {
                $missing[$miss['name']] = $missing[$miss['name']] ?? $miss['reason'];
                if ($miss['reason'] === 'tamaño') {
                    $missing[$miss['name']] = 'tamaño';
                }
            }

            $view = $project->views()->create([
                'name' => $this->viewName($path),
                'slug' => ProjectView::uniqueSlug($project->id, $this->viewName($path)),
                'role' => ProjectView::ROLE_STANDARD,
                'sort_order' => $order++,
                'sends_data' => true,
                'html' => $built['html'],
                'css' => $built['css'],
                'js' => $built['js'],
            ]);

            $pathToSlug[$path] = $view->slug;
            $records[] = ['path' => $path, 'view' => $view, 'built' => $built];
        }

        // Pase 2: remapear enlaces entre vistas, limpiar/corregir, analizar y marcar.
        $created = [];
        foreach ($records as $i => $rec) {
            $view = $rec['view'];
            $html = (string) $view->html;

            // Enlaces a otras páginas .html -> abrir esa vista dentro del embed.
            $html = $this->remapLinks($html, $this->dir($rec['path']), $pathToSlug);

            // Se conserva el JS propio del sitio tal cual (no se "corrige" al importar,
            // para no romper su funcionalidad). Si una vista choca, se corrige a mano con
            // el botón "Corregir automáticamente" en la vista.
            $view->html = $html;
            $view->save();

            $this->integrator->integrate($view);
            $view->refresh();
            $view->sends_data = filled($view->spec['targets'] ?? []);
            $view->save();

            if ($i === 0) {
                $project->forceFill(['primary_view_id' => $view->id])->save();
            }
            $created[] = $view->slug;
        }

        $missingList = [];
        foreach ($missing as $name => $reason) {
            $missingList[] = ['name' => $name, 'reason' => $reason];
        }

        return [
            'views' => count($created),
            'created' => $created,
            'missing' => $missingList,
        ];
    }

    /** Todas las .html como vistas: el index primero, el resto por profundidad y nombre. */
    private function allOrdered(array $htmls, string $indexPath): array
    {
        $rest = array_values(array_filter($htmls, fn ($p) => $p !== $indexPath));
        usort($rest, fn ($a, $b) => [substr_count($a, '/'), $a] <=> [substr_count($b, '/'), $b]);

        return array_merge([$indexPath], $rest);
    }

    /** Reescribe <a href="pagina.html"> a un enlace que abre esa vista en el embed. */
    private function remapLinks(string $html, string $dir, array $pathToSlug): string
    {
        return (string) preg_replace_callback('/(<a\b[^>]*\bhref\s*=\s*["\'])([^"\']+)(["\'])/i', function (array $m) use ($dir, $pathToSlug) {
            $resolved = $this->resolve($dir, $m[2]);
            if ($resolved !== null && isset($pathToSlug[$resolved])) {
                // href="#" + data-ew-view: el bundle dibuja esa vista sin navegar fuera.
                return $m[1].'#'.$m[3].' data-ew-view="'.$pathToSlug[$resolved].'"';
            }

            return $m[0];
        }, $html);
    }

    /**
     * Mapa rutaNormalizada => ['contents','ext'] y lista de archivos que pesan de más.
     *
     * @return array{0:array<string,array{contents:string,ext:string}>, 1:array<int,string>}
     */
    private function indexFiles(array $files, array $paths): array
    {
        $byPath = [];
        $oversize = []; // ruta normalizada => true (pesaban de más)

        foreach ($files as $i => $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }
            $rel = $paths[$i] ?? $file->getClientOriginalName();
            $norm = $this->normalize($rel);
            if ($norm === '') {
                continue;
            }

            if ($file->getSize() > self::MAX_KB * 1024) {
                $oversize[$norm] = true; // demasiado grande: no se incrusta, queda como faltante

                continue;
            }

            $byPath[$norm] = [
                'contents' => (string) file_get_contents($file->getRealPath()),
                'ext' => strtolower(pathinfo($norm, PATHINFO_EXTENSION)),
            ];
        }

        return [$byPath, $oversize];
    }

    /** Construye el HTML/CSS/JS de una vista resolviendo sus referencias. */
    private function buildView(array $byPath, array $oversize, string $htmlPath): array
    {
        $dir = $this->dir($htmlPath);
        $html = $byPath[$htmlPath]['contents'] ?? '';
        // <base> rompe la resolución de rutas relativas y el embed: se quita.
        // (Los link/script/img comentados SÍ se procesan: el archivo igual se incrusta.)
        $html = (string) preg_replace('/<base\b[^>]*>/i', '', $html);

        [$html, $css, $js] = $this->splitInline($html);
        $missing = [];

        // url() del <style> y de estilos en línea del HTML: relativos a la página.
        $css = $this->inlineCssUrls($css, $dir, $byPath, $oversize, $missing);
        $html = $this->inlineCssUrls($html, $dir, $byPath, $oversize, $missing);

        // <img src> relativos a la página.
        $html = $this->inlineImgs($html, $dir, $byPath, $oversize, $missing);

        // <link>: si es CSS se fusiona (resolviendo sus url() relativas a ESE css) y se
        // quita; si es imagen/fuente (p. ej. favicon) se incrusta en el href.
        foreach ($this->tagRefs($html, 'link', 'href') as $ref) {
            $resolved = $this->resolve($dir, $ref);
            if ($resolved === null) {
                continue;
            }
            $file = $byPath[$resolved] ?? null;
            $mime = $file ? (self::IMAGE_MIME[$file['ext']] ?? self::FONT_MIME[$file['ext']] ?? null) : null;
            if ($file && $file['ext'] === 'css') {
                $css = $this->joinText([$css, $this->inlineCssUrls($file['contents'], $this->dir($resolved), $byPath, $oversize, $missing)]);
                $html = $this->stripTag($html, 'link', 'href', $ref);
            } elseif ($file && $mime !== null) {
                $html = $this->replaceRef($html, $ref, 'data:'.$mime.';base64,'.base64_encode($file['contents']));
            } else {
                $missing[] = $this->miss($resolved, $oversize);
            }
        }

        // <script src>: se fusiona su contenido y se quita la etiqueta.
        foreach ($this->tagRefs($html, 'script', 'src') as $ref) {
            $resolved = $this->resolve($dir, $ref);
            if ($resolved === null) {
                continue;
            }
            $file = $byPath[$resolved] ?? null;
            if ($file && $file['ext'] === 'js') {
                $js = $this->joinText([$js, $file['contents']]);
                $html = $this->stripTag($html, 'script', 'src', $ref);
            } else {
                $missing[] = $this->miss($resolved, $oversize);
            }
        }

        return [
            'html' => $html,
            'css' => $css !== '' ? $css : null,
            'js' => $js !== '' ? $js : null,
            'missing' => $missing,
        ];
    }

    /** Entrada de faltante con su motivo: 'tamaño' (pesaba de más) o 'no existe'. */
    private function miss(string $resolved, array $oversize): array
    {
        return [
            'name' => $this->base($resolved),
            'reason' => isset($oversize[$resolved]) ? 'tamaño' : 'no existe',
        ];
    }

    /** Reescribe url(...) a data: (imágenes/fuentes) relativo a $dir; anota faltantes. */
    private function inlineCssUrls(string $text, string $dir, array $byPath, array $oversize, array &$missing): string
    {
        return (string) preg_replace_callback('/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i', function (array $m) use ($dir, $byPath, $oversize, &$missing) {
            $resolved = $this->resolve($dir, $m[2]);
            if ($resolved === null) {
                return $m[0];
            }
            $file = $byPath[$resolved] ?? null;
            $mime = $file ? (self::IMAGE_MIME[$file['ext']] ?? self::FONT_MIME[$file['ext']] ?? null) : null;
            if ($file && $mime !== null) {
                return 'url("data:'.$mime.';base64,'.base64_encode($file['contents']).'")';
            }
            $missing[] = $this->miss($resolved, $oversize);

            return $m[0];
        }, $text);
    }

    /** Reescribe <img src> a data: (imágenes) relativo a $dir; anota faltantes. */
    private function inlineImgs(string $html, string $dir, array $byPath, array $oversize, array &$missing): string
    {
        return (string) preg_replace_callback('/(<img\b[^>]*\bsrc\s*=\s*["\'])([^"\']+)(["\'])/i', function (array $m) use ($dir, $byPath, $oversize, &$missing) {
            $resolved = $this->resolve($dir, $m[2]);
            if ($resolved === null) {
                return $m[0];
            }
            $file = $byPath[$resolved] ?? null;
            $mime = $file ? (self::IMAGE_MIME[$file['ext']] ?? self::FONT_MIME[$file['ext']] ?? null) : null;
            if ($file && $mime !== null) {
                return $m[1].'data:'.$mime.';base64,'.base64_encode($file['contents']).$m[3];
            }
            $missing[] = $this->miss($resolved, $oversize);

            return $m[0];
        }, $html);
    }

    /** URLs referenciadas por una etiqueta/atributo concretos. */
    private function tagRefs(string $html, string $tag, string $attr): array
    {
        if (preg_match_all('/<'.$tag.'\b[^>]*\b'.$attr.'\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            return array_values(array_unique($m[1]));
        }

        return [];
    }

    /** Extrae <style> a CSS y <script> en línea a JS (deja limpio el HTML). */
    private function splitInline(string $html): array
    {
        $css = '';
        $js = '';

        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $m) && $m[1] !== []) {
            $css = trim(implode("\n", array_map('trim', $m[1])));
            $html = (string) preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        }
        if (preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*>(.*?)<\/script>/is', $html, $m) && $m[1] !== []) {
            $js = trim(implode("\n", array_map('trim', $m[1])));
            $html = (string) preg_replace('/<script\b(?![^>]*\bsrc=)[^>]*>.*?<\/script>/is', '', $html);
        }

        return [trim($html), $css, $js];
    }

    /** Reemplaza una referencia exacta por su data: URI, tanto en src/href como en url(). */
    private function replaceRef(string $text, string $ref, string $uri): string
    {
        $q = preg_quote($ref, '/');
        $text = (string) preg_replace('/((?:src|href)\s*=\s*["\'])'.$q.'(["\'])/i', '$1'.$uri.'$2', $text);
        $text = (string) preg_replace('/url\(\s*["\']?'.$q.'["\']?\s*\)/i', 'url("'.$uri.'")', $text);

        return $text;
    }

    private function stripTag(string $html, string $tag, string $attr, string $ref): string
    {
        $q = preg_quote($ref, '/');
        if ($tag === 'script') {
            return (string) preg_replace('/<script\b[^>]*\b'.$attr.'\s*=\s*["\']'.$q.'["\'][^>]*>\s*<\/script>/i', '', $html);
        }

        return (string) preg_replace('/<link\b[^>]*\b'.$attr.'\s*=\s*["\']'.$q.'["\'][^>]*>/i', '', $html);
    }

    /** ¿Debe resolverse? Devuelve la ruta normalizada, o null si es absoluta/data/ancla. */
    private function resolve(string $dir, string $ref): ?string
    {
        $ref = trim(preg_replace('/[?#].*$/', '', $ref) ?? '');
        if ($ref === '' || str_starts_with($ref, 'data:') || str_starts_with($ref, '#')
            || preg_match('#^(https?:)?//#i', $ref)) {
            return null;
        }

        $full = ($dir !== '' ? $dir.'/' : '').$ref;

        return $this->normalize($full);
    }

    /** Normaliza una ruta: separadores, ./, ../ y minúsculas. */
    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $seg;
        }

        return strtolower(implode('/', $parts));
    }

    private function dir(string $path): string
    {
        $pos = strrpos($path, '/');

        return $pos === false ? '' : substr($path, 0, $pos);
    }

    private function base(string $path): string
    {
        $pos = strrpos($path, '/');

        return $pos === false ? $path : substr($path, $pos + 1);
    }

    /** Elige el index: el index.html menos profundo; si no hay, el .html menos profundo. */
    private function pickIndex(array $htmls): string
    {
        $indexes = array_values(array_filter($htmls, fn ($p) => (bool) preg_match('/(^|\/)index\.html?$/i', $p)));
        $pool = $indexes !== [] ? $indexes : $htmls;

        usort($pool, fn ($a, $b) => [substr_count($a, '/'), $a] <=> [substr_count($b, '/'), $b]);

        return $pool[0];
    }

    /**
     * Vistas alcanzables desde el index siguiendo sus enlaces (<a href>, <form action>)
     * a otras .html de la carpeta. Recorrido en anchura: el index primero, luego las
     * que llama, y así sucesivamente. Las .html no enlazadas quedan fuera.
     *
     * @return array<int, string>
     */
    private function reachableViews(array $byPath, array $htmlSet, string $indexPath): array
    {
        $ordered = [];
        $seen = [$indexPath => true];
        $queue = [$indexPath];

        while ($queue !== []) {
            $path = array_shift($queue);
            $ordered[] = $path;

            $contents = $byPath[$path]['contents'] ?? '';
            foreach ($this->htmlLinks($contents, $this->dir($path)) as $target) {
                if (isset($htmlSet[$target]) && ! isset($seen[$target])) {
                    $seen[$target] = true;
                    $queue[] = $target;
                }
            }
        }

        return $ordered;
    }

    /**
     * Enlaces a otras páginas .html que hace esta página, ya resueltos. Se reconoce
     * cualquier referencia entre comillas a una .html: <a href>, <form action>,
     * window.open, onclick, location.href, variables JS, etc. (cubre casi todo cómo
     * "la llama" el index).
     */
    private function htmlLinks(string $html, string $dir): array
    {
        $out = [];
        // No seguir enlaces que estén comentados.
        $html = (string) preg_replace('/<!--.*?-->/s', '', $html);
        if (preg_match_all('/["\']([^"\']+?\.html?)(?:[?#][^"\']*)?["\']/i', $html, $m)) {
            foreach ($m[1] as $u) {
                $resolved = $this->resolve($dir, $u);
                if ($resolved !== null) {
                    $out[] = $resolved;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function viewName(string $path): string
    {
        $base = $this->base($path);
        $name = preg_replace('/\.html?$/i', '', $base);

        return ucfirst(str_replace(['-', '_'], ' ', (string) $name)) ?: 'Vista';
    }

    private function joinText(array $parts): string
    {
        $clean = array_filter(array_map(fn ($p) => trim((string) $p), $parts), fn ($p) => $p !== '');

        return implode("\n", $clean);
    }
}
