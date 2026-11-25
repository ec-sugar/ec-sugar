@foreach ($section->items as $feature)
    <article data-test="feature" data-tone="{{ $feature['tone'] ?? 'light' }}">
        <h2>{{ $feature['title'] }}</h2>
        @if ($feature['image'])
            <img src="{{ $feature['image']->url('medium') }}" alt="{{ $feature['image']->alt ?? '' }}" data-test="feature-image">
        @endif
    </article>
@endforeach
