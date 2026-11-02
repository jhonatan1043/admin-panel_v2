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

        <div class="flex items-center gap-3">
            <button class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-4 py-2 rounded">
                Guardar
            </button>
            <button type="button" id="btn-probar"
                    class="border border-sky-500/50 text-sky-300 hover:bg-sky-500/10 text-sm px-4 py-2 rounded">
                Probar conexión
            </button>
        </div>
    </form>

    <script>
        document.getElementById('btn-probar').addEventListener('click', function () {
            var token = (document.querySelector('[name=telegram_bot_token]').value || '').trim();
            var chat = (document.querySelector('[name=telegram_chat_id]').value || '').trim();

            Swal.fire({ title: 'Probando conexión…', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });

            fetch(@json(route('settings.test')), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify({ telegram_bot_token: token, telegram_chat_id: chat }),
            }).then(async function (res) {
                var data = await res.json().catch(function () { return {}; });
                Swal.fire({
                    icon: (res.ok && data.ok) ? 'success' : 'error',
                    title: (res.ok && data.ok) ? 'Conexión OK' : 'Falló la prueba',
                    text: data.message || ('Error ' + res.status),
                });
            }).catch(function () {
                Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudo contactar al servidor.' });
            });
        });
    </script>
@endsection
