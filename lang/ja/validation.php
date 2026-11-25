<?php

declare(strict_types=1);

/*
| 入力の誤りの理由（日本語）。:attributeは項目の名前（各画面で日本語の名前を渡す）。
| ここに無い決まりは英語の文になるので、新しい決まりを使ったら足す
*/

return [
    'accepted' => ':attributeに同意してください。',
    'array' => ':attributeの形が正しくありません。',
    'boolean' => ':attributeの値が正しくありません。',
    'distinct' => ':attributeが重なっています。',
    'email' => ':attributeの形を確認してください（例: name@example.com）。',
    'enum' => ':attributeは選択肢から選んでください。',
    'file' => ':attributeはファイルを選んでください。',
    'image' => ':attributeは画像を選んでください。',
    'in' => ':attributeは選択肢から選んでください。',
    'integer' => ':attributeは整数で入力してください。',
    'list' => ':attributeの形が正しくありません。',
    'max' => [
        'array' => ':attributeは :max 個までです。',
        'file' => ':attributeは :max KB までです。',
        'numeric' => ':attributeは :max 以下にしてください。',
        'string' => ':attributeは :max 文字までです。',
    ],
    'min' => [
        'array' => ':attributeは :min 個以上にしてください。',
        'file' => ':attributeは :min KB 以上にしてください。',
        'numeric' => ':attributeは :min 以上にしてください。',
        'string' => ':attributeは :min 文字以上にしてください。',
    ],
    'not_in' => ':attributeは選べない値です。',
    'not_regex' => ':attributeに使えない文字（改行など）が含まれています。',
    'numeric' => ':attributeは数字で入力してください。',
    'present' => ':attributeがありません。',
    'regex' => ':attributeの形を確認してください。',
    'required' => ':attributeを入力してください。',
    'required_with' => ':attributeを入力してください。',
    'string' => ':attributeは文字で入力してください。',
    'ulid' => ':attributeの形が正しくありません。',
    'unique' => 'この:attributeはもう使われています。',
    'uploaded' => ':attributeを送れませんでした。もう一度お試しください。',

    'attributes' => [],
];
