@extends('theme-default::layout')

{{-- テーマ API 1: $list（ProductListView: items・total・page・lastPage・query・category・breadcrumbs・children、sorts()・filters()・url()・previousUrl()・nextUrl()・pages()・title()） --}}

@section('title', $list->title().' — '.$shop->name)

@section('content')
    <div class="flex flex-col gap-8">
        <nav aria-label="パンくず" class="text-xs text-ink-soft">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('storefront.home') }}">ホーム</a></li>
                @if ($list->category)
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('storefront.products') }}">すべての商品</a></li>
                @endif
                @foreach ($list->breadcrumbs as $crumb)
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a></li>
                @endforeach
                <li aria-hidden="true">/</li>
                <li aria-current="page" class="text-ink">{{ $list->title() }}</li>
            </ol>
        </nav>

        <div class="flex flex-wrap items-end justify-between gap-6">
            <div class="flex flex-col gap-1">
                <h1 class="text-2xl font-semibold" data-test="list-title">{{ $list->title() }}</h1>
                <p class="text-[13px] text-ink-soft" data-test="list-total">{{ number_format($list->total) }} 点</p>
            </div>
            <form action="{{ route('storefront.products') }}" method="get" role="search" class="flex h-11 w-full max-w-xs items-center border border-line bg-canvas">
                <label for="product-search" class="sr-only">商品名で探す</label>
                <input id="product-search" type="search" name="q" value="{{ $list->query }}" maxlength="100" placeholder="商品名で探す"
                       class="h-full min-w-0 flex-1 bg-transparent px-3 text-[13px] text-ink placeholder:text-ink-soft focus:outline-none" data-test="search-input">
                <button type="submit" class="flex h-11 items-center px-4 text-ink" aria-label="探す">探す</button>
            </form>
        </div>

        @if ($list->children !== [])
            <nav aria-label="サブカテゴリ">
                <ul class="flex flex-wrap gap-2">
                    @foreach ($list->children as $child)
                        <li><a href="{{ $child['url'] }}" class="inline-flex h-9 items-center border border-line px-4 text-[13px] hover:border-ink">{{ $child['name'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif

        <nav aria-label="並べ替え" class="flex flex-wrap gap-5 border-b border-line pb-3 text-[13px]">
            @foreach ($list->sorts() as $sort)
                <a href="{{ $sort['url'] }}" @if ($sort['current']) aria-current="true" @endif
                   class="{{ $sort['current'] ? 'font-semibold text-ink underline underline-offset-4' : 'text-ink-soft' }}" data-test="sort-{{ $sort['value'] }}">{{ $sort['label'] }}</a>
            @endforeach
        </nav>

        @if ($list->filters() !== [])
            {{-- 絞り込み（App「並び順と絞り込み」を使う店だけ出る。同じものを押すと外れる） --}}
            <div class="flex flex-col gap-2 border-b border-line pb-4 text-[13px]" data-test="list-filters">
                @foreach ($list->filters() as $group)
                    <div class="flex flex-wrap items-baseline gap-x-5 gap-y-2">
                        <span class="w-12 shrink-0 text-ink-soft">{{ $group['label'] }}</span>
                        @foreach ($group['options'] as $option)
                            <a href="{{ $option['url'] }}" @if ($option['current']) aria-current="true" @endif
                               class="{{ $option['current'] ? 'font-semibold text-ink underline underline-offset-4' : 'text-ink-soft' }}" data-test="filter-{{ $option['value'] }}">{{ $option['label'] }}</a>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif

        @if ($list->items === [])
            <p class="bg-band px-6 py-14 text-center text-ink-soft" data-test="list-empty">
                {{ $list->query !== null ? '見つかりませんでした。別の言葉でお試しください。' : 'まだ商品がありません。' }}
            </p>
        @else
            <ul class="grid grid-cols-2 gap-x-5 gap-y-10 md:grid-cols-3 lg:grid-cols-4">
                @foreach ($list->items as $listing)
                    <li>@include('theme-default::partials.product-card', ['listing' => $listing])</li>
                @endforeach
            </ul>
        @endif

        @if ($list->lastPage > 1)
            <nav aria-label="ページ送り" class="flex flex-wrap items-center justify-center gap-1 pt-4 text-[13px]" data-test="pagination">
                @if ($list->previousUrl())
                    <a href="{{ $list->previousUrl() }}" class="inline-flex h-10 items-center px-3" rel="prev">前へ</a>
                @endif
                @foreach ($list->pages() as $number)
                    @if ($number === null)
                        <span class="px-2 text-ink-soft" aria-hidden="true">…</span>
                    @elseif ($number === $list->page)
                        <span aria-current="page" class="inline-flex size-10 items-center justify-center bg-ink text-canvas">{{ $number }}</span>
                    @else
                        <a href="{{ $list->url($number) }}" class="inline-flex size-10 items-center justify-center hover:bg-band">{{ $number }}</a>
                    @endif
                @endforeach
                @if ($list->nextUrl())
                    <a href="{{ $list->nextUrl() }}" class="inline-flex h-10 items-center px-3" rel="next">次へ</a>
                @endif
            </nav>
        @endif
    </div>
@endsection
