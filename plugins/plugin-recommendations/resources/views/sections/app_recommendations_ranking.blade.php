{{-- 売れ筋ランキング（App「おすすめの区画」）。直近 30 日に売れた数の多い順の、いま買える商品（1 時間ごとに数え直す）。まだ売れていなければ出さない。
     編集の画面のプレビューでは、並べる商品が無くても形と案内を出す --}}
@include('app-recommendations::cards', ['type' => 'app_recommendations_ranking', 'empty' => $section->app?->preview ? 'ショップでは、直近 30 日に売れた数の多い商品が並びます（まだ売れていなければ出ません）。' : null])
