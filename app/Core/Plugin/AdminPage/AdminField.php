<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

/**
 * App の画面の入力の欄 1 つ（足す・直すの欄。で ADR 0051 の口に足した）。描くのは核の部品で、形の確かめも核（AdminForms）。
 * 名前は英小文字・数字・_。文の欄（text・textarea）は長さの上限（max）が要る
 */
final readonly class AdminField
{
    /**
     * @param  int  $max  文の長さの上限（text は FieldType::TEXT_MAX、textarea は TEXTAREA_MAX まで）。文の欄だけ
     * @param  array<string, string>  $options  選択肢（値 => 画面の名前。並びのとおりに出す）。choice だけ
     * @param  bool|string|null  $default  足すときの最初の値（toggle は真偽、choice は選択肢の値）
     */
    public function __construct(
        public string $key,
        public FieldType $type,
        public string $label,
        public ?string $help = null,
        public bool $required = false,
        public int $max = 0,
        public array $options = [],
        public bool|string|null $default = null,
    ) {}
}
