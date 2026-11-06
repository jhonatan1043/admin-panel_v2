<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SettingsController extends Controller
{
    public function edit()
    {
        return view('settings.edit', [
            'botToken' => Setting::get('telegram_bot_token'),
            'chatId' => Setting::get('telegram_chat_id'),
            'soundEnabled' => Setting::get('sound_enabled', '1') === '1',
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
            'sound_enabled' => ['nullable', 'boolean'],
        ]);

        Setting::put('telegram_bot_token', $data['telegram_bot_token'] ?? null);
        Setting::put('telegram_chat_id', $data['telegram_chat_id'] ?? null);
        // Sonido de aviso al llegar algo (global). El operador puede silenciarlo
        // por unos minutos desde el propio tablero.
        Setting::put('sound_enabled', $request->boolean('sound_enabled') ? '1' : '0');

        return back()->with('status', 'Configuración guardada.');
    }

    /** Prueba la conexión con Telegram (getMe) y, si hay chat_id, envía un ping. */
    public function test(Request $request): JsonResponse
    {
        // Usa lo que esté en el formulario; si viene vacío, lo guardado.
        $botToken = $request->input('telegram_bot_token') ?: Setting::get('telegram_bot_token');
        $chatId = $request->input('telegram_chat_id') ?: Setting::get('telegram_chat_id');

        if (! $botToken) {
            return response()->json(['ok' => false, 'message' => 'Falta el bot token.'], 422);
        }

        try {
            $me = Http::timeout(10)->get("https://api.telegram.org/bot{$botToken}/getMe");
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'No se pudo conectar con Telegram.'], 502);
        }

        if (! ($me->successful() && $me->json('ok') === true)) {
            return response()->json([
                'ok' => false,
                'message' => 'Token inválido: '.($me->json('description') ?? 'Telegram rechazó la conexión.'),
            ], 422);
        }

        $bot = '@'.($me->json('result.username') ?? 'bot');

        if (! $chatId) {
            return response()->json(['ok' => true, 'message' => "Conectado como {$bot}. Sin chat_id: no se envió mensaje de prueba."]);
        }

        $send = Http::timeout(10)->asForm()->post(
            "https://api.telegram.org/bot{$botToken}/sendMessage",
            ['chat_id' => $chatId, 'text' => '✅ Ping de prueba desde el Panel Admin.']
        );

        if (! ($send->successful() && $send->json('ok') === true)) {
            return response()->json([
                'ok' => false,
                'message' => "Bot OK ({$bot}), pero el chat_id falló: ".($send->json('description') ?? 'error desconocido'),
            ], 422);
        }

        return response()->json(['ok' => true, 'message' => "Conectado como {$bot}. Ping enviado al chat {$chatId}."]);
    }
}
