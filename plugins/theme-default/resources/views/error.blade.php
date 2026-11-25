@extends('theme-default::layout')

{{-- テーマ API 1: $status（HTTP の状態の番号） --}}

@section('title', ($status === 404 ? 'ページが見つかりません' : 'エラー').' — '.$shop->name)

@section('content')
    <section class="flex flex-col items-start gap-4 py-12" data-test="error-page">
        <p class="tabular text-sm text-ink-soft">エラー {{ $status }}</p>
        @if ($status === 404)
            <h1 class="text-2xl font-semibold">ページが見つかりません</h1>
            <p class="text-ink-soft">アドレスが間違っているか、ページが移動または削除された可能性があります。</p>
        @elseif ($status === 400)
            <h1 class="text-2xl font-semibold">リクエストを受け付けられませんでした</h1>
            <p class="text-ink-soft">入力に使えない文字が含まれていた可能性があります。</p>
        @elseif ($status === 419)
            <h1 class="text-2xl font-semibold">画面の有効期限が切れました</h1>
            <p class="text-ink-soft">しばらく時間がたったため、操作を受け付けられませんでした。前の画面に戻り、読み込み直してからもう一度お試しください。</p>
        @elseif ($status === 429)
            <h1 class="text-2xl font-semibold">操作が多すぎます</h1>
            <p class="text-ink-soft">少し時間をおいてから、もう一度お試しください。</p>
        @else
            <h1 class="text-2xl font-semibold">うまく表示できませんでした</h1>
            <p class="text-ink-soft">時間をおいて、もう一度お試しください。</p>
        @endif
        <a href="{{ route('storefront.home') }}" class="mt-2 inline-flex h-11 items-center rounded-xl bg-accent px-5 font-medium text-on-accent no-underline hover:bg-accent-hover hover:text-on-accent">トップへ戻る</a>
    </section>
@endsection
