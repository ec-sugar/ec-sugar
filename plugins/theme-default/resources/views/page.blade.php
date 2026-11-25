@extends('theme-default::layout')

{{-- 自由なページ（ADR 0039 区切り 3）: 店の人が作ったページ。$page（PageView）の題と、公開した順の区画。
     区画はトップと同じ部品（sections/{種類}/{パターン}）、核の汎用の区画（basic_*）は核の部品で描く（色はこのテーマの変数）。
     区画の間の余白は main の間隔（gap-16）で、どの順に並べても同じ --}}

@section('title', $page->title.' — '.$shop->name)

@section('main-class', 'gap-16 pt-10')

@section('content')
    <h1 class="flex flex-col items-center gap-2 text-center" data-test="free-page-title">
        <span class="h-px w-6 bg-ink" aria-hidden="true"></span>
        <span class="text-[26px] leading-normal font-semibold tracking-[0.06em] [overflow-wrap:anywhere]">{{ $page->title }}</span>
    </h1>
    @foreach ($page as $section)
        @include($section->view, ['section' => $section])
    @endforeach
@endsection
