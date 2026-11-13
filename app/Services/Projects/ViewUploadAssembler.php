<?php

namespace App\Services\Projects;

use Illuminate\Http\UploadedFile;

/**
 * Ensambla el HTML/CSS/JS de una vista a partir de VARIOS archivos subidos a la vez
 * (la carpeta de la plantilla: un .html, varios .css/.js e imágenes).
 *
 * Todo queda DINÁMICO en la base de datos:
 *  - las imágenes se incrustan como data: base64 dentro del HTML y del CSS
 *    (se reescriben las referencias por nombre de archivo), así no se guarda
 *    ningún archivo en disco y el bundle las lleva dentro,
 *  - los .css y .js se concatenan en orden por nombre.
 */
class ViewUploadAssembler
{
    /** Extensiones de imagen admitidas y su tipo MIME. */
    private const IMAGE_MIME = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'bmp' => 'image/bmp',
        'avif' => 'image/avif',
    ];

    /** Extensiones de fuente admitidas y su tipo MIME (se incrustan en el CSS). */
    private const FONT_MIME = [
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
    ];

    /**
     * @param  array<int, UploadedFile|null>  $files
     * @return array{0:?string,1:?string,2:?string} [html, css, js]
     */
    public function assemble(array $files, ?string $html, ?string $css, ?string $js): array
    {
        $htmlFiles = [];
        $cssFiles = [];
        $jsFiles = [];
        $assets = []; // basename => data URI (imágenes y fuentes)

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $ext = strtolower($file->getClientOriginalExtension());
            $name = $file->getClientOriginalName();
            $contents = (string) file_get_contents($file->getRealPath());
            $mime = self::IMAGE_MIME[$ext] ?? self::FONT_MIME[$ext] ?? null;

            if (in_array($ext, ['html', 'htm'], true)) {
                $htmlFiles[$name] = $contents;
            } elseif ($ext === 'css') {
                $cssFiles[$name] = $contents;
            } elseif ($ext === 'js') {
                $jsFiles[$name] = $contents;
            } elseif ($mime !== null) {
                // Imágenes y fuentes se incrustan como data: (nada queda en disco).
                $assets[$this->basename($name)] = 'data:'.$mime.';base64,'.base64_encode($contents);
            }
        }

        // HTML: el primero subido tiene prioridad sobre el texto pegado.
        if ($htmlFiles !== []) {
            $html = reset($htmlFiles);
        }

        // CSS y JS: texto pegado primero, luego los archivos ordenados por nombre.
        ksort($cssFiles);
        ksort($jsFiles);
        $css = $this->join(array_merge([$css], array_values($cssFiles)));
        $js = $this->join(array_merge([$js], array_values($jsFiles)));

        // Incrustar imágenes y fuentes como data: en el HTML y el CSS.
        if ($assets !== []) {
            $html = $this->inlineAssetsHtml((string) $html, $assets);
            // url(...) también dentro de un <style> embebido en el propio HTML
            // (cubre imágenes de fondo y @font-face con src: url(...)).
            $html = $this->inlineAssetsCss((string) $html, $assets);
            $css = $this->inlineAssetsCss($css, $assets);
        }

        return [
            $html,
            $css !== '' ? $css : null,
            $js !== '' ? $js : null,
        ];
    }

    /**
     * Agrega archivos que faltaban a una vista existente:
     *  - imágenes/fuentes → se incrustan como data: en el HTML/CSS,
     *  - .css → su contenido se suma al CSS y se quita su <link>,
     *  - .js  → su contenido se suma al JS y se quita su <script src>.
     *
     * @param  array<int, UploadedFile|null>  $files
     * @return array{0:string,1:?string,2:?string} [html, css, js]
     */
    public function addMissingFiles(string $html, ?string $css, ?string $js, array $files): array
    {
        $assets = [];
        $cssAdd = [];
        $jsAdd = [];
        $cssNames = [];
        $jsNames = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }
            $ext = strtolower($file->getClientOriginalExtension());
            $name = $file->getClientOriginalName();
            $contents = (string) file_get_contents($file->getRealPath());
            $mime = self::IMAGE_MIME[$ext] ?? self::FONT_MIME[$ext] ?? null;

            if ($ext === 'css') {
                $cssAdd[] = $contents;
                $cssNames[] = $this->basename($name);
            } elseif ($ext === 'js') {
                $jsAdd[] = $contents;
                $jsNames[] = $this->basename($name);
            } elseif ($mime !== null) {
                $assets[$this->basename($name)] = 'data:'.$mime.';base64,'.base64_encode($contents);
            }
        }

        // Imágenes y fuentes: incrustar en HTML y CSS.
        if ($assets !== []) {
            $html = $this->inlineAssetsHtml($html, $assets);
            $html = $this->inlineAssetsCss($html, $assets);
            $css = $this->inlineAssetsCss((string) $css, $assets);
        }

        // CSS referenciado por <link>: sumar su contenido y quitar el <link>.
        if ($cssAdd !== []) {
            $css = $this->join(array_merge([$css], $cssAdd));
            foreach ($cssNames as $n) {
                $html = $this->stripTagByAsset($html, 'link', 'href', $n);
            }
        }

        // JS referenciado por <script src>: sumar su contenido y quitar el <script>.
        if ($jsAdd !== []) {
            $js = $this->join(array_merge([$js], $jsAdd));
            foreach ($jsNames as $n) {
                $html = $this->stripTagByAsset($html, 'script', 'src', $n);
            }
        }

        return [
            $html,
            ($css !== null && $css !== '') ? $css : null,
            ($js !== null && $js !== '') ? $js : null,
        ];
    }

    /** Quita del HTML la etiqueta cuyo atributo apunta a ese archivo (por nombre). */
    private function stripTagByAsset(string $html, string $tag, string $attr, string $basename): string
    {
        $quoted = preg_quote($basename, '/');
        // <script ... src="...basename"></script>
        if ($tag === 'script') {
            return (string) preg_replace(
                '/<script\b[^>]*\b'.$attr.'\s*=\s*["\'][^"\']*'.$quoted.'["\'][^>]*>\s*<\/script>/i',
                '',
                $html
            );
        }

        // <link ... href="...basename" ...> (etiqueta sin cierre)
        return (string) preg_replace(
            '/<link\b[^>]*\b'.$attr.'\s*=\s*["\'][^"\']*'.$quoted.'["\'][^>]*>/i',
            '',
            $html
        );
    }

    /**
     * Lista los archivos (nombres) que la vista referencia con rutas relativas y que
     * todavía no están incrustados: imágenes, hojas de estilo, scripts y url() del CSS.
     *
     * @return array<int, string>
     */
    public function missing(?string $html, ?string $css): array
    {
        $refs = [];

        if (preg_match_all('/<(?:img|script)\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', (string) $html, $m)) {
            $refs = array_merge($refs, $m[1]);
        }
        if (preg_match_all('/<link\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']/i', (string) $html, $m)) {
            $refs = array_merge($refs, $m[1]);
        }
        foreach ([(string) $html, (string) $css] as $src) {
            if (preg_match_all('/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i', $src, $m)) {
                $refs = array_merge($refs, $m[2]);
            }
        }

        $faltan = [];
        foreach ($refs as $url) {
            $url = trim($url);
            if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, '#')
                || preg_match('#^(https?:)?//#i', $url)) {
                continue; // ya es absoluta o incrustada
            }
            $base = $this->basename($url);
            if ($base !== '') {
                $faltan[$base] = true; // dedupe por nombre de archivo
            }
        }

        return array_keys($faltan);
    }

    /** Une partes de texto no vacías con saltos de línea. */
    private function join(array $parts): string
    {
        $clean = array_filter(
            array_map(fn ($p) => trim((string) $p), $parts),
            fn ($p) => $p !== ''
        );

        return implode("\n", $clean);
    }

    /** Nombre de archivo en minúsculas, sin ruta ni query/hash. */
    private function basename(string $name): string
    {
        $name = (string) preg_replace('/[?#].*$/', '', $name);

        return strtolower(basename(str_replace('\\', '/', $name)));
    }

    /** Reescribe src/href de recursos locales (imágenes) por su data: URI. */
    private function inlineAssetsHtml(string $html, array $assets): string
    {
        return (string) preg_replace_callback(
            '/\b(src|href)\s*=\s*(["\'])(.*?)\2/is',
            function (array $m) use ($assets) {
                $uri = $this->resolve($m[3], $assets);

                return $uri === null ? $m[0] : $m[1].'='.$m[2].$uri.$m[2];
            },
            $html
        );
    }

    /** Reescribe url(...) de recursos locales (imágenes y fuentes) por su data: URI. */
    private function inlineAssetsCss(string $css, array $assets): string
    {
        return (string) preg_replace_callback(
            '/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i',
            function (array $m) use ($assets) {
                $uri = $this->resolve($m[2], $assets);

                return $uri === null ? $m[0] : 'url("'.$uri.'")';
            },
            $css
        );
    }

    /** Devuelve la data: URI si la referencia local coincide con un recurso subido. */
    private function resolve(string $ref, array $assets): ?string
    {
        $ref = trim($ref);

        if ($ref === '' || str_starts_with($ref, 'data:') || preg_match('#^(https?:)?//#i', $ref)) {
            return null; // ya es absoluta o data:, no se toca
        }

        return $assets[$this->basename($ref)] ?? null;
    }
}
