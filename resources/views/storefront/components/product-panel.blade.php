{{--
    App の開いて読む並び（核の部品。よくある質問・レビューなど）。テーマの商品の画面が、説明・仕様の下に置く。
    使い方（テーマ）:
        @foreach ($productPanels ?? [] as $panel)
            <section aria-labelledby="panel-{{ $panel->plugin }}-title" class="…">
                <h2 id="panel-{{ $panel->plugin }}-title" class="…">{{ $panel->title }}</h2>
                <x-storefront::product-panel :panel="$panel" class="…" />
            </section>
        @endforeach
    - $panel: ProductPanel（plugin・title・items・rating・open）。項目は <details> と <summary> で開閉する（JavaScript を使わない）。open の並びは最初から開いて出す
    - 見出し・本文・添え書き・返信は {{ }} で出す（HTML として扱わない）。本文と返信の改行は CSS の white-space: pre-line で生かす
    - 星（平均と項目ごと）は絵の文字（★☆）を読み上げから外し、「5 段階中 4.3」と読ませる（role="img" と aria-label）
    - 見た目は核の CSS（resources/storefront/apps.css の [data-product-panel]）が付け、テーマは同じ印で上書きできる
--}}
@props(['panel'])
<div {{ $attributes->merge(['data-product-panel' => $panel->plugin, 'data-test' => "product-panel-{$panel->plugin}"]) }}>
    @if ($panel->rating !== null)
        <p data-product-panel-rating data-test="product-panel-rating">
            <span data-product-panel-stars role="img" aria-label="5 段階中 {{ $panel->rating->average() }}">{{ str_repeat('★', $panel->rating->stars()) }}{{ str_repeat('☆', 5 - $panel->rating->stars()) }}</span>
            <span data-product-panel-average data-test="product-panel-average">{{ $panel->rating->average() }}</span>
            <span data-product-panel-count data-test="product-panel-count">（{{ number_format($panel->rating->count) }} 件）</span>
        </p>
    @endif
    @foreach ($panel->items as $item)
        <details data-product-panel-item @if ($panel->open) open @endif>
            @if ($item->rating === null)
                <summary data-product-panel-summary>{{ $item->summary }}</summary>
            @else
                <summary data-product-panel-summary><span data-product-panel-stars role="img" aria-label="5 段階中 {{ $item->rating }}">{{ str_repeat('★', $item->rating) }}{{ str_repeat('☆', 5 - $item->rating) }}</span><span data-product-panel-title>{{ $item->summary }}</span></summary>
            @endif
            @if ($item->meta !== null)
                <p data-product-panel-meta>{{ $item->meta }}</p>
            @endif
            <div data-product-panel-body>{{ $item->body }}</div>
            @if ($item->reply !== null)
                <div data-product-panel-reply>
                    <p data-product-panel-reply-label>ショップから</p>
                    <div data-product-panel-reply-body>{{ $item->reply }}</div>
                </div>
            @endif
        </details>
    @endforeach
</div>
