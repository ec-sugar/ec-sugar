{{-- 核の共通の区画「値段の表」。パターンは cards（カードを横に）・featured（おすすめを大きく。おすすめのカードを濃い地で一回り大きく）・
     table（表で比べる。プランを列に、値段・説明・含むもの・ボタンを段に。狭い画面では表の箱の中だけが横に動く）。
     カードは狭い画面では縦に積む。値段は店の人が書いた文字のまま（注文・支払いとは結びつかない）。含むものは 1 行を 1 つの箇条に。
     おすすめの札の文字は選択肢の名前（SectionPricing::badge）。名前も値段も無いプランは出さず、1 つも無ければ何も出さない --}}
@if (($plans = \App\Storefront\View\SectionPricing::plans($section)) !== [])
    <section class="sugar-section sugar-pricing" data-section="{{ $section->id }}" data-section-type="basic_pricing" data-pattern="{{ $section->pattern }}" data-plans="{{ count($plans) }}">
        @if ($section['heading'] || $section['body'])
            <div class="sugar-pricing__intro">
                @if ($section['heading'])
                    <h2 class="sugar-section__heading sugar-section__heading--center">{{ $section['heading'] }}</h2>
                @endif
                @if ($section['body'])
                    <p class="sugar-section__body">{{ $section['body'] }}</p>
                @endif
            </div>
        @endif
        @if ($section->pattern === 'table')
            <div class="sugar-pricing__scroll" tabindex="0" role="region" aria-label="{{ $section['heading'] ?? '値段の表' }}">
                <table class="sugar-pricing__table">
                    <thead>
                        <tr>
                            <td></td>
                            @foreach ($plans as $i => $plan)
                                <th scope="col" @if ($i === \App\Storefront\View\SectionPricing::featured($section)) data-featured @endif>
                                    @if ($i === \App\Storefront\View\SectionPricing::featured($section) && ($badge = \App\Storefront\View\SectionPricing::badge($plan)))
                                        <span class="sugar-pricing__badge">{{ $badge }}</span>
                                    @endif
                                    <span class="sugar-pricing__name">{{ $plan['name'] }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @if (\App\Storefront\View\SectionPricing::hasRow($section, 'price'))
                            <tr>
                                <th scope="row">値段</th>
                                @foreach ($plans as $i => $plan)
                                    <td @if ($i === \App\Storefront\View\SectionPricing::featured($section)) data-featured @endif>
                                        @if ($plan['price'])
                                            <span class="sugar-pricing__price">{{ $plan['price'] }}</span>
                                        @endif
                                        @if ($plan['price_note'])
                                            <span class="sugar-pricing__price-note">{{ $plan['price_note'] }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endif
                        @if (\App\Storefront\View\SectionPricing::hasRow($section, 'description'))
                            <tr>
                                <th scope="row">説明</th>
                                @foreach ($plans as $i => $plan)
                                    <td @if ($i === \App\Storefront\View\SectionPricing::featured($section)) data-featured @endif>{{ $plan['description'] }}</td>
                                @endforeach
                            </tr>
                        @endif
                        @if (\App\Storefront\View\SectionPricing::hasRow($section, 'includes'))
                            <tr>
                                <th scope="row">含まれるもの</th>
                                @foreach ($plans as $i => $plan)
                                    <td @if ($i === \App\Storefront\View\SectionPricing::featured($section)) data-featured @endif>
                                        @if (($lines = \App\Storefront\View\SectionPricing::includes($plan)) !== [])
                                            <ul class="sugar-pricing__includes" role="list">
                                                @foreach ($lines as $line)
                                                    <li>{{ $line }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endif
                        @if (\App\Storefront\View\SectionPricing::hasRow($section, 'button'))
                            <tr>
                                <td></td>
                                @foreach ($plans as $i => $plan)
                                    <td @if ($i === \App\Storefront\View\SectionPricing::featured($section)) data-featured @endif>
                                        @if ($plan['button_label'] && $plan['button_link'])
                                            <a class="sugar-pricing__button" href="{{ $plan['button_link'] }}">{{ $plan['button_label'] }}</a>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        @else
            <ul class="sugar-pricing__list" role="list">
                @foreach ($plans as $i => $plan)
                    <li class="sugar-pricing__plan" data-motion-reveal @if ($i === \App\Storefront\View\SectionPricing::featured($section)) data-featured @endif>
                        @if ($i === \App\Storefront\View\SectionPricing::featured($section) && ($badge = \App\Storefront\View\SectionPricing::badge($plan)))
                            <span class="sugar-pricing__badge">{{ $badge }}</span>
                        @endif
                        @if ($plan['name'])
                            <h3 class="sugar-pricing__name">{{ $plan['name'] }}</h3>
                        @endif
                        @if ($plan['price'] || $plan['price_note'])
                            <p class="sugar-pricing__price-line">
                                @if ($plan['price'])
                                    <span class="sugar-pricing__price">{{ $plan['price'] }}</span>
                                @endif
                                @if ($plan['price_note'])
                                    <span class="sugar-pricing__price-note">{{ $plan['price_note'] }}</span>
                                @endif
                            </p>
                        @endif
                        @if ($plan['description'])
                            <p class="sugar-pricing__description">{{ $plan['description'] }}</p>
                        @endif
                        @if (($lines = \App\Storefront\View\SectionPricing::includes($plan)) !== [])
                            <ul class="sugar-pricing__includes" role="list">
                                @foreach ($lines as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($plan['button_label'] && $plan['button_link'])
                            <a class="sugar-pricing__button" href="{{ $plan['button_link'] }}">{{ $plan['button_label'] }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($section['note'])
            <p class="sugar-pricing__note">{{ $section['note'] }}</p>
        @endif
    </section>
@elseif ($preview ?? false)
    <div class="sugar-section sugar-pricing" data-section="{{ $section->id }}" data-section-type="basic_pricing" data-pattern="{{ $section->pattern }}">
        <p class="sugar-section__empty">「値段の表」は、プランの名前か価格を入力するとここに表示されます</p>
    </div>
@endif
