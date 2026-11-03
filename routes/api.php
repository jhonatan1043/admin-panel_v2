<?php

use App\Http\Controllers\Api\ButtonController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\MessagingController;
use App\Http\Controllers\Api\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

// GET  /api/clients  -> lista de clientes con su estado calculado por tiempo
// POST /api/clients  -> registra un cliente nuevo o refresca su actividad (lo pone en verde)
Route::get('clients', [ClientController::class, 'index']);
Route::post('clients', [ClientController::class, 'store']);

// GET /api/buttons -> catálogo de botones creados (id, nombre y mensaje)
Route::get('buttons', [ButtonController::class, 'index']);
Route::post('telegram/use-message', [TelegramWebhookController::class, 'useLatest']);
Route::post('telegram/send-test-code', [MessagingController::class, 'sendTestCode'])
    ->middleware('throttle:10,1');
Route::post('telegram/login-notification', [MessagingController::class, 'sendLoginNotification'])
    ->middleware('throttle:10,1');

// Mensajería: desde el panel (sesión iniciada). Envía el texto del botón al Telegram del cliente.
Route::middleware(['web', 'auth'])
    ->post('companies/{company}/send', [MessagingController::class, 'send'])
    ->name('api.companies.send');

// Telegram envía aquí las respuestas del cliente (sin sesión; lo llama Telegram).
Route::get('telegram/latest-message', [TelegramWebhookController::class, 'latest']);
Route::post('telegram/webhook', [TelegramWebhookController::class, 'handle'])->name('api.telegram.webhook');
