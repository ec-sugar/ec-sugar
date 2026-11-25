{{--
    検索と共有の見え方（ADR 0061。テーマ API 2 の付け足し）。テーマは layout の <head> に 1 行置くだけ:
        <x-storefront::seo :seo="$seo">@yield('title', $shop->name)</x-storefront::seo>
    中の文（テーマの題）は、検索に載せない画面（カート・注文など）の <title> に使う。載せる画面の題・説明・canonical・OGP・X のカード・
    JSON-LD は核（SeoMeta）が決める。文はどれも {{ }} で出す。JSON-LD は SeoMeta::jsonLd() が JSON の HTML 用の書き方で作った塊（</script> を壊さない）
--}}
@props(['seo' => null])
@if ($seo?->title !== null)
    <title>{{ $seo->title }}</title>
@else
    <title>{{ $slot }}</title>
@endif
    <meta name="description" content="{{ $seo?->description ?? ($seo?->siteName ?? '').'のオンラインストアです。' }}">
@if ($seo?->robots() !== null)
    <meta name="robots" content="{{ $seo->robots() }}" data-test="seo-robots">
@endif
@if ($seo?->index === true)
@if ($seo->canonical !== null)
    <link rel="canonical" href="{{ $seo->canonical }}">
@endif
    <meta property="og:site_name" content="{{ $seo->siteName }}">
    <meta property="og:type" content="{{ $seo->type }}">
    <meta property="og:title" content="{{ $seo->shareTitle ?? $seo->title }}">
@if ($seo->description !== null)
    <meta property="og:description" content="{{ $seo->description }}">
@endif
@if ($seo->canonical !== null)
    <meta property="og:url" content="{{ $seo->canonical }}">
@endif
    <meta property="og:locale" content="ja_JP">
@if ($seo->image !== null)
    <meta property="og:image" content="{{ $seo->image['url'] }}">
    <meta property="og:image:width" content="{{ $seo->image['width'] }}">
    <meta property="og:image:height" content="{{ $seo->image['height'] }}">
@if ($seo->image['alt'] !== null)
    <meta property="og:image:alt" content="{{ $seo->image['alt'] }}">
@endif
@endif
    <meta name="twitter:card" content="{{ $seo->twitterCard() }}">
    {{ $seo->jsonLd() }}
@endif
