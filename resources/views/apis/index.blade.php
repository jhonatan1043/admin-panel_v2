@extends('layouts.app')

@section('title', 'APIs')

@section('content')
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-semibold">APIs</h1>
        <span class="text-xs text-slate-500">Documentación interactiva · OpenAPI</span>
    </div>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui.min.css">

    {{-- Swagger UI se renderiza con tema claro: lo envolvemos en un contenedor propio. --}}
    <div class="rounded-lg overflow-hidden bg-white">
        <div id="swagger-ui"></div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui-bundle.min.js"></script>
    <script>
        window.addEventListener('load', function () {
            window.ui = SwaggerUIBundle({
                url: @json(route('apis.spec')),
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [SwaggerUIBundle.presets.apis],
                layout: 'BaseLayout',
            });
        });
    </script>
@endsection
