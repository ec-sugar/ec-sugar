@foreach ($section['list'] as $tile)
    <p data-test="shelf">{{ $tile['label'] }}</p>
@endforeach
