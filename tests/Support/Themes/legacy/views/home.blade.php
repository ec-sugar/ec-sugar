@extends('theme-legacy::layout')

@section('content')
    <h1>{{ $sections->get('hero')?->offsetGet('heading') ?? $shop->name }}</h1>
@endsection
