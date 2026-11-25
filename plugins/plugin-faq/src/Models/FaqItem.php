<?php

declare(strict_types=1);

namespace EcSugar\PluginFaq\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * よくある質問の 1 つ（質問・答え・どの商品か〔null は全部の商品〕・並び・公開か）。書くのは FaqItems だけ（連鎖のロックとイベント）
 *
 * @property string $id
 * @property ?string $listing_id
 * @property string $question
 * @property string $answer
 * @property int $position
 * @property bool $published
 */
final class FaqItem extends Model
{
    use HasUlids;

    protected $table = 'faq_items';

    protected $fillable = ['listing_id', 'question', 'answer', 'position', 'published'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer', 'published' => 'boolean'];
    }
}
