@extends('theme-kinari::layout')

{{-- ページの区画の並び（ADR 0039）: 店の人が公開した順に、種類 × パターンの部品（sections/{種類}/{パターン}）で描く。
     お知らせの帯とフッターは外枠（layout）が $sections から読むので、ここには部品を置かない（$page に入らない）。
     ヒーローは画面の幅いっぱい、ほかの区画は自分で幅の箱（1360px まで）に入る。区画の間の余白はこの並びの間隔（gap-22）。
     核の共通の区画（basic_*）は核の部品で描くので、自由なページと同じく幅の箱に入れる。
     $listings（list<ListingCard>、公開中の商品だけ）は、新着で店の人が商品を選んでいないときに使う --}}

@section('content')
    <div class="flex flex-col gap-22 pb-22">
        @foreach ($page as $section)
            @if (str_starts_with($section->key, 'basic_'))
                <div class="mx-auto hidden w-full max-w-[1360px] px-5 first:mt-18 has-[*]:block sm:px-8">@include($section->view, ['section' => $section])</div>
            @else
                @include($section->view, ['section' => $section])
            @endif
        @endforeach
    </div>
@endsection
