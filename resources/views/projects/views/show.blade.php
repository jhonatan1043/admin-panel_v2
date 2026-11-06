@extends('layouts.app')

@section('title', $view->name)

@section('content')
@php
    $analysis = $view->analysis ?? ['forms' => [], 'standalone' => ['fields' => [], 'buttons' => []], 'warnings' => []];
@endphp

<div class="flex items-center justify-between gap-3 mb-4">
    <div class="flex items-center gap-3">
        <a href="{{ route('projects.show', $project) }}" class="text-slate-500 hover:text-slate-300 text-sm">← {{ $project->name }}</a>
        <h1 class="text-lg font-semibold text-emerald-300">{{ $view->name }}</h1>
        @if ($view->isIntegrated())
            <span class="text-xs text-emerald-300 border border-emerald-600/50 rounded px-2 py-0.5">Integrada ✓</span>
        @else
            <span class="text-xs text-amber-300 border border-amber-600/50 rounded px-2 py-0.5">Sin integrar</span>
        @endif
    </div>
    <div class="flex items-center gap-2">
        <form method="POST" action="{{ route('projects.views.analyze', [$project, $view]) }}" class="inline">
            @csrf
            <button class="text-slate-300 hover:bg-white/5 border border-slate-600 text-xs px-3 py-1.5 rounded">Reanalizar</button>
        </form>
        <form method="POST" action="{{ route('projects.views.destroy', [$project, $view]) }}" class="js-confirm inline"
              data-confirm="Se eliminará la vista." data-confirm-title="¿Eliminar vista?">
            @csrf @method('DELETE')
            <button class="text-red-300 hover:bg-red-500/10 border border-red-600/50 text-xs px-3 py-1.5 rounded">Eliminar</button>
        </form>
    </div>
</div>

@if (!empty($analysis['warnings']))
    <div class="text-xs text-amber-200 bg-amber-600/10 border border-amber-600/40 rounded p-3 mb-4">
        <ul class="list-disc list-inside space-y-0.5">
            @foreach ($analysis['warnings'] as $w) <li>{{ $w }}</li> @endforeach
        </ul>
    </div>
@endif

<div class="grid lg:grid-cols-2 gap-4">
    {{-- Análisis detectado --}}
    <div class="space-y-4">
        <div class="border border-slate-700 rounded bg-black/20">
            <div class="px-4 py-3 border-b border-slate-700">
                <h2 class="text-sm font-semibold text-slate-200">Elementos detectados</h2>
            </div>
            <div class="p-4 space-y-4">
                @foreach ($analysis['forms'] as $i => $form)
                    <div>
                        <div class="text-xs font-semibold text-emerald-300 mb-1">
                            Formulario: {{ $form['id'] ?? $form['name'] ?? ('#'.($i + 1)) }}
                            <span class="text-slate-500 font-normal">({{ $form['selector'] }})</span>
                        </div>
                        @include('projects.views._fields', ['fields' => $form['fields']])
                        @if (!empty($form['submit']))
                            <div class="text-[11px] text-slate-500 mt-1">Envío: «{{ $form['submit']['text'] ?: 'botón' }}» → {{ $form['submit']['selector'] }}</div>
                        @endif
                    </div>
                @endforeach

                @if (!empty($analysis['standalone']['fields']))
                    <div>
                        <div class="text-xs font-semibold text-emerald-300 mb-1">Campos sueltos (sin formulario)</div>
                        @include('projects.views._fields', ['fields' => $analysis['standalone']['fields']])
                    </div>
                @endif

                @if (!empty($analysis['standalone']['buttons']))
                    <div>
                        <div class="text-xs font-semibold text-slate-300 mb-1">Botones sueltos</div>
                        <ul class="text-xs text-slate-400 space-y-0.5">
                            @foreach ($analysis['standalone']['buttons'] as $b)
                                <li>«{{ $b['text'] ?: 'botón' }}» → {{ $b['selector'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (empty($analysis['forms']) && empty($analysis['standalone']['fields']))
                    <p class="text-sm text-slate-500">No se detectaron campos para recopilar.</p>
                @endif
            </div>
        </div>

        {{-- Integración generada --}}
        <div class="border border-slate-700 rounded bg-black/20">
            <div class="px-4 py-3 border-b border-slate-700 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-200">integration.js</h2>
                @if ($view->isIntegrated())
                    <a href="{{ route('projects.views.integration', [$project, $view]) }}" target="_blank"
                       class="text-xs text-emerald-300 hover:underline">Ver archivo ↗</a>
                @endif
            </div>
            <div class="p-4">
                <p class="text-[11px] text-slate-500 mb-2">Generado automáticamente. Se añade a la vista sin modificar su código original.</p>
                <pre class="text-[11px] text-emerald-200/80 bg-black/40 rounded p-3 overflow-auto max-h-64">{{ $view->integration_js }}</pre>
            </div>
        </div>
    </div>

    {{-- Configuración manual + edición --}}
    <div class="space-y-4">
        <div class="border border-slate-700 rounded bg-black/20 p-4">
            <h2 class="text-sm font-semibold text-slate-200 mb-2">Configuración de integración</h2>
            <p class="text-[11px] text-slate-500 mb-2">Ajuste avanzado del envío (destinos, campos, clave de cada dato). Se regenera integration.js al guardar.</p>
            <form method="POST" action="{{ route('projects.views.spec', [$project, $view]) }}">
                @csrf @method('PUT')
                <textarea name="spec" rows="12"
                          class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-[11px] font-mono focus:border-emerald-500 outline-none">{{ old('spec', json_encode($view->spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}</textarea>
                <button class="mt-2 bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-4 py-2 rounded">Guardar configuración</button>
            </form>
        </div>

        <div class="border border-slate-700 rounded bg-black/20 p-4">
            <h2 class="text-sm font-semibold text-slate-200 mb-2">Reemplazar archivos</h2>
            <form method="POST" action="{{ route('projects.views.update', [$project, $view]) }}" enctype="multipart/form-data" class="space-y-3">
                @csrf @method('PUT')
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Nombre</label>
                    <input type="text" name="name" value="{{ old('name', $view->name) }}" required
                           class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-sm focus:border-emerald-500 outline-none">
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div><label class="block text-xs text-slate-400 mb-1">HTML</label><input type="file" name="html_file" accept=".html,.htm" class="w-full text-xs text-slate-400"></div>
                    <div><label class="block text-xs text-slate-400 mb-1">CSS</label><input type="file" name="css_file" accept=".css" class="w-full text-xs text-slate-400"></div>
                    <div><label class="block text-xs text-slate-400 mb-1">JS</label><input type="file" name="js_file" accept=".js" class="w-full text-xs text-slate-400"></div>
                </div>
                <p class="text-[11px] text-slate-500">Deja un campo vacío para conservar el archivo actual. Al guardar se reanaliza la vista.</p>
                <button class="bg-slate-600 hover:bg-slate-500 text-white text-sm px-4 py-2 rounded">Guardar y reanalizar</button>
            </form>
        </div>
    </div>
</div>
@endsection
