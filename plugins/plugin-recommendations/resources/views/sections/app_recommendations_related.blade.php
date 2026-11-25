{{-- 関連商品（App「おすすめの区画」）。お客さんが最後に見た商品と同じカテゴリの、いま買える商品。足りない分は新着。並べる商品が無ければ出さない。
     編集の画面のプレビューでは、並べる商品が無くても形と案内を出す --}}
@include('app-recommendations::cards', ['type' => 'app_recommendations_related', 'empty' => $section->app?->preview ? 'ショップでは、お客さんが最後に見た商品と同じカテゴリの商品（無ければ新着）が並びます。' : null])
