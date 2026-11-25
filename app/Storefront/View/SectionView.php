<?php

declare(strict_types=1);

namespace App\Storefront\View;

use ArrayAccess;
use LogicException;

/**
 * 区画 1 つの中身（テーマ API 2。ADR 0028）。テーマは $hero['heading'] のように欄の名前で読み、繰り返しの組は $hero->items。
 * 値はショップに出してよい形に組み立て済み: 文は string|null、写真は ShopPhoto|null（置き場にあるものだけ）、リンクは href の string|null、
 * 商品の並びは list<ListingCard>（公開中のものだけ）、カテゴリの並びは list<{label, slug, photo}>、選択肢は string|null、
 * HTML（核の区画「自由な HTML」「見たまま書ける文」の欄）は核が整えた Illuminate\Support\HtmlString|null（{{ }} でそのまま出る）。
 * 宣言した欄はすべて入っている（空なら null か []）。
 *
 * ページの並び（ADR 0039）では、区画ごとに id（並びの中で重ならない）・pattern（見た目のパターン）・blocks（区画の中の小さなブロック。
 * それぞれも SectionView で、key がブロックの種類）を持つ。view は核が選んだテーマの部品の名前（部品の無い区画はページに出さない）。
 * key は区画の種類の名前（ブロックではブロックの種類の名前）。
 * App の区画（ADR 0040）では app に、核が決めた埋め込みの扱い（AppSectionView。開いた時から読み込むか・押すまで待つか）が入る。
 *
 * @implements ArrayAccess<string, mixed>
 */
final readonly class SectionView implements ArrayAccess
{
    /**
     * @param  array<string, mixed>  $values
     * @param  list<array<string, mixed>>  $items
     * @param  list<SectionView>  $blocks
     */
    public function __construct(
        public string $key,
        public array $values,
        public array $items,
        public string $id = '',
        public string $pattern = 'default',
        public array $blocks = [],
        public ?string $view = null,
        public ?AppSectionView $app = null,
    ) {}

    public function withView(?string $view): self
    {
        return new self($this->key, $this->values, $this->items, $this->id, $this->pattern, $this->blocks, $view, $this->app);
    }

    /**
     * App の区画の埋め込みの扱いを付けたもの（SectionResolver が付ける。ADR 0040）
     */
    public function withApp(AppSectionView $app): self
    {
        return new self($this->key, $this->values, $this->items, $this->id, $this->pattern, $this->blocks, $this->view, $app);
    }

    /**
     * 欄も繰り返しもすべて空（テーマは区画を出さないか、見本の文で埋める）
     */
    public function isEmpty(): bool
    {
        return $this->items === [] && array_filter($this->values, static fn (mixed $v): bool => $v !== null && $v !== []) === [];
    }

    public function offsetExists(mixed $offset): bool
    {
        // $hero['typo'] ?? '既定' の形（isset を通る）でも書き間違いに気づけるように
        return $this->declared($offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->declared($offset);
    }

    /**
     * 宣言に無い欄の名前はテーマの書き間違い。黙って空にせず、テストで気づけるようにする
     */
    private function declared(string $offset): mixed
    {
        if (! array_key_exists($offset, $this->values)) {
            throw new LogicException("セクション {$this->key} に欄 {$offset} はありません（theme.json の宣言を確認する）");
        }

        return $this->values[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new LogicException('セクションの中身は変えられません');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new LogicException('セクションの中身は変えられません');
    }
}
