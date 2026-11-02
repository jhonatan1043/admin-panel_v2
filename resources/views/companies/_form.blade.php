@csrf
<div class="grid gap-4 max-w-xl">
    <div>
        <label class="block text-sm mb-1">Registro (interno)</label>
        <input type="text" name="registro" value="{{ old('registro', $company->registro) }}"
               class="w-full rounded bg-slate-800 border border-slate-600 px-3 py-2 text-sm" required>
    </div>

    <div>
        <label class="block text-sm mb-1">Nombre (visible en la tarjeta)</label>
        <input type="text" name="name" value="{{ old('name', $company->name) }}"
               class="w-full rounded bg-slate-800 border border-slate-600 px-3 py-2 text-sm">
    </div>

    <div>
        <label class="block text-sm mb-1">Empresa (de dónde viene el cliente)</label>
        <input type="text" name="empresa" value="{{ old('empresa', $company->empresa) }}"
               class="w-full rounded bg-slate-800 border border-slate-600 px-3 py-2 text-sm">
    </div>

    <div>
        <label class="block text-sm mb-1">Estado</label>
        <select name="status" class="w-full rounded bg-slate-800 border border-slate-600 px-3 py-2 text-sm">
            @foreach (['active' => 'Activo (verde)', 'pending' => 'Pendiente (amarillo)', 'offline' => 'Desconectado (gris)', 'deletable' => 'Borrable (rojo)'] as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $company->status) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="block text-sm mb-1">Telegram chat ID</label>
        <input type="text" name="telegram_chat_id" value="{{ old('telegram_chat_id', $company->telegram_chat_id) }}"
               class="w-full rounded bg-slate-800 border border-slate-600 px-3 py-2 text-sm">
        <p class="mt-1 text-[11px] text-slate-500">El bot de Telegram se configura una sola vez en
            <a href="{{ route('settings.edit') }}" class="text-sky-400 hover:text-sky-300">Configuración</a>.</p>
    </div>

    <div>
        <label class="block text-sm mb-1">Imagen de espera (loading)</label>
        @if ($company->loading_image_path)
            <img src="{{ Storage::url($company->loading_image_path) }}" alt="loading"
                 class="h-20 rounded border border-slate-600 mb-2 object-cover">
        @endif
        <input type="file" name="loading_image" accept="image/*"
               class="w-full text-sm text-slate-300">
    </div>

    <div class="flex items-center gap-3 pt-2">
        <button class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-4 py-2 rounded">
            Guardar
        </button>
        <a href="{{ route('companies.index') }}" class="text-slate-400 hover:text-slate-200 text-sm">Cancelar</a>
    </div>
</div>
