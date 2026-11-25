@extends('theme-sample::layout')

{{-- ページの区画の並び（ADR 0039）: 公開した順に、種類 × パターンの部品で描く。部品の無い種類（お知らせの帯は外枠が読む）は $page に入らない --}}
@section('content')
    @foreach ($page as $section)
        @include($section->view, ['section' => $section])
    @endforeach
@endsection
