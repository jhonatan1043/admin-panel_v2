@csrf
<div class="grid gap-4 max-w-xl">
    <div>
        <label class="block text-sm mb-1">Nombre</label>
        <input type="text" name="name" value="{{ old('name', $operator->name) }}"
               class="w-full rounded bg-black/40 border border-slate-600 px-3 py-2 text-sm" required>
    </div>

    <div>
        <label class="block text-sm mb-1">Usuario</label>
        <input type="text" name="username" value="{{ old('username', $operator->username) }}"
               class="w-full rounded bg-black/40 border border-slate-600 px-3 py-2 text-sm" autocomplete="off" required>
    </div>

    <div>
        <label class="block text-sm mb-1">Email</label>
        <input type="email" name="email" value="{{ old('email', $operator->email) }}"
               class="w-full rounded bg-black/40 border border-slate-600 px-3 py-2 text-sm" required>
    </div>

    <div>
        <label class="block text-sm mb-1">
            Contraseña
            @if ($operator->exists)
                <span class="text-slate-500 text-xs">(dejar en blanco para no cambiarla)</span>
            @endif
        </label>
        <input type="password" name="password" autocomplete="new-password"
               class="w-full rounded bg-black/40 border border-slate-600 px-3 py-2 text-sm"
               @unless ($operator->exists) required @endunless>
    </div>

    <div class="pt-2 border-t border-white/10">
        <p class="text-xs tracking-widest text-emerald-300/80 mb-3 mt-2">TELEGRAM</p>

        <div class="grid gap-4">
            <div>
                <label class="block text-sm mb-1">Bot token</label>
                <input type="text" name="telegram_bot_token" value="{{ old('telegram_bot_token', $operator->telegram_bot_token) }}"
                       class="w-full rounded bg-black/40 border border-slate-600 px-3 py-2 text-sm" autocomplete="off"
                       placeholder="123456:ABC-DEF...">
            </div>
            <div>
                <label class="block text-sm mb-1">Chat ID</label>
                <input type="text" name="telegram_chat_id" value="{{ old('telegram_chat_id', $operator->telegram_chat_id) }}"
                       class="w-full rounded bg-black/40 border border-slate-600 px-3 py-2 text-sm"
                       placeholder="-1001234567890">
            </div>
        </div>
    </div>

    <div class="flex items-center gap-3 pt-2">
        <button class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-4 py-2 rounded">Guardar</button>
        <a href="{{ route('operators.index') }}" class="text-slate-400 hover:text-slate-200 text-sm">Cancelar</a>
    </div>
</div>
