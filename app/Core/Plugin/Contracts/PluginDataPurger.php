<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

/**
 * App のデータを消す口（ADR 0092）。店で App を止めた後に、店のオーナーが「この App のデータを消す」を選んだ時に核が呼ぶ。
 * 口を持たない App は消せない（管理画面にボタンが出ない）。
 *
 * - 呼ぶのは核の `PluginDataPurge` だけ。どれも店の文脈の中（その店の DB）で呼ばれる
 * - 消すのは自分の表の行と、自分の置き場のファイルだけ。表は消さない（移行は全部の店で共通。使わない店でも表はある）。核の表には触れない
 * - refusal と purgeRows は、連鎖のロック（EventStore::lock()）を取ったトランザクションの中で、App が止まっていることを確かめた後に呼ばれる。
 *   記録（`plugin.purged`）は核が同じトランザクションで残す（App は自分で記録を足さない。消した行の記録は残るので、中身は ID と数だけの決まりを守る）
 * - purgeFiles はコミットの後に呼ばれる。落ちても行はもう無いので DB と食い違わない（残ったファイルは、もう一度消すと拾う）
 */
interface PluginDataPurger
{
    /**
     * 今は消せない理由（お店の人に見せる文）。消せるなら null。
     * お客さんがまだ使う物（払ったお客さんの受け取りなど）が残っている時に断る
     */
    public function refusal(): ?string;

    /**
     * 自分の表の行を全部消す（外部キーの向きに合わせて子の表から）。
     *
     * @return array<string, int> 表の名前（App の頭で始まる）=> 消した行の数
     */
    public function purgeRows(): array;

    /**
     * 自分の置き場のうち、この店の、どの行も指していないファイルを消す（行を消した後なので、ふつうは全部）。消した数
     */
    public function purgeFiles(): int;
}
