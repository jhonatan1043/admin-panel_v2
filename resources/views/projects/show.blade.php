@extends('layouts.app')

@section('title', $project->name)

@section('content')
@php
    $badge = [
        'published' => 'bg-emerald-600/20 border-emerald-500 text-emerald-200',
        'unpublished' => 'bg-amber-600/20 border-amber-500 text-amber-200',
        'draft' => 'bg-slate-600/20 border-slate-500 text-slate-300',
    ];
    $label = ['published' => 'Publicado', 'unpublished' => 'Despublicado', 'draft' => 'Borrador'];
@endphp

<div class="flex items-center justify-between gap-3 mb-1">
    <div class="flex items-center gap-3">
        <a href="{{ route('projects.index') }}" class="text-slate-500 hover:text-slate-300 text-sm">← Proyectos</a>
        <h1 class="text-lg font-semibold text-emerald-300">{{ $project->name }}</h1>
        <span class="text-xs px-2 py-1 rounded border {{ $badge[$project->status] ?? $badge['draft'] }}">
            {{ $label[$project->status] ?? 'Borrador' }}
        </span>
    </div>
    <div class="flex items-center gap-2">
        <a href="{{ route('projects.edit', $project) }}" class="text-slate-300 hover:bg-white/5 border border-slate-600 text-xs px-3 py-1.5 rounded">Editar</a>
        <form method="POST" action="{{ route('projects.destroy', $project) }}" class="js-confirm inline"
              data-confirm="Se eliminarán el proyecto y todas sus vistas." data-confirm-title="¿Eliminar proyecto?">
            @csrf @method('DELETE')
            <button class="text-red-300 hover:bg-red-500/10 border border-red-600/50 text-xs px-3 py-1.5 rounded">Eliminar</button>
        </form>
    </div>
</div>
@if ($project->description)
    <p class="text-sm text-slate-400 mb-4">{{ $project->description }}</p>
@endif

