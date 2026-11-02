@extends('layouts.app')

@section('title', 'Nuevo registro')

@section('content')
    <h1 class="text-xl font-semibold mb-4">Nuevo registro</h1>

    <form action="{{ route('companies.store') }}" method="POST" enctype="multipart/form-data">
        @include('companies._form')
    </form>
@endsection
