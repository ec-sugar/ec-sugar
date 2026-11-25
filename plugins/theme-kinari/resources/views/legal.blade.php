@extends('theme-kinari::layout')

{{-- テーマ API 1: $shop->legal（list<{label, value}>。未設定なら空） --}}

@section('title', '特定商取引法に基づく表記 — '.$shop->name)

@section('content')
    {{-- 表記のまとまりはページの中央に置き、中の文字は左寄せ --}}
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-8 px-5 pt-16 pb-22 sm:px-8">
        <h1 class="font-mincho text-[26px] font-medium tracking-[0.06em]">特定商取引法に基づく表記</h1>

        @if ($shop->legal === [])
            <p class="bg-band px-6 py-10 text-ink-soft" data-test="legal-empty">ただいま準備中です。お問い合わせはお店までお願いします。</p>
        @else
            <dl class="flex flex-col divide-y divide-line border-y border-line">
                @foreach ($shop->legal as $row)
                    <div class="grid grid-cols-1 gap-1 py-4 sm:grid-cols-3 sm:gap-6">
                        <dt class="text-sm text-ink-soft">{{ $row['label'] }}</dt>
                        <dd class="whitespace-pre-line sm:col-span-2">{{ $row['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </div>
@endsection
