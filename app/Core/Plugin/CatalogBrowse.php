<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\Plugin\Catalog\CatalogBand;
use App\Core\Plugin\Catalog\CatalogTag;
use App\Core\Plugin\Contracts\CatalogBrowseHandler;
use App\Core\Plugin\Services\PluginStates;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ショップの一覧の並びと絞り込みの核の側。口（CatalogBrowseHandler）を持つ App に聞く窓口。
 *
 * 核が持つのは「決まった列で並べる・決まった列で絞る」だけで、何を出すかは App が決める（店の設定は App が持つ）。
 * App の答えはここで確かめて削る（数・長さ・形・商品の ID の形）。App が落ちたら絞り込みも並べ替えも出さない
 * （一覧は今までの新着順・価格順のまま出す。一覧が 500 になるより、絞り込みが出ない方がよい）。
 * 絞り込みの値は、ここが返した key と同じ文字しか App に渡さない（お客さんの入力から列や SQL を作らない）
 */
final class CatalogBrowse
{
    /** 店が手で並びを決められる商品の数（これを超えた分は今までの並び） */
    public const int MAX_ORDER = 500;

    /** 絞り込みの選択肢の数（値段の帯・タグのそれぞれ） */
    public const int MAX_OPTIONS = 20;

    /** 1 つのタグで絞り込める商品の数（ショップの読み出しのたびに渡すので、数を抑える） */
    public const int MAX_TAGGED = 1000;

    /** 選択肢の名前の長さ */
    public const int LABEL_MAX = 24;

    /** 選択肢の key（URL に出る）の形 */
    private const string KEY = '/\A[a-z0-9][a-z0-9-]{0,23}\z/';

    /** 商品の ID の形（小文字の ULID） */
    private const string ID = '/\A[0-9a-hjkmnp-tv-z]{26}\z/';

    /**
     * 店 => 覚えた答え（同じ窓口では読み直さない。一覧の 1 画面で、同じ設定と同じ並びを何度も引かないため）
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    /**
     * 並びと絞り込みの口を持つ App（入っていない・この店で使っていない・店の文脈の外なら null）
     */
    private function handler(): ?CatalogBrowseHandler
    {
        $plugin = $this->plugins->catalogBrowsePlugin();

        if ($plugin?->catalogBrowse === null || tenant() === null) {
            return null;
        }

        $enabled = $this->remember('enabled', fn (): bool => $this->states->enabled($plugin->name));

        return $enabled === true ? $plugin->catalogBrowse : null;
    }

    /**
     * 一度読んだ答えを、同じ店の間だけ覚える（店が変わったら読み直す）
     *
     * @template T
     *
     * @param  callable(): T  $make
     * @return T
     */
    private function remember(string $key, callable $make): mixed
    {
        $key = (string) tenant()?->getTenantKey().'|'.$key;

        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $make();
        }

        /** @var T */
        return $this->memo[$key];
    }

    /**
     * 店が手で決めた並び（商品の ID。先頭から MAX_ORDER 件まで。無ければ空）
     *
     * @return list<string>
     */
    public function order(): array
    {
        return $this->remember('order', function (): array {
            $ids = $this->ask(static fn (CatalogBrowseHandler $handler): array => $handler->order(), '店の並びを読めなかった') ?? [];

            return array_slice($this->listingIds($ids), 0, self::MAX_ORDER);
        });
    }

    /**
     * 一覧のはじめの並びを「おすすめ順」にするか（店の並びがある時だけ効く）
     */
    public function shopOrderFirst(): bool
    {
        return $this->remember('order_first', fn (): bool => $this->ask(static fn (CatalogBrowseHandler $handler): bool => $handler->firstSortIsShopOrder(), '店の並びの設定を読めなかった') === true);
    }

    /**
     * お客さんに出す値段の帯（出さないなら空）
     *
     * @return list<CatalogBand>
     */
    public function bands(): array
    {
        return $this->remember('bands', fn (): array => $this->ask(static function (CatalogBrowseHandler $handler): array {
            $out = [];

            foreach ($handler->bands() as $band) {
                $label = self::label($band->label);

                if (preg_match(self::KEY, $band->key) !== 1 || $label === null || isset($out[$band->key])
                    || $band->min < 0 || ($band->max !== null && $band->max <= $band->min)) {
                    continue;
                }

                $out[$band->key] = new CatalogBand($band->key, $label, $band->min, $band->max);
            }

            return array_slice(array_values($out), 0, self::MAX_OPTIONS);
        }, '値段の帯を読めなかった') ?? []);
    }

    /**
     * お客さんに出すタグ（出さないなら空）
     *
     * @return list<CatalogTag>
     */
    public function tags(): array
    {
        return $this->remember('tags', fn (): array => $this->ask(static function (CatalogBrowseHandler $handler): array {
            $out = [];

            foreach ($handler->tags() as $tag) {
                $label = self::label($tag->label);

                if (preg_match(self::KEY, $tag->key) !== 1 || $label === null || isset($out[$tag->key])) {
                    continue;
                }

                $out[$tag->key] = new CatalogTag($tag->key, $label);
            }

            return array_slice(array_values($out), 0, self::MAX_OPTIONS);
        }, 'タグを読めなかった') ?? []);
    }

    /**
     * 「在庫あり」の絞り込みを出すか
     */
    public function showsInStock(): bool
    {
        return $this->remember('stock', fn (): bool => $this->ask(static fn (CatalogBrowseHandler $handler): bool => $handler->showsInStock(), '在庫の絞り込みの設定を読めなかった') === true);
    }

    /**
     * そのタグを付けた商品の ID（MAX_TAGGED 件まで）。$tag は tags() が返した key だけ（ほかは空）
     *
     * @return list<string>
     */
    public function taggedListings(string $tag): array
    {
        $known = array_map(static fn (CatalogTag $t): string => $t->key, $this->tags());

        if (! in_array($tag, $known, true)) {
            return [];
        }

        return $this->remember('tagged|'.$tag, function () use ($tag): array {
            $ids = $this->ask(static fn (CatalogBrowseHandler $handler): array => $handler->taggedListings($tag), 'タグの商品を読めなかった') ?? [];

            return array_slice($this->listingIds($ids), 0, self::MAX_TAGGED);
        });
    }

    /**
     * App に聞く（口が無い・App が落ちたら null）
     *
     * @template T
     *
     * @param  callable(CatalogBrowseHandler): T  $ask
     * @return T|null
     */
    private function ask(callable $ask, string $message): mixed
    {
        $handler = $this->handler();

        if ($handler === null) {
            return null;
        }

        try {
            return $ask($handler);
        } catch (Throwable $e) {
            Log::error($message, ['error' => $e::class]);

            return null;
        }
    }

    /**
     * 商品の ID だけを、順を変えずに重なりなく（形の違うもの・文字でないものは捨てる。App の答えをそのまま信じない）
     *
     * @param  array<mixed>  $ids
     * @return list<string>
     */
    private function listingIds(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_string($id) && preg_match(self::ID, $id) === 1)));
    }

    /**
     * 選択肢の名前（空・制御文字・長すぎるものは断る。切らずに捨てるのは、途中で切れた名前を出さないため）
     */
    private static function label(string $label): ?string
    {
        $label = trim($label);

        return $label === '' || mb_strlen($label) > self::LABEL_MAX || preg_match('/[\p{Cc}\p{Cf}]/u', $label) === 1 ? null : $label;
    }
}
