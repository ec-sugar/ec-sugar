{{-- よく選ばれている商品「帯に 4 列」（既定のパターン）。帯の色の箱に見出しと 4 点（スマホ 2 列）。
     区画にする前は季節の特集と横に 2 つ並んでいた（区画は前後を前提にしないので、今は幅いっぱいの 1 つの箱）。
     商品が選ばれていなければ区画を出さない --}}
@if ($section['products'] !== [])
    <div class="mx-auto w-full max-w-[1360px] px-5 first:mt-18 sm:px-8" data-section="{{ $section->id }}">
        <section aria-labelledby="favorites-{{ $section->id }}" class="flex flex-col gap-5 bg-band p-6 sm:p-8" data-test="favorites" data-pattern="default">
            @include('theme-kinari::partials.section-title', ['eyebrow' => 'FAVORITES', 'title' => 'よく選ばれている商品', 'id' => 'favorites-'.$section->id])
            <ul class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                @foreach ($section['products'] as $listing)
                    <li class="min-w-0">@include('theme-kinari::partials.product-card', ['listing' => $listing, 'sizes' => '(min-width: 640px) 25vw, 50vw'])</li>
                @endforeach
            </ul>
        </section>
    </div>
@endif
