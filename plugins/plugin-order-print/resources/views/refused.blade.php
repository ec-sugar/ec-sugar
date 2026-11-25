{{-- 出せないときのページ（払い済みでない・リンクの期限切れなど）。$message --}}
@extends('print.layout')

@section('toolbar')
    <p class="error" role="alert" data-test="print-refused">{{ $message }}</p>
@endsection

@section('content')
@endsection
