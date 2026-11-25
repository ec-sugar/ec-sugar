{{--
    年齢の確かめが要る商品の一言（ADR 0077、App「年齢確認」）。核の部品で、テーマの商品の画面が値段の近くに置く。
    $age は年齢（20 など）。年齢の要らない商品・App を使っていない店では null で、何も出さない
--}}
@props(['age' => null])
@if (is_int($age))
    <p class="sugar-age-notice" data-test="age-notice">{{ \App\Core\Plugin\AgeCheck\AgeRule::noticeText($age) }}</p>
@endif
