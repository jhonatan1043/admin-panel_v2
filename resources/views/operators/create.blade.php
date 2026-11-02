@extends('layouts.app')

@section('title', 'Nuevo operador')

@section('content')
    <h1 class="text-xl font-semibold mb-4">Nuevo operador</h1>

    <form action="{{ route('operators.store') }}" method="POST">
        @include('operators._form')
    </form>
@endsection
