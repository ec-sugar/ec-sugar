{{--
    自由なページの核の既定の画面（ADR 0039 区切り 3）。テーマに page の画面が無いときに、テーマの外枠の中で描く。
    $page（PageView）: 題（title）と、公開した区画の並び。区画は部品（テーマの sections/… か核の storefront.sections.…）で描く。
    店の人が書いた文は、どれも {{ }} で出す
--}}
@extends($theme->view('layout'))

@section('title', $page->title.' — '.$shop->name)

@section('content')
    <article class="sugar-free-page" data-test="free-page">
        <h1 class="sugar-free-page__title" data-test="free-page-title">{{ $page->title }}</h1>
        @foreach ($page as $section)
            @include($section->view, ['section' => $section])
        @endforeach
    </article>
@endsection
