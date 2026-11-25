@extends('theme-kinari::layout')

{{-- 自由なページ（ADR 0039 区切り 3）: 店の人が作ったページ。$page（PageView）の題と、公開した順の区画。
     区画はトップと同じ部品（sections/{種類}/{パターン}）。核の汎用の区画（basic_*）は核の部品で描き、幅の箱（1360px まで）に入れる。
     区画の間の余白はこの並びの間隔（gap-16） --}}

@section('title', $page->title.' — '.$shop->name)

@section('content')
    <div class="flex flex-col gap-16 pt-14 pb-22">
        <h1 class="mx-auto flex w-full max-w-[1360px] flex-col gap-1 px-5 sm:px-8" data-test="free-page-title">
            <span class="font-display text-[15px] tracking-[0.18em] text-ink-soft" aria-hidden="true">{{ $shop->name }}</span>
            <span class="font-mincho text-[30px] leading-normal font-medium tracking-[0.06em] [overflow-wrap:anywhere]">{{ $page->title }}</span>
        </h1>
        @foreach ($page as $section)
            @if (str_starts_with($section->key, 'basic_'))
                <div class="mx-auto hidden w-full max-w-[1360px] px-5 has-[*]:block sm:px-8">@include($section->view, ['section' => $section])</div>
            @else
                @include($section->view, ['section' => $section])
            @endif
        @endforeach
    </div>
@endsection
