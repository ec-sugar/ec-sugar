@extends('theme-sample::layout')

{{-- プライバシーポリシー（ADR 0094。任意の画面）: テーマが持てば核の既定の画面の代わりにこれで描く --}}
@section('content')
    <h1 data-test="sample-privacy-title">{{ $privacy->title }}</h1>
    <x-storefront::description-blocks :blocks="$privacy->blocks" :heading="2" />
@endsection
