@extends('theme-default::layout')

{{-- ページの区画の並び（ADR 0039）: 店の人が公開した順に、種類 × パターンの部品（sections/{種類}/{パターン}）で描く。
     お知らせの帯とフッターは外枠（layout）が $sections から読むので、ここには部品を置かない（$page に入らない）。
     最初の並びは「写真の帯と説明 → 新着 → カテゴリ → 約束」。商品より先に、どんな店かを伝える。
     $listings（list<ListingCard>、公開中の商品だけ、新しい順）は、商品の並びで店の人が商品を選んでいないときに使う。
     区画の間の余白は外枠の main の間隔（gap-24）で、どの順に並べても同じ --}}

@section('main-class', 'gap-24 pt-2')

@section('content')
    @foreach ($page as $section)
        @include($section->view, ['section' => $section])
    @endforeach
@endsection
