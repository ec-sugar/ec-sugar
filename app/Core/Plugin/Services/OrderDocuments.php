<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\Contracts\OrderDocumentHandler;
use App\Core\Plugin\OrderDocument\OrderDocument;
use App\Core\Plugin\PluginRegistry;
use LogicException;

/**
 * App の注文の書類（納品書・領収書など）を、この店で使っている App から集める。店の文脈の中だけで使う。
 * 書類の形の誤り（名前の重複・型の違い）は App の開発の誤りとして止める。お客さんのご注文の画面のリンク（領収書など）は
 * この口でなく OrderLinkProvider（ショップの OrderLinks が集める）
 */
final class OrderDocuments
{
    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly PluginStates $states,
    ) {}

    /**
     * 管理画面の注文の画面に出す書類（App の名前の順・App の決めた順）
     *
     * @return list<array{plugin: string, key: string, label: string, bulk: bool}>
     */
    public function menu(): array
    {
        $out = [];

        foreach ($this->registry->all() as $plugin) {
            if ($plugin->orderDocuments === null || ! $this->states->enabled($plugin->name)) {
                continue;
            }

            foreach (self::documentsOf($plugin->name, $plugin->orderDocuments) as $document) {
                $out[] = ['plugin' => $plugin->name, 'key' => $document->key, 'label' => $document->label, 'bulk' => $document->bulk];
            }
        }

        return $out;
    }

    /**
     * 使っている App の書類の係と種類。無い・使っていない・その書類の無い App は同じ 404（AdminRecordNotFound）
     *
     * @return array{0: OrderDocumentHandler, 1: OrderDocument}
     */
    public function find(string $plugin, string $document): array
    {
        if (! $this->registry->has($plugin)) {
            throw new AdminRecordNotFound;
        }

        $handler = $this->registry->get($plugin)->orderDocuments;

        if ($handler === null || ! $this->states->enabled($plugin)) {
            throw new AdminRecordNotFound;
        }

        foreach (self::documentsOf($plugin, $handler) as $found) {
            if ($found->key === $document) {
                return [$handler, $found];
            }
        }

        throw new AdminRecordNotFound;
    }

    /**
     * @return list<OrderDocument>
     */
    private static function documentsOf(string $plugin, OrderDocumentHandler $handler): array
    {
        $documents = $handler->documents();
        $keys = [];

        foreach ($documents as $document) {
            if (isset($keys[$document->key])) {
                throw new LogicException("App {$plugin} の注文の書類の名前「{$document->key}」が重なっています");
            }

            $keys[$document->key] = true;
        }

        return $documents;
    }
}
