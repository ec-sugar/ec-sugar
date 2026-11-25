{{--
    商品の説明のブロック（核の部品。テーマ API 2 の付け足し。ADR 0032）。
    使い方: <x-storefront::description-blocks :blocks="$product->blocks" class="…" />（class などの属性は外枠に付く）
    - $blocks: list<DescriptionBlock>。店の人が書いた文は、どれも {{ }} で出す（エスケープしない出力を使わない）
    - $heading: 大きい見出しの段（商品名が h1・「商品の説明」が h2 なら 3。小さい見出しは 1 つ下）
    - 見た目はテーマが印（[data-blocks]・[data-block=…]・[data-tone=…]）から CSS で決める。この部品は class を持たない（テーマのビルドに頼らない）
--}}
@props(['blocks' => [], 'heading' => 3])
<div {{ $attributes->merge(['data-blocks' => '']) }}>
    @foreach ($blocks as $block)
        @switch($block->type)
            @case('paragraph')
                <p data-block="paragraph">{{ $block->text }}</p>
                @break
            @case('heading')
                <{{ $block->headingTag((int) $heading) }} data-block="heading" data-level="{{ $block->level }}">{{ $block->text }}</{{ $block->headingTag((int) $heading) }}>
                @break
            @case('list')
                @if ($block->ordered)
                    <ol data-block="list" data-style="number">
                        @foreach ($block->items as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ol>
                @else
                    <ul data-block="list" data-style="bullet">
                        @foreach ($block->items as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @endif
                @break
            @case('image')
                <figure data-block="image">
                    <img src="{{ $block->photo->url('large') }}" srcset="{{ $block->photo->srcset() }}" sizes="(min-width: 1024px) 40vw, 100vw"
                         width="{{ $block->photo->width }}" height="{{ $block->photo->height }}" alt="{{ $block->photo->alt ?? '' }}" loading="lazy" decoding="async">
                    @if ($block->caption !== null)
                        <figcaption>{{ $block->caption }}</figcaption>
                    @endif
                </figure>
                @break
            @case('table')
                {{-- 幅が足りなければ表の中で横に動かす（キーボードでも動かせるよう、枠に入れる） --}}
                <div data-block="table" role="region" aria-label="{{ $block->caption ?? '表' }}" tabindex="0">
                    <table>
                        @if ($block->caption !== null)
                            <caption>{{ $block->caption }}</caption>
                        @endif
                        @if ($block->header)
                            <thead>
                                <tr>
                                    @foreach ($block->headRow() as $cell)
                                        <th scope="col">{{ $cell }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                        @endif
                        <tbody>
                            @foreach ($block->bodyRows() as $row)
                                <tr>
                                    @foreach ($row as $cell)
                                        <td>{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @break
            @case('note')
                <aside data-block="note" data-tone="{{ $block->tone }}" aria-label="{{ $block->tone === 'warning' ? 'ご注意' : 'お知らせ' }}">
                    <p>{{ $block->text }}</p>
                </aside>
                @break
        @endswitch
    @endforeach
</div>
