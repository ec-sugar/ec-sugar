<?php

declare(strict_types=1);

namespace App\Core\Plugin;

/**
 * App のデータを消した結果（ADR 0092。PluginDataPurge）
 */
final readonly class PurgedData
{
    /**
     * @param  array<string, int>  $rows  表の名前 => 消した行の数
     * @param  bool  $settings  App の設定（秘密も）を消したか
     * @param  int  $files  コミットの後に消したファイルの数
     * @param  bool  $leftover  ファイルを消し終えられなかった（報告した。もう一度消すと拾う）
     */
    public function __construct(
        public array $rows,
        public bool $settings,
        public int $files,
        public bool $leftover,
    ) {}

    public function total(): int
    {
        return array_sum($this->rows);
    }

    /**
     * @return array{rows: array<string, int>, total: int, settings: bool, files: int, leftover: bool}
     */
    public function toArray(): array
    {
        return ['rows' => $this->rows, 'total' => $this->total(), 'settings' => $this->settings, 'files' => $this->files, 'leftover' => $this->leftover];
    }
}
