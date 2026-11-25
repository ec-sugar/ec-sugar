{{--
    お知らせの一覧（App「お知らせ・ブログ」の既定の画面。ADR 0072）。テーマに news の画面があればそちらで描く。テーマの外枠の中に描く。
    $news（NewsList）: items（PostCard の並び）・categories（種類で絞る札。name・url・current）・page・lastPage・previousUrl・nextUrl・feedUrl。
    店の人が書いた文は、どれも {{ }} で出す。見た目は核の CSS（apps.css の sugar-news）
--}}
@extends($theme->view('layout'))

@section('title', $news->title().' — '.$shop->name)

@section('head')
    <link rel="alternate" type="application/atom+xml" title="{{ $news->title() }} — {{ $shop->name }}" href="{{ $news->feedUrl }}">
@endsection

@section('content')
    <div class="sugar-page sugar-news" data-test="news-list">
        <h1 class="sugar-page__title">{{ $news->title() }}</h1>

        @if (count($news->categories) > 2)
            <nav class="sugar-news__filters" aria-label="種類で絞る">
                @foreach ($news->categories as $category)
                    <a href="{{ $category['url'] }}" class="sugar-news__filter" @if ($category['current']) aria-current="page" @endif data-test="news-filter">{{ $category['name'] }}</a>
                @endforeach
            </nav>
        @endif

        @if ($news->items === [])
            <p class="sugar-page__empty" data-test="news-empty">まだお知らせはありません。</p>
        @else
            <ul class="sugar-news__list">
                @foreach ($news->items as $item)
                    <li class="sugar-news__item" data-test="news-item">
                        @if ($item->photo)
                            <img class="sugar-news__photo" src="{{ $item->photo->url('medium') }}" srcset="{{ $item->photo->srcset() }}" sizes="(min-width: 640px) 12rem, 100vw"
                                 width="{{ $item->photo->width }}" height="{{ $item->photo->height }}" alt="" loading="lazy" decoding="async">
                        @endif
                        <div class="sugar-news__text">
                            <p class="sugar-news__meta"><time datetime="{{ $item->isoDate() }}">{{ $item->date() }}</time><span class="sugar-news__category">{{ $item->category }}</span></p>
                            <h2 class="sugar-news__title"><a href="{{ $item->url }}">{{ $item->title }}</a></h2>
                            @if ($item->excerpt)
                                <p class="sugar-news__excerpt">{{ $item->excerpt }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($news->previousUrl || $news->nextUrl)
            <nav class="sugar-news__pager" aria-label="ページ送り" data-test="news-pager">
                @if ($news->previousUrl)
                    <a href="{{ $news->previousUrl }}" rel="prev">新しい記事へ</a>
                @endif
                <span>{{ $news->page }} / {{ $news->lastPage }}</span>
                @if ($news->nextUrl)
                    <a href="{{ $news->nextUrl }}" rel="next">古い記事へ</a>
                @endif
            </nav>
        @endif
    </div>
@endsection
