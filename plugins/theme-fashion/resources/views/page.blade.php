@extends('theme-fashion::layout')

@section('title', $page->title.' — '.$shop->name)

{{-- 自由なページ（ADR 0039 区切り 3）: 題と、公開した順の区画。テーマの区画と核の汎用の区画（basic_*。核の部品で描く）を並べられる --}}
@section('content')
    <article class="fashion-home fashion-free-page" data-test="free-page">
        <h1 class="fashion-page-title" data-test="free-page-title">{{ $page->title }}</h1>
        @foreach ($page as $section)
            @include($section->view, ['section' => $section])
        @endforeach
    </article>
@endsection
