<?php

declare(strict_types=1);

namespace EcSugar\PluginFaq;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * 見本のプラグイン「よくある質問」（ADR 0025）。プラグインの登録簿に自分を登録する。
 * 表（faq_items）は店の DB に。管理画面は App の画面（一覧・1 件・足す・直す・並べ替え・消す。ADR 0051）、
 * ショップは商品の画面の説明の下の開いて読む並び（核の部品が描く）。どちらも使う店だけ
 */
final class FaqServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: 'faq',
            title: 'よくある質問',
            description: '商品の画面の下に、よくある質問と答えを表示します。すべての商品に出す共通の質問と、商品ごとの質問を書けます。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（今は目的 category だけ。登録のときに核が確かめる）
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            adminPage: new FaqAdminPage,
            productPanel: new FaqProductPanel,
            // 止めた店で、オーナーが選ぶと質問を全部消す（ADR 0092）
            purger: new FaqDataPurger,
        ));
    }
}
