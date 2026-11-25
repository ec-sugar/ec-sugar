{{--
    区画「新着のお知らせ」（App「お知らせ・ブログ」。ADR 0040 の口と ADR 0072 の並べる区画）。パターンは list（日付と題の並び）・cards（写真つきの札）。
    並べるものは核が App の係から受け取った $section->app->content（NewsSection: items〔PostCard〕・moreUrl）。ショップに出ている記事だけ。
    記事が無ければショップではこの区画は出ない（編集の画面のプレビューでは「記事を公開すると出ます」）。見た目は核の CSS（apps.css の sugar-news）
--}}
<section class="sugar-section sugar-app-section sugar-news-section" data-section="{{ $section->id }}" data-section-type="app_blog" data-pattern="{{ $section->pattern }}" data-test="news-section">
    @if ($section['heading'])
        <h2 class="sugar-section__heading">{{ $section['heading'] }}</h2>
    @endif
    @if ($section->app?->content === null)
        <p class="sugar-page__empty">記事を公開すると、ここに新着のお知らせが出ます。</p>
    @else
        <ul class="sugar-news__list" data-layout="{{ $section->pattern === 'cards' ? 'cards' : 'list' }}">
            @foreach ($section->app->content->items as $item)
                <li class="sugar-news__item" data-test="news-section-item">
                    @if ($section->pattern === 'cards' && $item->photo)
                        <img class="sugar-news__photo" src="{{ $item->photo->url('medium') }}" srcset="{{ $item->photo->srcset() }}" sizes="(min-width: 768px) 33vw, 100vw"
                             width="{{ $item->photo->width }}" height="{{ $item->photo->height }}" alt="" loading="lazy" decoding="async">
                    @endif
                    <div class="sugar-news__text">
                        <p class="sugar-news__meta"><time datetime="{{ $item->isoDate() }}">{{ $item->date() }}</time><span class="sugar-news__category">{{ $item->category }}</span></p>
                        <h3 class="sugar-news__title"><a href="{{ $item->url }}">{{ $item->title }}</a></h3>
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="sugar-news__more"><a class="sugar-page__link" href="{{ $section->app->content->moreUrl }}">お知らせの一覧へ</a></p>
    @endif
</section>
