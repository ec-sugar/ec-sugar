{{-- よく選ばれている商品「2 列の大」。商品が選ばれていなければ区画を出さない --}}
@if ($section['products'] !== [])
    @include('theme-kinari::partials.rail-large', ['cards' => $section['products'], 'eyebrow' => 'FAVORITES', 'title' => 'よく選ばれている商品', 'anchor' => null, 'test' => 'favorites'])
@endif
