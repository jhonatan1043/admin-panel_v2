<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Artisan;

class ApiDocsController extends Controller
{
    /** Vista de APIs embebida dentro del panel. */
    public function index()
    {
        return view('apis.index');
    }

    /** Devuelve la especificación OpenAPI (JSON) generada por l5-swagger. */
    public function spec()
    {
        // En local regenera para reflejar endpoints nuevos (incluida la mensajería).
        if (config('l5-swagger.defaults.generate_always')) {
            Artisan::call('l5-swagger:generate');
        }

        $dir = config('l5-swagger.defaults.paths.docs', storage_path('api-docs'));
        $file = config('l5-swagger.defaults.paths.docs_json', 'api-docs.json');
        $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $file;

        abort_unless(is_file($path), 404, 'Especificación no generada.');

        return response()->file($path, ['Content-Type' => 'application/json']);
    }
}
