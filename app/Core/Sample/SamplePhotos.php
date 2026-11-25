<?php

declare(strict_types=1);

namespace App\Core\Sample;

use App\Core\Media\ImageProcessor;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * サンプルの写真を、入れる時に Unsplash から取る（配る物に写真を入れない）。
 *
 * - 取りに行くのは unsplash.com と images.unsplash.com の https だけ。リダイレクトは自分でたどり、行き先も同じ決まりで確かめる（3 回まで）
 * - 時間切れ（つなぐ 5 秒・全体 20 秒）、大きさの上限（画像の処理と同じ 10 MB。Content-Length と、読んだ量の両方で）、
 *   形（Content-Type が JPEG・PNG・WebP で、読んだ中身も画像として読める）を確かめる。どれかが駄目ならその写真は取らない
 * - 取った物は一時フォルダに `<名前>.<拡張子>` で置くだけ。そのまま使わず、入れる時に今の画像の処理（作り直し）に通す
 * - 1 枚取れなくても止めない（その写真なしで入れる）。取れなかった写真と理由を返す
 *
 * @phpstan-import-type PhotoSource from SampleSet
 */
final class SamplePhotos
{
    /** 取りに行ってよいホスト */
    public const array HOSTS = ['unsplash.com', 'images.unsplash.com'];

    /** 1 枚の上限（画像の処理が受け付ける大きさと同じ） */
    public const int MAX_BYTES = ImageProcessor::MAX_BYTES;

    /** 取る大きさ（長い辺。ショップの写真の大きいものと同じ） */
    public const int WIDTH = 2000;

    private const int MAX_REDIRECTS = 3;

    private const int TIMEOUT = 20;

    private const int CONNECT_TIMEOUT = 5;

    /** Content-Type => 拡張子 */
    private const array TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * 写真をまとめて取る。$directory は呼ぶ側が作った空の一時フォルダ
     *
     * @param  array<string, PhotoSource>  $sources  写真の名前 => 出どころ
     * @return array<string, string> 取れなかった写真の名前 => 理由
     */
    public function fetch(array $sources, string $directory): array
    {
        $failed = [];

        foreach ($sources as $name => $source) {
            if (preg_match('/\A[a-z0-9-]+\z/', $name) !== 1) {
                $failed[$name] = '写真の名前の形が違います';

                continue;
            }

            try {
                $this->download(self::url($source), $directory, $name);
            } catch (RuntimeException $e) {
                $failed[$name] = $e->getMessage();
            }
        }

        return $failed;
    }

    /**
     * 出どころから取りに行く URL。写真の ID は Unsplash の取り出しの入口（images.unsplash.com へリダイレクトされる）、URL は大きさの指定を付ける
     *
     * @param  PhotoSource  $source
     *
     * @throws RuntimeException 出どころの形が違う
     */
    public static function url(array $source): string
    {
        $id = $source['id'] ?? null;
        $url = $source['url'] ?? null;

        if (is_string($id) && preg_match('/\A[A-Za-z0-9_-]{6,32}\z/', $id) === 1) {
            return "https://unsplash.com/photos/{$id}/download?force=true&w=".self::WIDTH;
        }

        if (is_string($url) && preg_match('#\Ahttps://images\.unsplash\.com/photo-[0-9]+-[0-9a-f]+\z#', $url) === 1) {
            return "{$url}?w=".self::WIDTH.'&fm=jpg&q=80';
        }

        throw new RuntimeException('写真の取得元の形が違います');
    }

    /**
     * 1 枚を取って置く。リダイレクトは行き先を確かめてからたどる
     *
     * @throws RuntimeException 取れない（理由の文つき）
     */
    private function download(string $url, string $directory, string $name): void
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            self::assertAllowed($url);

            try {
                $response = Http::timeout(self::TIMEOUT)->connectTimeout(self::CONNECT_TIMEOUT)
                    ->withoutRedirecting()
                    ->withOptions(['stream' => true])
                    ->accept('image/jpeg, image/png, image/webp')
                    ->get($url);
            } catch (ConnectionException|RequestException|GuzzleException) {
                throw new RuntimeException('接続できませんでした（時間切れか、外へ出られない）');
            }

            if ($response->redirect()) {
                $url = $response->header('Location');

                if ($url === '') {
                    throw new RuntimeException('リダイレクトの行き先がありません');
                }

                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException("取得元の応答が {$response->status()} でした");
            }

            $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
            $extension = self::TYPES[$type] ?? throw new RuntimeException('画像ではありません（'.($type === '' ? '形の知らせなし' : $type).'）');
            $length = $response->header('Content-Length');

            if ($length !== '' && ctype_digit($length) && (int) $length > self::MAX_BYTES) {
                throw new RuntimeException('大きすぎます（上限 '.intdiv(self::MAX_BYTES, 1024 * 1024).' MB）');
            }

            $this->store($response->toPsrResponse()->getBody(), "{$directory}/{$name}.{$extension}");

            return;
        }

        throw new RuntimeException('リダイレクトが多すぎます');
    }

    /**
     * 読みながら書き、上限を超えたら止めて消す。書き終えたら画像として読めるかを確かめる
     *
     * @throws RuntimeException
     */
    private function store(StreamInterface $body, string $path): void
    {
        $file = fopen($path, 'xb');

        if ($file === false) {
            throw new RuntimeException('写真を置けませんでした');
        }

        $bytes = 0;

        // 読みかけの本文（同じ応答を使い回すテストの偽物など）も頭から
        if ($body->isSeekable()) {
            $body->rewind();
        }

        try {
            while (! $body->eof()) {
                $chunk = $body->read(65_536);
                $bytes += strlen($chunk);

                if ($bytes > self::MAX_BYTES) {
                    throw new RuntimeException('大きすぎます（上限 '.intdiv(self::MAX_BYTES, 1024 * 1024).' MB）');
                }

                fwrite($file, $chunk);
            }
        } catch (RuntimeException $e) {
            fclose($file);
            @unlink($path);

            throw $e;
        }

        fclose($file);
        $info = $bytes > 0 ? @getimagesize($path) : false;

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            @unlink($path);

            throw new RuntimeException('画像として読めません');
        }
    }

    /**
     * 行き先が https の決まったホストか（リダイレクトの行き先も）
     *
     * @throws RuntimeException
     */
    private static function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! in_array($host, self::HOSTS, true)) {
            throw new RuntimeException('決まった取得元（unsplash.com・images.unsplash.com）の外へは取りに行きません（'.($host === '' ? '行き先が読めない' : $host).'）');
        }
    }
}
