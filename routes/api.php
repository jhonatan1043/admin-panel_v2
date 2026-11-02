<?php

use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\MessagingController;
use App\Http\Controllers\Api\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

// GET  /api/clients  -> lista de clientes con su estado calculado por tiempo
// POST /api/clients  -> registra un cliente nuevo o refresca su actividad (lo pone en verde)
Route::get('clients', [ClientController::class, 'index']);
Route::post('clients', [ClientController::class, 'store']);

// Mensajería: desde el panel (sesión iniciada). Envía el texto del botón al Telegram del cliente.
Route::middleware(['web', 'auth'])
    ->post('companies/{company}/send', [MessagingController::class, 'send'])
    ->name('api.companies.send');

// Telegram envía aquí las respuestas del cliente (sin sesión; lo llama Telegram).
Route::post('telegram/webhook', [TelegramWebhookController::class, 'handle'])->name('api.telegram.webhook');
