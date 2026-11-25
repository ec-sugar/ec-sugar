<?php

declare(strict_types=1);

namespace App\Core\Plugin;

/**
 * 核が受け取って確かめたファイル（ListingFileUploads → ListingFilesHandler::store）。
 * path は置き場 downloads の中の上げる途中の場所（uploads/<店>/<ID>.part）。App は自分の場所へ移す（移さなければ核が後で捨てる）。
 * name は店の人のファイルの名前を表示用にそろえたもの（道筋の区切り・制御文字を除き、200 文字まで）。extension は小文字の拡張子、mime は中身から判定した型
 */
final readonly class ListingFileUpload
{
    public function __construct(
        public string $path,
        public string $name,
        public string $extension,
        public string $mime,
        public int $bytes,
        public string $sha256,
    ) {}
}
