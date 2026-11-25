{{--
    お知らせの記事（App「お知らせ・ブログ」の既定の画面。ADR 0072）。テーマに news-post の画面があればそちらで描く。テーマの外枠の中に描く。
    $post（PostView）: 題・日付（プレビューの下書きでは null）・種類・一覧の写真・本文のブロック（核の部品 <x-storefront::description-blocks>）。
    有料の記事で権利が無ければ、本文は無料の分までで切れていて（有料の分はここに渡っていない）、$post->paywall に案内が入る。
    店の人が書いた文は、どれも {{ }} で出す
--}}
@extends($theme->view('layout'))

@section('title', $post->title.' — '.$shop->name)

@section('content')
    <article class="sugar-page sugar-news-post" data-test="news-post">
        <p class="sugar-news__meta">
            @if ($post->date())
                <time datetime="{{ $post->isoDate() }}">{{ $post->date() }}</time>
            @endif
            @if ($post->category !== '')
                <a class="sugar-news__category" href="{{ route('storefront.plugin.blog.pages.index', ['category' => $post->categorySlug], false) }}">{{ $post->category }}</a>
            @endif
        </p>
        <h1 class="sugar-page__title" data-test="news-post-title">{{ $post->title }}</h1>
        @if ($post->photo)
            <img class="sugar-news-post__photo" src="{{ $post->photo->url('large') }}" srcset="{{ $post->photo->srcset() }}" sizes="(min-width: 768px) 48rem, 100vw"
                 width="{{ $post->photo->width }}" height="{{ $post->photo->height }}" alt="{{ $post->photo->alt ?? '' }}" decoding="async">
        @endif
        <x-storefront::description-blocks :blocks="$post->blocks" :heading="2" class="sugar-news-post__body" data-test="news-post-body" />
        @if ($post->paywall)
            @include('blog::paywall', ['paywall' => $post->paywall])
        @endif
        <p><a class="sugar-page__link" href="{{ route('storefront.plugin.blog.pages.index', [], false) }}">お知らせの一覧へ</a></p>
    </article>
@endsection
