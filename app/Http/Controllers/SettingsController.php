<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function edit()
    {
        return view('settings.edit', [
            'botToken' => Setting::get('telegram_bot_token'),
            'chatId' => Setting::get('telegram_chat_id'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
        ]);

        Setting::put('telegram_bot_token', $data['telegram_bot_token'] ?? null);
        Setting::put('telegram_chat_id', $data['telegram_chat_id'] ?? null);

        return back()->with('status', 'Configuración guardada.');
    }
}
