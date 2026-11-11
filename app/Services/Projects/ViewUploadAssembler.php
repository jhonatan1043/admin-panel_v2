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

    /**
     * @param  array<int, UploadedFile|null>  $files
     * @return array{0:?string,1:?string,2:?string} [html, css, js]
     */
    public function assemble(array $files, ?string $html, ?string $css, ?string $js): array
    {
        $htmlFiles = [];
        $cssFiles = [];
        $jsFiles = [];
        $images = []; // basename => data URI

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $ext = strtolower($file->getClientOriginalExtension());
            $name = $file->getClientOriginalName();
            $contents = (string) file_get_contents($file->getRealPath());

            if (in_array($ext, ['html', 'htm'], true)) {
                $htmlFiles[$name] = $contents;
            } elseif ($ext === 'css') {
                $cssFiles[$name] = $contents;
            } elseif ($ext === 'js') {
                $jsFiles[$name] = $contents;
            } elseif (isset(self::IMAGE_MIME[$ext])) {
                $images[$this->basename($name)] = 'data:'.self::IMAGE_MIME[$ext].';base64,'.base64_encode($contents);
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

        // Incrustar imágenes como data: en el HTML y el CSS.
        if ($images !== []) {
            $html = $this->inlineImagesHtml((string) $html, $images);
            $css = $this->inlineImagesCss($css, $images);
        }

        return [
            $html,
            $css !== '' ? $css : null,
            $js !== '' ? $js : null,
        ];
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

    /** Reescribe src/href de imágenes locales por su data: URI. */
    private function inlineImagesHtml(string $html, array $images): string
    {
        return (string) preg_replace_callback(
            '/\b(src|href)\s*=\s*(["\'])(.*?)\2/is',
            function (array $m) use ($images) {
                $uri = $this->resolve($m[3], $images);

                return $uri === null ? $m[0] : $m[1].'='.$m[2].$uri.$m[2];
            },
            $html
        );
    }

    /** Reescribe url(...) de imágenes locales por su data: URI. */
    private function inlineImagesCss(string $css, array $images): string
    {
        return (string) preg_replace_callback(
            '/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i',
            function (array $m) use ($images) {
                $uri = $this->resolve($m[2], $images);

                return $uri === null ? $m[0] : 'url("'.$uri.'")';
            },
            $css
        );
    }

    /** Devuelve la data: URI si la referencia local coincide con una imagen subida. */
    private function resolve(string $ref, array $images): ?string
    {
        $ref = trim($ref);

        if ($ref === '' || str_starts_with($ref, 'data:') || preg_match('#^(https?:)?//#i', $ref)) {
            return null; // ya es absoluta o data:, no se toca
        }

        return $images[$this->basename($ref)] ?? null;
    }
}
