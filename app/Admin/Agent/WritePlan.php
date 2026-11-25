<?php

declare(strict_types=1);

namespace App\Admin\Agent;

/**
 * 書く道具の 1 回の中身（ADR 0101）。ShopWriter::plan が引数を確かめ、今の店の値を読んで作る。
 *
 * - arguments: 確かめて形をそろえた引数（案に残し、承認の時にこれで作り直す）
 * - basis: 照らす値（改訂の番号・金額・状態など。承認の時に作り直した値と違えば断る）。個人情報を入れない
 * - summary: 人が読む要約（元の値と新しい値。案の一覧と承認の画面に出す）
 * - level: 要る段階。approval: 店の設定にかかわらず承認が要る（お金に届く操作）。external: 決済代行に頼む（ロックの外で反映する）
 */
final readonly class WritePlan
{
    /** @var array<string, mixed> */
    public array $basis;

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $basis
     */
    public function __construct(
        public string $tool,
        public string $level,
        public bool $approval,
        public bool $external,
        public array $arguments,
        array $basis,
        public string $summary,
    ) {
        // 照らす値の無い道具も、JSON の object として持つ（表の CHECK）
        $this->basis = $basis === [] ? ['none' => true] : $basis;
    }

    /**
     * 作り直した中身と、照らす値が同じか（JSON の項目の順は見ない）
     *
     * @param  array<string, mixed>  $stored
     */
    public function sameBasis(array $stored): bool
    {
        return self::canonical($this->basis) === self::canonical($stored);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        ksort($value);

        return array_map(static fn (mixed $v): mixed => is_array($v) ? self::canonical($v) : (is_int($v) || is_bool($v) || $v === null ? $v : (string) $v), $value);
    }
}
