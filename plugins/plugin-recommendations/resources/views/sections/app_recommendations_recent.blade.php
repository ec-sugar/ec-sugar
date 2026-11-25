{{-- 最近見た商品（App「おすすめの区画」）。このブラウザで見た、いま買える商品を新しい順に。まだ何も見ていないお客さんには出さない。
     編集の画面のプレビューでは、並べる商品が無くても形と案内を出す --}}
@include('app-recommendations::cards', ['type' => 'app_recommendations_recent', 'empty' => $section->app?->preview ? 'ショップでは、お客さんがこのブラウザで最近見た商品が並びます（まだ見ていないお客さんには出ません）。' : null])
