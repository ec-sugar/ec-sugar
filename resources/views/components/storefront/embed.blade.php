{{--
    App の区画の埋め込み（ADR 0040）。核の部品。App の Blade は <x-storefront.embed :section="$section" :poster="…" ratio="wide" /> と書くだけで、
    枠（iframe）を描くかは核が決める（$section->app。SectionResolver が決めた AppSectionView）:
      - ショップ（live）: 最初から枠を描く（loading="lazy"。同意は待たない。2026-10-30）。JavaScript が無い人にも出る。
        外のサイトで開くリンク（宣言の links に合うときだけ）を枠の下に添える
      - 編集の画面のプレビュー・試し見（preview）: 外へは何も読み込まない。店の写真（$poster。無ければ無地）に App の名前だけ
    枠の URL・sandbox・allow は核が確かめた値（宣言の宛先に合う URL、核の決めた言葉だけ）。送り元はショップのドメインまで（strict-origin）。
    ratio は wide（16:9）・standard（4:3）・square・tall（9:16）・panorama（21:9）
--}}
@props(['section', 'poster' => null, 'ratio' => 'wide'])
@if ($section->app !== null)
    <div class="sugar-embed" data-ratio="{{ in_array($ratio, ['wide', 'standard', 'square', 'tall', 'panorama'], true) ? $ratio : 'wide' }}" data-test="app-embed">
        @if (! $section->app->preview && $section->app->live && $section->app->embed !== null)
            <iframe class="sugar-embed__frame" src="{{ $section->app->embed->src }}" title="{{ $section->app->embed->title }}"
                    sandbox="{{ implode(' ', $section->app->sandbox) }}" allow="{{ implode('; ', $section->app->allow) }}"
                    referrerpolicy="strict-origin" loading="lazy" @if (in_array('fullscreen', $section->app->allow, true)) allowfullscreen @endif data-test="app-embed-frame"></iframe>
        @else
            <div class="sugar-embed__cover">
                @if ($poster)
                    <img class="sugar-embed__poster" src="{{ $poster->url('large') }}" srcset="{{ $poster->srcset() }}" sizes="100vw"
                         width="{{ $poster->width }}" height="{{ $poster->height }}" alt="" loading="lazy">
                @endif
                <div class="sugar-embed__panel">
                    <p class="sugar-embed__note" data-test="app-embed-empty">
                        @if ($section->app->embed === null)
                            {{ $section->app->ready ? '「'.$section->app->title.'」のセクションは、項目を入力するとここに表示されます' : 'App「'.$section->app->title.'」を設定すると、ここに表示されます' }}
                        @else
                            {{-- プレビューでは外へ読み込まない（ショップでは最初から枠が出る） --}}
                            {{ '「'.$section->app->title.'」は、ショップではここに表示されます（編集画面では読み込みません）' }}
                        @endif
                    </p>
                </div>
            </div>
        @endif
    </div>
    @if (! $section->app->preview && $section->app->live && $section->app->embed?->link)
        <p class="sugar-embed__links"><a href="{{ $section->app->embed->link }}" class="sugar-embed__link" target="_blank" rel="noopener noreferrer external" data-test="app-embed-link">別のタブで開く</a></p>
    @endif
@endif
