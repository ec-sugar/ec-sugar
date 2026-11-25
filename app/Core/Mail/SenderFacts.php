<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Sample\SampleSet;
use App\Core\Shop\Services\ShopSettingsService;

/**
 * 配信のメールに必ず載せる送り主の表示（特定電子メール法 第 4 条・施行規則 第 9 条）。店の設定の「特定商取引法に基づく表記」から読む:
 * 送り主の名前（「販売業者」など）・住所（「所在地」）・問い合わせ先（「電話番号」「メールアドレス」「お問い合わせ」など。1 つ以上）。
 * どれかが無い・入れたときの見本のままなら配信しない（missing() に足りない項目の名前）。
 *
 * お知らせ・宣伝のメールを送る App（メルマガ・カゴ落ちのお知らせ・お気に入り）が使う。
 * 同じ判定を App ごとに写していたのを核へ畳んだ（ADR 0107。向きは App → 核だけ）
 */
final readonly class SenderFacts
{
    private const string NAME = '/販売業者|事業者|販売者|会社名|屋号|名称|ショップ名|店舗名|店名/u';

    private const string ADDRESS = '/所在地|住所/u';

    private const string CONTACT = '/電話|メール|問い?合わ?せ|連絡先/u';

    /** 名前に見えても送り主ではない項目（運営統括責任者は人の名前） */
    private const string NOT_NAME = '/責任者|担当/u';

    /**
     * @param  list<string>  $contacts  「電話番号: 03-…」の形の行
     */
    public function __construct(
        public string $name,
        public string $address,
        public array $contacts,
    ) {}

    public static function current(): self
    {
        $name = '';
        $address = '';
        $contacts = [];

        foreach (app(ShopSettingsService::class)->legal() as $item) {
            $label = trim($item['label']);
            $value = trim($item['value']);

            if ($value === '' || str_contains($value, SampleSet::LEGAL_TODO) || str_starts_with($value, '（サンプル）')) {
                continue;
            }

            if ($name === '' && preg_match(self::NAME, $label) === 1 && preg_match(self::NOT_NAME, $label) !== 1) {
                $name = $value;
            } elseif ($address === '' && preg_match(self::ADDRESS, $label) === 1) {
                $address = $value;
            } elseif (preg_match(self::CONTACT, $label) === 1 && count($contacts) < 4) {
                $contacts[] = $label.': '.str_replace("\n", ' ', $value);
            }
        }

        return new self($name, $address, $contacts);
    }

    /**
     * 足りない項目の名前（そろっていれば空）
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return array_values(array_filter([
            $this->name === '' ? '販売業者' : null,
            $this->address === '' ? '所在地' : null,
            $this->contacts === [] ? '電話番号かメールアドレス' : null,
        ]));
    }
}
