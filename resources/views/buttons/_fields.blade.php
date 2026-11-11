@php
    $field = 'w-full rounded bg-black/40 border border-slate-600 px-2 py-1.5 text-sm focus:border-emerald-500 outline-none';
    $lbl = 'block text-[11px] uppercase tracking-wide text-slate-500 mb-1';
    // $button puede ser null (alta) o un botón existente (edición).
    $v = fn ($attr, $default = null) => old($attr, $button->{$attr} ?? $default);
@endphp

<div class="grid sm:grid-cols-6 gap-3">
    <div class="sm:col-span-2">
        <label class="{{ $lbl }}">Nombre</label>
        <input type="text" name="label" value="{{ $v('label') }}" placeholder="Ej: Licencia" required class="{{ $field }}">
    </div>
    <div class="sm:col-span-4">
        <label class="{{ $lbl }}">Texto / mensaje para el cliente</label>
        <textarea name="text" rows="2" placeholder="Mensaje…" required class="{{ $field }}">{{ $v('text') }}</textarea>
        <p class="text-[10px] text-slate-500 mt-1">Si el botón abre una vista, este texto le aparece al cliente como aviso (SweetAlert), p. ej. «Los datos no están bien diligenciados». Si es un botón de solo texto, se envía por Telegram.</p>
    </div>

    <div class="sm:col-span-3">
        <label class="{{ $lbl }}">Abre vista de proyecto <span class="text-slate-600 normal-case">(opcional)</span></label>
        <select name="project_view_id" class="{{ $field }}">
            <option value="">— Ninguna (solo Telegram) —</option>
            @foreach ($projectViews as $projectName => $views)
                <optgroup label="{{ $projectName }}">
                    @foreach ($views as $pv)
                        <option value="{{ $pv->id }}" @selected((int) $v('project_view_id') === $pv->id)>{{ $pv->name }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        <label class="flex items-center gap-1.5 mt-1.5 text-[11px] text-slate-300">
            <input type="checkbox" name="is_error_view" value="1"
                   @checked($button ? $button->is_error_view : old('is_error_view'))>
            Vista de error (reabre la misma vista y le muestra el texto como aviso de corrección)
        </label>
    </div>
    <div class="sm:col-span-3">
        <label class="{{ $lbl }}">Abrir URL <span class="text-slate-600 normal-case">(si no eliges vista)</span></label>
        <input type="text" name="url" value="{{ $v('url') }}" placeholder="https://destino.com/…" class="{{ $field }}">
        <p class="text-[10px] text-slate-500 mt-1">Si el botón no abre una vista del proyecto pero tiene URL, el cliente entra por esa dirección.</p>
    </div>

    <div class="sm:col-span-2">
        <label class="{{ $lbl }}">Orden</label>
        <input type="number" name="sort_order" value="{{ $v('sort_order', 0) }}" class="{{ $field }}">
    </div>
    <div class="sm:col-span-2">
        <label class="{{ $lbl }}">Vence (seg.)</label>
        <input type="number" name="expires_seconds" min="1" value="{{ $v('expires_seconds') }}" placeholder="No vence" class="{{ $field }}">
    </div>
    <div class="sm:col-span-2 flex items-end">
        <label class="text-xs text-slate-300 flex items-center gap-1.5 pb-1.5">
            <input type="checkbox" name="is_active" value="1" @checked($button ? $button->is_active : old('is_active', true))> Activo
        </label>
    </div>
</div>
