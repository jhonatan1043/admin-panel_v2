@extends('layouts.app')

@section('title', 'Operadores')

@section('content')
    <div class="flex items-center justify-between mb-5">
        <h1 class="text-xl font-semibold">Operadores
            <span class="text-xs text-slate-500 font-normal">· Total: {{ $operators->count() }}</span>
        </h1>
        <a href="{{ route('operators.create') }}"
           class="bg-emerald-600 hover:bg-emerald-500 text-white text-xs px-3 py-1.5 rounded">
            + Nuevo operador
        </a>
    </div>

    @if ($operators->isEmpty())
        <p class="text-slate-400 text-sm">Aún no hay operadores.</p>
    @else
        <div class="overflow-hidden rounded-lg border border-white/10">
            <table class="w-full text-sm">
                <thead class="bg-black/40 text-slate-300">
                    <tr>
                        <th class="text-left px-4 py-2">Nombre</th>
                        <th class="text-left px-4 py-2">Usuario</th>
                        <th class="text-left px-4 py-2">Email</th>
                        <th class="text-left px-4 py-2">Clientes</th>
                        <th class="text-left px-4 py-2">Telegram</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    @foreach ($operators as $operator)
                        <tr class="hover:bg-white/5">
                            <td class="px-4 py-2 font-medium">{{ $operator->name }}</td>
                            <td class="px-4 py-2 text-slate-300">{{ $operator->username }}</td>
                            <td class="px-4 py-2 text-slate-400">{{ $operator->email }}</td>
                            <td class="px-4 py-2 text-slate-300">{{ $operator->clients_count }}</td>
                            <td class="px-4 py-2">
                                @if ($operator->telegram_bot_token)
                                    <span class="text-emerald-400">configurado</span>
                                @else
                                    <span class="text-slate-500">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <a href="{{ route('operators.edit', $operator) }}"
                                   class="text-sky-400 hover:text-sky-300">Editar</a>
                                <form action="{{ route('operators.destroy', $operator) }}" method="POST" class="inline ml-3"
                                      onsubmit="return confirm('¿Eliminar este operador? Sus clientes quedarán sin asignar.')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-400 hover:text-red-300">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
