<?php

use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ButtonController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\OperatorController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectViewController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\SettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Si el host es un subdominio de un proyecto publicado, se sirve ese proyecto;
// si no, el panel normal.
Route::get('/', function (Request $request) {
    if (PublicSiteController::hostIsProject($request)) {
        return app(PublicSiteController::class)->serveByHost($request);
    }

    return redirect()->route('companies.index');
});

// Servir vistas de proyectos publicados por ruta (siempre disponible, sin DNS).
Route::get('sitio/{project:slug}/{viewSlug?}', [PublicSiteController::class, 'serve'])
    ->name('sitio.serve');

// Login
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    Route::post('login', [AuthController::class, 'login']);
});
Route::post('logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Panel (requiere sesión iniciada)
Route::middleware('auth')->group(function () {
    // Tablero en vivo (polling): devuelve solo la grilla actualizada.
    Route::get('companies/board', [CompanyController::class, 'board'])->name('companies.board');
    // Conversación en vivo de la tarjeta abierta.
    Route::get('companies/{company}/detail', [CompanyController::class, 'detail'])->name('companies.detail');
    // Exportar la conversación del cliente a TXT.
    Route::get('companies/{company}/detail/export', [CompanyController::class, 'exportDetail'])->name('companies.detail.export');

    Route::resource('companies', CompanyController::class)->except('show');

    Route::post('companies/{company}/templates', [MessageTemplateController::class, 'store'])
        ->name('companies.templates.store');
    Route::put('companies/{company}/templates/{template}', [MessageTemplateController::class, 'update'])
        ->name('companies.templates.update');
    Route::delete('companies/{company}/templates/{template}', [MessageTemplateController::class, 'destroy'])
        ->name('companies.templates.destroy');

    // Solo administrador
    Route::middleware('admin')->group(function () {
        Route::put('companies/{company}/assign', [CompanyController::class, 'assign'])->name('companies.assign');

        Route::get('operadores', [OperatorController::class, 'index'])->name('operators.index');
        Route::get('operadores/nuevo', [OperatorController::class, 'create'])->name('operators.create');
        Route::post('operadores', [OperatorController::class, 'store'])->name('operators.store');
        Route::get('operadores/{operator}/editar', [OperatorController::class, 'edit'])->name('operators.edit');
        Route::put('operadores/{operator}', [OperatorController::class, 'update'])->name('operators.update');
        Route::delete('operadores/{operator}', [OperatorController::class, 'destroy'])->name('operators.destroy');

        Route::get('botones', [ButtonController::class, 'index'])->name('buttons.index');
        Route::post('botones', [ButtonController::class, 'store'])->name('buttons.store');
        Route::put('botones/{button}', [ButtonController::class, 'update'])->name('buttons.update');
        Route::delete('botones/{button}', [ButtonController::class, 'destroy'])->name('buttons.destroy');

        // Módulo de proyectos: vistas HTML dinámicas con integración automática.
        Route::resource('proyectos', ProjectController::class)
            ->parameters(['proyectos' => 'project'])
            ->names('projects');
        Route::post('proyectos/{project}/publicar', [ProjectController::class, 'publish'])->name('projects.publish');
        Route::post('proyectos/{project}/despublicar', [ProjectController::class, 'unpublish'])->name('projects.unpublish');
        Route::post('proyectos/{project}/subdominios', [ProjectController::class, 'storeDomain'])->name('projects.domains.store');
        Route::delete('proyectos/{project}/subdominios/{domain}', [ProjectController::class, 'destroyDomain'])->name('projects.domains.destroy');

        // Vistas de un proyecto.
        Route::post('proyectos/{project}/vistas', [ProjectViewController::class, 'store'])->name('projects.views.store');
        Route::post('proyectos/{project}/pantalla-carga', [ProjectViewController::class, 'storeLoading'])->name('projects.loading.store');
        Route::get('proyectos/{project}/vistas/{view}', [ProjectViewController::class, 'show'])->name('projects.views.show');
        Route::put('proyectos/{project}/vistas/{view}', [ProjectViewController::class, 'update'])->name('projects.views.update');
        Route::post('proyectos/{project}/vistas/{view}/analizar', [ProjectViewController::class, 'analyze'])->name('projects.views.analyze');
        Route::put('proyectos/{project}/vistas/{view}/integracion', [ProjectViewController::class, 'updateSpec'])->name('projects.views.spec');
        Route::get('proyectos/{project}/vistas/{view}/integration.js', [ProjectViewController::class, 'integrationJs'])->name('projects.views.integration');
        Route::delete('proyectos/{project}/vistas/{view}', [ProjectViewController::class, 'destroy'])->name('projects.views.destroy');

        Route::get('apis', [ApiDocsController::class, 'index'])->name('apis.index');
        Route::get('apis/spec', [ApiDocsController::class, 'spec'])->name('apis.spec');

        // Configuración global (incluye el bot de Telegram del panel).
        Route::get('configuracion', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('configuracion', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('configuracion/probar', [SettingsController::class, 'test'])->name('settings.test');
    });
});

// Para un subdominio de proyecto, cualquier ruta no reconocida sirve esa vista
// del proyecto (p. ej. contactos.midominio.com/registro -> vista "registro").
Route::fallback(function (Request $request) {
    if (PublicSiteController::hostIsProject($request)) {
        $segment = trim($request->path(), '/');
        $viewSlug = $segment === '' ? null : explode('/', $segment)[0];

        return app(PublicSiteController::class)->serveByHost($request, $viewSlug);
    }

    abort(404);
});
