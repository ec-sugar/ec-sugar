<h1 data-test="hero-heading">{{ $section['heading'] ?? $shop->name }}</h1>
@if ($section['body'])
    <p data-test="hero-body">{{ $section['body'] }}</p>
@endif
@if ($section['image'])
    <img data-test="hero-image" src="{{ $section['image']->url() }}" srcset="{{ $section['image']->srcset() }}" width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt="">
@endif
@if ($section['link'])
    <a data-test="hero-link" href="{{ $section['link'] }}">見る</a>
@endif
@foreach ($section->blocks as $block)
    @if ($block->key === 'button' && $block['link'])
        <a data-test="hero-button" href="{{ $block['link'] }}">{{ $block['label'] ?? '見る' }}</a>
    @elseif ($block->key === 'photo' && $block['image'])
        <img data-test="hero-block-photo" src="{{ $block['image']->url('medium') }}" alt="">
    @endif
@endforeach
