@extends('theme-sample::layout')

{{-- 自由なページ（ADR 0039 区切り 3）: 題と、公開した順の区画。テーマの部品の無い核の汎用の区画（basic_text）は核の部品で描かれる --}}
@section('content')
    <h1 data-test="sample-page-title">{{ $page->title }}</h1>
    @foreach ($page as $section)
        @include($section->view, ['section' => $section])
    @endforeach
@endsection
