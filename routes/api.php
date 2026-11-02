<?php

use App\Http\Controllers\Api\ClientController;
use Illuminate\Support\Facades\Route;

// GET  /api/clients  -> lista de clientes con su estado calculado por tiempo
// POST /api/clients  -> registra un cliente nuevo o refresca su actividad (lo pone en verde)
Route::get('clients', [ClientController::class, 'index']);
Route::post('clients', [ClientController::class, 'store']);
