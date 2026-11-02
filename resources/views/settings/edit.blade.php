@extends('layouts.app')

@section('title', 'Configuración')

@section('content')
    <h1 class="text-xl font-semibold mb-1">Configuración</h1>
    <p class="text-sm text-slate-400 mb-6">Ajustes globales del panel, iguales para todos los clientes.</p>

    <form action="{{ route('settings.update') }}" method="POST" class="grid gap-4 max-w-xl">
        @csrf @method('PUT')

        <div class="rounded-lg border border-slate-700/60 bg-black/20 p-4">
            <h2 class="text-sm font-semibold text-emerald-300 mb-1">Telegram</h2>
            <p class="text-xs text-slate-500 mb-3">
                Un solo bot para todo el panel. El <code>chat_id</code> de cada cliente llega por la API; aquí solo se configura el bot.
            </p>

            <label class="block text-sm mb-1">Bot token (global)</label>
            <input type="text" name="telegram_bot_token" value="{{ old('telegram_bot_token', $botToken) }}"
                   placeholder="123456:ABC-DEF…" autocomplete="off"
                   class="w-full rounded bg-slate-800 border border-slate-600 px-3 py-2 text-sm">
            <p class="mt-1 text-[11px] {{ $botToken ? 'text-emerald-400' : 'text-amber-400' }}">
                {{ $botToken ? 'Bot configurado.' : 'Sin bot configurado: no se podrán enviar mensajes.' }}
            </p>

            <label class="block text-sm mb-1 mt-4">Chat ID (global, por defecto)</label>
            <input type="text" name="telegram_chat_id" value="{{ old('telegram_chat_id', $chatId) }}"
                   placeholder="-1001234567890" autocomplete="off"
                   class="w-full rounded bg-slate-800 border border-slate-600 px-3 py-2 text-sm">
            <p class="mt-1 text-[11px] text-slate-500">
                Se usa cuando un cliente no trae su propio <code>chat_id</code>. Útil para probar el bot.
            </p>
        </div>

        <div>
            <button class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-4 py-2 rounded">
                Guardar
            </button>
        </div>
    </form>
@endsection