<div class="grid lg:grid-cols-3 gap-4 mt-4">
    {{-- Columna principal: vistas --}}
    <div class="lg:col-span-2 space-y-4">
        <div class="border border-slate-700 rounded bg-black/20">
            <div class="px-4 py-3 border-b border-slate-700 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-200">Vistas ({{ $formViews->count() }})</h2>
            </div>

            @forelse ($formViews as $view)
                @php $s = $view->summary(); @endphp
                <div class="px-4 py-3 border-b border-slate-800 last:border-0 flex items-center justify-between gap-3">
                    <div>
                        <a href="{{ route('projects.views.show', [$project, $view]) }}"
                           class="text-slate-100 hover:text-emerald-300 font-medium text-sm">{{ $view->name }}</a>
                        <div class="text-xs text-slate-500 mt-0.5">
                            {{ $s['forms'] }} formulario(s) · {{ $s['fields'] }} campo(s) · {{ $s['buttons'] }} botón(es)
                            @if ($view->isIntegrated())
                                <span class="text-emerald-400">· integrada ✓</span>
                            @else
                                <span class="text-amber-400">· sin integrar</span>
                            @endif
                        </div>
                    </div>
                    <a href="{{ route('projects.views.show', [$project, $view]) }}"
                       class="shrink-0 text-xs border border-slate-600 text-slate-300 hover:bg-white/5 px-3 py-1.5 rounded">Abrir</a>
                </div>
            @empty
                <div class="px-4 py-6 text-sm text-slate-500">Aún no hay vistas. Agrega una con el formulario de abajo.</div>
            @endforelse
        </div>

        {{-- Cargar una vista --}}
        <div class="border border-slate-700 rounded bg-black/20 p-4">
            <h2 class="text-sm font-semibold text-slate-200 mb-3">Cargar una vista</h2>
            <form method="POST" action="{{ route('projects.views.store', $project) }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Nombre de la vista</label>
                    <input type="text" name="name" value="{{ old('name') }}" required
                           class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-sm focus:border-emerald-500 outline-none"
                           placeholder="Registrar contacto">
                </div>
                <div class="grid sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">HTML (archivo)</label>
                        <input type="file" name="html_file" accept=".html,.htm,text/html"
                               class="w-full text-xs text-slate-400 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-emerald-600 file:text-white">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">CSS (archivo)</label>
                        <input type="file" name="css_file" accept=".css"
                               class="w-full text-xs text-slate-400 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-slate-600 file:text-white">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">JS (archivo)</label>
                        <input type="file" name="js_file" accept=".js"
                               class="w-full text-xs text-slate-400 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-slate-600 file:text-white">
                    </div>
                </div>
                <details class="text-xs text-slate-400">
                    <summary class="cursor-pointer hover:text-slate-200">…o pegar el código directamente</summary>
                    <div class="mt-2 space-y-2">
                        <textarea name="html" rows="5" placeholder="HTML"
                                  class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-xs font-mono focus:border-emerald-500 outline-none">{{ old('html') }}</textarea>
                        <textarea name="css" rows="3" placeholder="CSS (opcional)"
                                  class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-xs font-mono focus:border-emerald-500 outline-none">{{ old('css') }}</textarea>
                        <textarea name="js" rows="3" placeholder="JavaScript propio (opcional)"
                                  class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-xs font-mono focus:border-emerald-500 outline-none">{{ old('js') }}</textarea>
                    </div>
                </details>
                <p class="text-[11px] text-slate-500">El HTML, CSS y JS se conservan intactos. El sistema solo añade un <code>integration.js</code> para recibir los datos.</p>
                <button class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-4 py-2 rounded">Cargar y analizar</button>
            </form>
        </div>

        {{-- Pantalla de carga del proyecto (espera entre pasos) --}}
        <div class="border border-slate-700 rounded bg-black/20 p-4">
            <div class="flex items-center justify-between mb-1">
                <h2 class="text-sm font-semibold text-slate-200">Pantalla de carga</h2>
                @if ($loadingView)
                    <span class="text-xs text-emerald-300 border border-emerald-600/50 rounded px-2 py-0.5">Configurada ✓</span>
                @else
                    <span class="text-xs text-slate-400 border border-slate-600 rounded px-2 py-0.5">Opcional</span>
                @endif
            </div>
            <p class="text-[11px] text-slate-500 mb-3">
                Lo que el cliente ve mientras el operador revisa sus datos y pide la siguiente vista con un botón.
                Solo HTML y CSS propios de este proyecto; el JavaScript de espera lo genera el sistema.
            </p>
            <form method="POST" action="{{ route('projects.loading.store', $project) }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <div class="grid sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">HTML (archivo)</label>
                        <input type="file" name="html_file" accept=".html,.htm,text/html"
                               class="w-full text-xs text-slate-400 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-emerald-600 file:text-white">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">CSS (archivo)</label>
                        <input type="file" name="css_file" accept=".css"
                               class="w-full text-xs text-slate-400 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-slate-600 file:text-white">
                    </div>
                </div>
                <details class="text-xs text-slate-400">
                    <summary class="cursor-pointer hover:text-slate-200">…o pegar el código directamente</summary>
                    <div class="mt-2 space-y-2">
                        <textarea name="html" rows="4" placeholder="HTML de la pantalla de carga"
                                  class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-xs font-mono focus:border-emerald-500 outline-none">{{ old('html') }}</textarea>
                        <textarea name="css" rows="3" placeholder="CSS (opcional)"
                                  class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-xs font-mono focus:border-emerald-500 outline-none">{{ old('css') }}</textarea>
                    </div>
                </details>
                <div class="flex items-center gap-2">
                    <button class="bg-slate-600 hover:bg-slate-500 text-white text-sm px-4 py-2 rounded">
                        {{ $loadingView ? 'Reemplazar pantalla de carga' : 'Guardar pantalla de carga' }}
                    </button>
                    @if ($loadingView)
                        <a href="{{ route('sitio.serve', [$project, $loadingView->slug]) }}" target="_blank"
                           class="text-xs text-emerald-300 hover:underline">Ver ↗</a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    {{-- Columna lateral: publicación y subdominios --}}
    <div class="space-y-4">
        <div class="border border-slate-700 rounded bg-black/20 p-4">
            <h2 class="text-sm font-semibold text-slate-200 mb-3">Publicación</h2>

            @if ($problems)
                <div class="text-xs text-amber-200 bg-amber-600/10 border border-amber-600/40 rounded p-3 mb-3">
                    <div class="font-semibold mb-1">Falta para publicar:</div>
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach ($problems as $p) <li>{{ $p }}</li> @endforeach
                    </ul>
                </div>
            @else
                <p class="text-xs text-emerald-300 mb-3">Listo para publicar.</p>
            @endif

            @if ($project->isPublished())
                <form method="POST" action="{{ route('projects.unpublish', $project) }}">
                    @csrf
                    <button class="w-full bg-amber-600 hover:bg-amber-500 text-white text-sm px-4 py-2 rounded">Despublicar</button>
                </form>
                <p class="text-[11px] text-slate-500 mt-2">Publicado {{ $project->published_at?->diffForHumans() }}.</p>
            @else
                <form method="POST" action="{{ route('projects.publish', $project) }}">
                    @csrf
                    <button @disabled($problems) class="w-full bg-emerald-600 hover:bg-emerald-500 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm px-4 py-2 rounded">
                        Publicar
                    </button>
                </form>
            @endif
        </div>

        <div class="border border-slate-700 rounded bg-black/20 p-4">
            <h2 class="text-sm font-semibold text-slate-200 mb-3">Subdominios</h2>
            @forelse ($project->domains as $domain)
                <div class="flex items-center justify-between gap-2 text-sm py-1">
                    <span class="text-slate-300">{{ $domain->host }} @if ($domain->is_primary)<span class="text-emerald-400 text-xs">(principal)</span>@endif</span>
                    <form method="POST" action="{{ route('projects.domains.destroy', [$project, $domain]) }}" class="js-confirm inline"
                          data-confirm="Se quitará el subdominio." data-confirm-title="¿Quitar subdominio?">
                        @csrf @method('DELETE')
                        <button class="text-red-300 hover:text-red-200 text-xs">✕</button>
                    </form>
                </div>
            @empty
                <p class="text-xs text-slate-500 mb-2">Sin subdominios asociados.</p>
            @endforelse

            <form method="POST" action="{{ route('projects.domains.store', $project) }}" class="mt-2 flex gap-2">
                @csrf
                <input type="text" name="host" required placeholder="contactos.midominio.com"
                       class="flex-1 bg-slate-900 border border-slate-700 rounded px-3 py-1.5 text-sm focus:border-emerald-500 outline-none">
                <button class="bg-slate-600 hover:bg-slate-500 text-white text-sm px-3 py-1.5 rounded">+</button>
            </form>
        </div>

        @if ($project->views->isNotEmpty())
            <div class="border border-slate-700 rounded bg-black/20 p-4">
                <h2 class="text-sm font-semibold text-slate-200 mb-2">Vista previa</h2>
                <p class="text-[11px] text-slate-500 mb-2">Disponible cuando el proyecto esté publicado.</p>
                @foreach ($project->views as $view)
                    <a href="{{ route('sitio.serve', [$project, $view->slug]) }}" target="_blank"
                       class="block text-xs text-emerald-300 hover:underline py-0.5">/sitio/{{ $project->slug }}/{{ $view->slug }} ↗</a>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
