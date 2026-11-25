<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\ListingFile;
use App\Core\Plugin\ListingFileUpload;

/**
 * 管理画面の商品の画面の App の欄に、App が置くファイルの並び（ADR 0084。App「ダウンロード販売」の売るファイル）。
 * 欄（ListingPanelHandler）を持つ App だけが使える（同じ App の欄の下に並ぶ）。欄を決まった商品だけに出す係（ListingPanelScope）なら、ファイルも同じ商品だけ。
 *
 * 受け取りは核がする（切れ端に分けて受け、名前と大きさ・拡張子と中身の型〔MIME の判定〕・危ない形・数・店の合計を確かめてから渡す。ListingFileUploads）。
 * App は確かめた後のファイル（ListingFileUpload。置き場 downloads の上げる途中の場所）を自分の場所へ移し、自分の表に残す。
 * 書き込みは App が連鎖のロック（EventStore::lock()）の中で、イベントを同じトランザクションで残す。読み書きは運用の段階から（核の経路が確かめる）
 */
interface ListingFilesHandler
{
    /** 1 つの商品に置けるファイルの数（1〜10） */
    public function maxFiles(): int;

    /** 1 つのファイルの上限（バイト。核の上限 config('downloads.max_file_mb') を超える分は核が切り詰める） */
    public function maxBytes(): int;

    /** 店で今置いているファイルの合計（バイト。店の合計の上限 config('downloads.shop_quota_mb') と比べる） */
    public function usedBytes(): int;

    /**
     * 受け付ける形: 拡張子（小文字）=> その拡張子で受ける中身の型（MIME。finfo の判定）の並び。核の断る形（実行できる形・HTML・SVG など）は宣言しても受けない
     *
     * @return array<string, list<string>>
     */
    public function accepts(): array;

    /**
     * この商品に置いたファイル（置いた順）
     *
     * @return list<ListingFile>
     */
    public function files(Listing $listing): array;

    /**
     * 確かめた後のファイルを置く。数の上限（maxFiles）と店の合計は、ロックの中でもう一度確かめる（同時に上げても越えない）。
     * 越えたら Illuminate\Validation\ValidationException（file）
     */
    public function store(Listing $listing, ListingFileUpload $upload, string $actor): void;

    /**
     * ファイルを消す。この商品のファイルでなければ App\Core\Plugin\ListingPanelNotFound（404）
     */
    public function delete(Listing $listing, string $fileId, string $actor): void;
}
