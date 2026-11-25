@extends('theme-botanica::layout')

{{-- ページの区画の並び（ADR 0039）: 店の人が公開した順に、種類 × パターンの部品（sections/{種類}/{パターン}）で描く。
     お知らせの帯は外枠が読むので、ここには入らない。区画は前後の区画を前提にせず、余白は区画の内側で持つ。
     ヒーロー「写真と面」のすぐ後に右の特集「ヒーローの右に縦」が来たときだけ、CSS が 2 つを左右に並べる（今までのトップの組み方） --}}
@section('content')
    <div class="botanica-home">
        @foreach ($page as $section)
            @include($section->view, ['section' => $section])
        @endforeach
    </div>
@endsection
