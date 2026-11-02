@extends('layouts.app')

@section('title', 'Editar operador')

@section('content')
    <h1 class="text-xl font-semibold mb-4">Editar operador: {{ $operator->name }}</h1>

    <form action="{{ route('operators.update', $operator) }}" method="POST">
        @method('PUT')
        @include('operators._form')
    </form>
@endsection
