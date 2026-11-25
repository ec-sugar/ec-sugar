{{--
    区画の試し見の断片（ADR 0039）。区画 1 つ（$trial。テーマの見本の値）だけを、テーマの部品で描く。外枠（layout）も App も描かない。
    編集の部品（resources/storefront/editor.js）が同じオリジンから読み、[data-sugar-trial-root] の中の要素を、プレビューの中の選んでいる区画の後ろへ仮に差し込む。
    部品が何も描かない（見出しが要る区画で見本が無いなど）ときは、編集の部品が「入れると出ます」の箱を出す。
    テーマに部品の無い種類（外枠が描くお知らせの帯・フッター）は data-sugar-trial-frame の印だけ（編集の部品が「決まった場所に出ます」と知らせる）
--}}
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>セクションのプレビュー</title>
</head>
<body>
    @if ($trial->view === null)
        <div data-sugar-trial-root data-sugar-trial-frame></div>
    @else
        <div data-sugar-trial-root>
            @include($trial->view, ['section' => $trial])
        </div>
    @endif
</body>
</html>
