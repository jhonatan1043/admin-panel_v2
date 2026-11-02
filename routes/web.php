<?php

use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ButtonController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\OperatorController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('companies.index'));

// Login
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    Route::post('login', [AuthController::class, 'login']);
});
Route::post('logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Panel (requiere sesión iniciada)
Route::middleware('auth')->group(function () {
    Route::resource('companies', CompanyController::class)->except('show');

    Route::post('companies/{company}/templates', [MessageTemplateController::class, 'store'])
        ->name('companies.templates.store');
    Route::put('companies/{company}/templates/{template}', [MessageTemplateController::class, 'update'])
        ->name('companies.templates.update');
    Route::delete('companies/{company}/templates/{template}', [MessageTemplateController::class, 'destroy'])
        ->name('companies.templates.destroy');

    // Solo administrador
    Route::middleware('admin')->group(function () {
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

        Route::get('apis', [ApiDocsController::class, 'index'])->name('apis.index');
        Route::get('apis/spec', [ApiDocsController::class, 'spec'])->name('apis.spec');
    });
});
