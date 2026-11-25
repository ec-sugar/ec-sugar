<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Demo\DemoRefused;
use App\Admin\Demo\DemoShops;
use App\Admin\Services\ShopNames;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * デモの店の中身を入れる（ADR 0086）。先に shop:create で店を作り、shop:demo <店> --on で印を付けてから流す。
 *
 *   php artisan demo:shop sake --tenant=demosake --photos=/var/www/html/storage/app/demo-photos/sake
 *   php artisan demo:shop coffee --tenant=democoffee --name="焙煎所の名前"
 *
 * 扱いは sake（酒蔵）・coffee（コーヒー焙煎所）・wagashi（和菓子店）・livehouse（ライブハウス）。
 * 店・テーマ・ページの区画・商品・カテゴリ・App とその設定・クーポン・お知らせ・特商法の表記を作り、ショップを公開する。
 * 流し直しても増えない（済んだ手順は飛ばす）。--photos が無ければ写真を Unsplash から取る（取れない写真は無しで入れる）。
 * 扱い・名前・写真のフォルダは中央の店の行に覚え、毎晩の戻し（demo:reset）が同じ形で作り直す。
 * 名前は --name を付けたときだけ変える。無ければ中央の店の今の名前（shop:create で付けた名前）のまま。
 *
 * 終わりの番号: 0 入れた・もう入っていた / 1 断った・入れ終えられなかった / 2 引数の誤り
 */
final class DemoShopCommand extends Command
{
    protected $signature = 'demo:shop
        {kind : デモの店の扱い（sake・coffee・wagashi・livehouse）}
        {--tenant= : 店の ID（デモの印のある店。demo で始まる）}
        {--name= : 店の名前を変える（無ければ今の店の名前のまま。店に名前が無いときだけ扱いの名前の候補のはじめ）}
        {--photos= : 写真を Unsplash へ取りに行かず、このフォルダの写真を使う（<名前>.jpg などを名前で合わせる）}';

    protected $description = 'デモの店（酒蔵・コーヒー焙煎所・和菓子店・ライブハウス）の中身を入れる';

    public function handle(DemoShops $shops): int
    {
        $kind = (string) $this->argument('kind');

        if (! in_array($kind, DemoShops::kinds(), true)) {
            $this->error("デモの店の扱いがありません: {$kind}（".implode('・', DemoShops::kinds()).'）');

            return self::INVALID;
        }

        $id = (string) $this->option('tenant');
        $tenant = $id === '' ? null : Tenant::query()->find($id);

        if (! $tenant instanceof Tenant) {
            $this->error('--tenant に、あるデモの店の ID を指定してください（先に shop:create と shop:demo <店> --on）');

            return self::INVALID;
        }

        // 名前は付けたときだけ変える（無い・空なら店の今の名前のまま）。形は管理画面と同じ決まり（ShopNames）
        $option = $this->option('name');
        $name = null;

        if (is_string($option) && trim($option) !== '') {
            try {
                $name = ShopNames::validated($option);
            } catch (ValidationException $e) {
                $this->error('--name: '.$e->validator->errors()->first('name'));

                return self::INVALID;
            }
        }

        $photos = null;
        $photosOption = $this->option('photos');

        if (is_string($photosOption)) {
            $photos = realpath($photosOption);

            if ($photos === false || ! is_dir($photos)) {
                $this->error("写真のフォルダがありません: {$photosOption}");

                return self::INVALID;
            }
        }

        try {
            $result = $shops->install($tenant, $kind, $name, $photos, fn (string $message) => $this->line($message));
        } catch (DemoRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error("デモの店を入れ終えられませんでした: {$e->getMessage()}");
            $this->line('入れ終えた物はそのまま残ります。原因を解消して再実行すると、残りだけを入れます');

            return self::FAILURE;
        }

        foreach ($result['missing'] as $photo => $reason) {
            $this->warn("写真 {$photo} を入れられませんでした（{$reason}）。この写真なしで入れました");
        }

        $this->info($result['added'] === 0
            ? ($result['renamed'] ? "店 {$id} のデモはもう入っています（名前だけ「{$result['name']}」に変えました）" : "店 {$id} のデモはもう入っています（何も変えていません）")
            : "店 {$id} にデモ「{$result['name']}」を入れました（{$result['added']} 件）。毎晩 demo:reset でこの状態に戻ります");

        return self::SUCCESS;
    }
}
