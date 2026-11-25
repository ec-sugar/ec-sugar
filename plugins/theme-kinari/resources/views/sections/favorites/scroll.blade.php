{{-- よく選ばれている商品「横に流れる」。商品が選ばれていなければ区画を出さない --}}
@if ($section['products'] !== [])
    @include('theme-kinari::partials.rail-scroll', ['cards' => $section['products'], 'eyebrow' => 'FAVORITES', 'title' => 'よく選ばれている商品', 'anchor' => null, 'test' => 'favorites'])
@endif
