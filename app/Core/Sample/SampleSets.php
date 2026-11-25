<?php

declare(strict_types=1);

namespace App\Core\Sample;

use InvalidArgumentException;

/**
 * 入れたときのサンプルのセットの一覧。インストーラーとコマンドは名前（--set）で選ぶ。
 *
 * 印（sample_marks.key）の名前: 雑貨は前からの形（listing:mug・theme:default・legal）、ほかのセットは前にセットの名前を付ける
 * （sweets_listing:jam-apricot・sweets_legal）。消した後に別のセットを入れても、前のセットの閉じた印と重ならない
 */
final class SampleSets
{
    /** 既定のセット（今までのインストーラーが入れていたもの） */
    public const string DEFAULT = 'zakka';

    /** @var array<string, class-string<SampleSet>> */
    private const array SETS = [
        'zakka' => ZakkaSamples::class,
        'sweets' => SweetsSamples::class,
        'fashion' => FashionSamples::class,
        'botanica' => BotanicaSamples::class,
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::SETS);
    }

    public static function has(string $name): bool
    {
        return array_key_exists($name, self::SETS);
    }

    /**
     * @throws InvalidArgumentException 無いセット
     */
    public static function get(string $name): SampleSet
    {
        $class = self::SETS[$name] ?? throw new InvalidArgumentException("サンプルのセットがありません: {$name}（".implode('・', self::names()).'）');

        return new $class;
    }

    /**
     * 印の名前（$kind は category・listing・media・theme・legal・shipping）
     */
    public static function key(SampleSet $set, string $kind, ?string $part = null): string
    {
        $head = $set->name() === self::DEFAULT ? $kind : "{$set->name()}_{$kind}";

        return $part === null ? $head : "{$head}:{$part}";
    }

    /**
     * 印の名前から、どのセットの印か（前に名前の無い印は雑貨）
     */
    public static function ofKey(string $key): string
    {
        $head = explode(':', $key)[0];

        return str_contains($head, '_') ? explode('_', $head)[0] : self::DEFAULT;
    }

    /**
     * 印の名前から、セットの名前を外した種類（listing・theme など）
     */
    public static function kindOf(string $key): string
    {
        $head = explode(':', $key)[0];

        return str_contains($head, '_') ? substr($head, strrpos($head, '_') + 1) : $head;
    }
}
