<?php

declare(strict_types=1);

namespace App\Storefront\Apps;

use App\Core\Plugin\Manifest\ConsentKind;

/**
 * 同意の帯（ADR 0034 の 4）。核が描く。まだ決めていない種類があれば見せ、決めた後も隠して置く（フッターの「Cookie の設定」で開き直せる）
 */
final readonly class ConsentBanner
{
    /**
     * @param  list<ConsentKind>  $kinds  この店で同意が要る種類
     * @param  bool  $open  まだ決めていない種類がある（見せる）
     * @param  string  $returnPath  決めた後に戻る道筋（同じオリジンの道筋だけ）
     */
    public function __construct(
        public array $kinds,
        public bool $open,
        public string $returnPath,
    ) {}

    /**
     * 「アクセス解析と広告の計測」のような文
     */
    public function purposes(): string
    {
        return implode('と', array_map(static fn (ConsentKind $kind): string => $kind->label(), $this->kinds));
    }
}
