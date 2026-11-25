{{-- 核の共通の区画「比べる表」。パターンは table（表）・photo（写真とサイズ表。広い画面では写真が左、表が右。狭い画面では写真が上）。
     列の見出しは区画の欄 col1〜col5（左上のますは corner）、行はブロック（行の見出し label と c1〜c5）。見出しも中身も空の列と行は出さない
     （SectionTable）。狭い画面で表が幅に収まらなければ、表の箱の中だけが横に動く（ページは広げない）。行が 1 つも無ければ何も出さない --}}
@if (\App\Storefront\View\SectionTable::rows($section) !== [])
    <section class="sugar-section sugar-table" data-section="{{ $section->id }}" data-section-type="basic_table" data-pattern="{{ $section->pattern }}">
        @if ($section->pattern === 'photo' && ($section['image'] || ($preview ?? false)))
            <div class="sugar-table__media">
                @if ($section['image'])
                    <img src="{{ $section['image']->url('large') }}" srcset="{{ $section['image']->srcset() }}" sizes="(min-width: 768px) 35vw, 100vw"
                         width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt="{{ $section['image']->alt ?? '' }}" loading="lazy">
                @else
                    <span class="sugar-section__placeholder" aria-hidden="true"></span>
                @endif
            </div>
        @endif
        <div class="sugar-table__body">
            @if ($section['heading'])
                <h2 class="sugar-section__heading">{{ $section['heading'] }}</h2>
            @endif
            <div class="sugar-table__scroll" tabindex="0" role="region" aria-label="{{ $section['heading'] ?? '表' }}">
                <table class="sugar-table__table">
                    @if (\App\Storefront\View\SectionTable::hasHead($section))
                        <thead>
                            <tr>
                                <th scope="col">{{ $section['corner'] }}</th>
                                @foreach (\App\Storefront\View\SectionTable::columns($section) as $column)
                                    <th scope="col">{{ $section["col{$column}"] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                    @endif
                    <tbody>
                        @foreach (\App\Storefront\View\SectionTable::rows($section) as $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                @foreach (\App\Storefront\View\SectionTable::columns($section) as $column)
                                    <td>{{ $row["c{$column}"] }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($section['note'])
                <p class="sugar-table__note">{{ $section['note'] }}</p>
            @endif
        </div>
    </section>
@elseif ($preview ?? false)
    <div class="sugar-section sugar-table" data-section="{{ $section->id }}" data-section-type="basic_table" data-pattern="{{ $section->pattern }}">
        <p class="sugar-section__empty">「比較表」は、行を入力するとここに表示されます</p>
    </div>
@endif
