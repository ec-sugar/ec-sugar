<?php

declare(strict_types=1);

namespace EcSugar\PluginWrapping;

use App\Core\Event\EventStore;
use App\Core\Money\Money;
use App\Core\Plugin\Contracts\OrderChargeHandler;
use App\Core\Plugin\OrderCharge\ChargeCart;
use App\Core\Plugin\OrderCharge\ChargeField;
use App\Core\Plugin\OrderCharge\ChargeForm;
use App\Core\Plugin\OrderCharge\ChargeQuote;
use App\Core\Plugin\OrderCharge\ChargeRejected;
use EcSugar\PluginWrapping\Models\WrappingListing;
use EcSugar\PluginWrapping\Models\WrappingOrder;
use EcSugar\PluginWrapping\Models\WrappingType;
use Illuminate\Support\Collection;

/**
 * 核の料金の口（OrderChargeHandler。ADR 0078）の、この App の実装。ラッピングとのしを注文に 1 つずつ選ぶ（品ごとには選ばない）。
 *
 * - 出すのは、カートに「ラッピングできる」「のしを付けられる」商品がある時だけ。選んだら、その商品をまとめて 1 つに包む・のしを掛ける
 * - 額は店の設定（種類の料金・のしの料金。税込み）から決め、お客さんの入力の額は使わない。行の種類は wrapping と noshi
 * - 名入れは 1 行で、店が決めた文字数まで。制御文字・文字の向きの印・幅の無い文字・< と > を断る。暗号化して持ち、記録（イベント）・行の名前・メール・ご注文の状況には入れない
 *   （行の名前には「名入れあり」とだけ。中身は確かめの画面〔本人〕・納品書・配送の CSV・管理画面の個人情報の入口にだけ出る）
 */
final class WrappingCharges implements OrderChargeHandler
{
    public const string PLUGIN = 'wrapping';

    /** 名入れの欄の一言: 送り状のソフト（Shift_JIS）で表せない文字は配送の CSV で「〓」になる（断らない。ADR 0078 の作って決めたこと） */
    public const string GLYPH_NOTE = '絵文字や一部の旧字体は、送り状では「〓」になることがあります。';

    /**
     * 名入れで断る文字: < と >、制御文字・書式の文字（向きの印・幅の無い文字・絵文字をつなぐ印も）・私用の文字・まだ決まっていない文字、行と段落の区切り、
     * 空白に見える文字（ハングルの埋め字 U+3164・U+115F・U+1160・U+FFA0、点字の空白 U+2800）と異体字セレクタ（U+FE00〜FE0F・U+E0100〜E01EF。同じに見える別の文字列を作れる）
     */
    private const string UNSAFE_NAME = '/[<>]|\p{C}|[\x{2028}\x{2029}\x{3164}\x{115F}\x{1160}\x{FFA0}\x{2800}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]/u';

    public function form(ChargeCart $cart): ?ChargeForm
    {
        [$wrappable, $noshiable] = self::eligible($cart);
        $types = $wrappable === [] ? [] : self::types()->all();
        $rules = NoshiRules::current();
        $fields = [];
        $wrapping = $types !== [];

        if ($wrapping) {
            $options = ['' => 'ラッピングしない'];
            foreach ($types as $type) {
                $options[$type->id] = $type->name.'（'.self::yen($type->price).'）';
            }

            $fields[] = new ChargeField('wrapping', 'select', 'ラッピング', self::partNote($cart, $wrappable, 'ラッピングできない'), $options);
        }

        $noshi = $rules->enabled && $noshiable !== [];

        if ($noshi) {
            $fields[] = new ChargeField('noshi', 'select', 'のし（表書き）', ($rules->fee === 0 ? 'のしは無料です。' : 'のしの料金は '.self::yen($rules->fee).' です。').(self::partNote($cart, $noshiable, 'のしを付けられない') ?? ''),
                self::titleOptions($rules->titles));
            $fields[] = new ChargeField('noshi_style', 'select', 'のしの掛け方', '外のしは包装の上に、内のしは包装の内側に掛けます。', [WrappingOrder::OUTER => '外のし', WrappingOrder::INNER => '内のし']);

            if ($rules->nameField) {
                $fields[] = new ChargeField('noshi_name', 'text', '名入れ（のしに入れるお名前）', $rules->nameMax.' 文字まで。空なら名入れなしでお付けします。'.self::GLYPH_NOTE, maxLength: $rules->nameMax);
            }
        }

        $title = self::title($wrapping, $noshi);
        $note = $wrapping ? 'ラッピングは、ご注文の商品をまとめて 1 つにお包みします。' : null;

        return $fields === [] ? null : new ChargeForm($title, $fields, $note);
    }

    public function quote(ChargeCart $cart, array $values): ChargeQuote
    {
        $choice = $this->decide($cart, $values);

        if ($choice === null) {
            return ChargeQuote::none();
        }

        [$type, $noshi, $style, $name, $rules] = $choice;
        $lines = [];
        $summary = [];

        if ($type !== null) {
            $lines[] = ['kind' => 'wrapping', 'title' => 'ラッピング（'.$type->name.'）', 'amount' => $type->price];
            $summary[] = ['name' => 'ラッピング', 'value' => $type->name.'（'.self::yen($type->price).'）'];
        }

        if ($noshi !== null) {
            $label = $noshi.'・'.WrappingOrder::STYLES[$style];
            $lines[] = ['kind' => 'noshi', 'title' => 'のし（'.$label.($name !== '' ? '・名入れあり' : '').'）', 'amount' => $rules->fee];
            $summary[] = ['name' => 'のし', 'value' => $label.'（'.self::yen($rules->fee).'）'];

            if ($name !== '') {
                $summary[] = ['name' => '名入れ', 'value' => $name];
            }
        }

        // 選んだ値と使った設定（種類の名前・料金、のしの決まり）の要約。名入れは指紋の中でもハッシュにして入れる
        $digest = 'v1:'.hash('sha256', json_encode([$type?->id, $type?->name, $type?->price, $noshi, $style, $name === '' ? '' : hash('sha256', $name), $rules->digest()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return new ChargeQuote($lines, $summary, $digest);
    }

    public function placed(string $transactionId, ChargeCart $cart, array $values, string $actor): void
    {
        $choice = $this->decide($cart, $values);

        if ($choice === null) {
            return;
        }

        [$type, $noshi, $style, $name, $rules] = $choice;
        WrappingOrder::query()->create([
            'transaction_id' => $transactionId,
            'wrapping_type_id' => $type?->id,
            'wrapping_name' => $type?->name,
            'wrapping_amount' => $type->price ?? 0,
            'noshi_title' => $noshi,
            'noshi_style' => $noshi === null ? null : $style,
            'noshi_name' => $name === '' ? null : $name,
            'noshi_amount' => $noshi === null ? 0 : $rules->fee,
        ]);

        // 記録には種類の ID・のしの有無と掛け方・名入れの有無だけ（名入れの文字は入れない）
        app(EventStore::class)->append('wrapping.recorded', 'transaction', $transactionId, [
            'wrapping_type_id' => $type?->id,
            'noshi' => $noshi !== null,
            'noshi_style' => $noshi === null ? null : $style,
            'noshi_name' => $name !== '',
        ], $actor);
    }

    public function notes(string $transactionId, bool $private): array
    {
        $row = WrappingOrder::query()->find($transactionId);

        if ($row === null) {
            return [];
        }

        $notes = [];

        if ($row->wrapping_name !== null) {
            $notes[] = ['name' => 'ラッピング', 'value' => $row->wrapping_name];
        }

        if ($row->noshi_title !== null) {
            $name = $row->noshi_name;
            $value = $row->noshi_title.'・'.(WrappingOrder::STYLES[(string) $row->noshi_style] ?? '外のし');
            $notes[] = ['name' => 'のし', 'value' => $name === null ? $value : $value.($private ? '・名入れ '.$name : '・名入れあり')];
        }

        return $notes;
    }

    /**
     * 選んだ値を店の今の設定で確かめる。何も選んでいなければ null。選べない・形の違う値は ChargeRejected（欄の鍵 => 理由）
     *
     * @param  array<string, string>  $values
     * @return array{0: ?WrappingType, 1: ?string, 2: string, 3: string, 4: NoshiRules}|null [種類, 表書き, 掛け方, 名入れ, のしの決まり]
     */
    private function decide(ChargeCart $cart, array $values): ?array
    {
        $wrapping = trim($values['wrapping'] ?? '');
        $noshi = trim($values['noshi'] ?? '');
        $style = trim($values['noshi_style'] ?? '') === '' ? WrappingOrder::OUTER : trim($values['noshi_style']);
        $name = trim($values['noshi_name'] ?? '');

        if ($wrapping === '' && $noshi === '' && $name === '') {
            return null;
        }

        [$wrappable, $noshiable] = self::eligible($cart);
        $rules = NoshiRules::current();
        $errors = [];
        $type = null;

        if ($wrapping !== '') {
            $type = preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', strtolower($wrapping)) === 1 ? WrappingType::query()->whereKey(strtolower($wrapping))->where('active', true)->first() : null;

            if ($wrappable === []) {
                $errors['wrapping'] = 'ご注文の商品はラッピングできません。「ラッピングしない」を選んでください。';
            } elseif ($type === null) {
                $errors['wrapping'] = 'このラッピングは今はお選びいただけません。選び直してください。';
            }
        }

        if ($noshi !== '') {
            if (! $rules->enabled || $noshiable === []) {
                $errors['noshi'] = 'ご注文の商品にはのしを付けられません。「のしを付けない」を選んでください。';
            } elseif (! in_array($noshi, $rules->titles, true)) {
                $errors['noshi'] = '表書きを選び直してください。';
            }

            if (! isset(WrappingOrder::STYLES[$style])) {
                $errors['noshi_style'] = 'のしの掛け方を選び直してください。';
            }
        }

        if ($name !== '') {
            $errors += ['noshi_name' => match (true) {
                $noshi === '' => 'のしの表書きを選んでから、名入れを入れてください。',
                ! $rules->enabled || ! $rules->nameField => '名入れはお受けしていません。空にしてください。',
                ! mb_check_encoding($name, 'UTF-8') || preg_match(self::UNSAFE_NAME, $name) === 1 => '名入れに使えない文字（< や >、改行・制御文字・見えない文字など）が入っています。',
                // 結合文字（濁点・アクセントなど、前の文字に重ねる印）だけで、見える文字の無い名前
                preg_match('/[^\p{M}\p{Z}]/u', $name) !== 1 => '名入れには、空白以外の文字を入れてください。',
                mb_strlen($name) > $rules->nameMax => '名入れは '.$rules->nameMax.' 文字以内で入れてください。',
                default => '',
            }];
            $errors = array_filter($errors, static fn (string $message): bool => $message !== '');
        }

        if ($errors !== []) {
            throw new ChargeRejected($errors);
        }

        return [$type, $noshi === '' ? null : $noshi, $style, $noshi === '' ? '' : $name, $rules];
    }

    /**
     * カートの中の、ラッピングできる商品とのしを付けられる商品
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function eligible(ChargeCart $cart): array
    {
        $ids = $cart->listingIds();

        if ($ids === []) {
            return [[], []];
        }

        $rows = WrappingListing::query()->whereIn('listing_id', $ids)->get();

        return [
            $rows->where('wrappable', true)->pluck('listing_id')->values()->all(),
            $rows->where('noshi', true)->pluck('listing_id')->values()->all(),
        ];
    }

    /**
     * @return Collection<int, WrappingType>
     */
    private static function types(): Collection
    {
        return WrappingType::query()->where('active', true)->orderBy('position')->orderBy('id')->limit(WrappingTypes::MAX_TYPES)->get();
    }

    /**
     * カートに選べない商品が混ざっている時の一言（「ラッピングできない商品（2 点）は、そのままお送りします。」。全部選べるなら null）
     *
     * @param  list<string>  $eligible
     */
    private static function partNote(ChargeCart $cart, array $eligible, string $what): ?string
    {
        $others = array_sum(array_map(static fn (array $item): int => in_array($item['listingId'], $eligible, true) ? 0 : $item['quantity'], $cart->items));

        return $others === 0 ? null : $what.'商品（'.$others.' 点）は、そのままお送りします。';
    }

    /**
     * 表書きの選択肢（値 => 名前）。数字だけの表書き（「100」など）も鍵を振り直さずに残す（配列を広げる ... は数の鍵を 0 から振り直す）
     *
     * @param  list<string>  $titles
     * @return array<string, string>
     */
    private static function titleOptions(array $titles): array
    {
        $options = ['' => 'のしを付けない'];

        foreach ($titles as $title) {
            $options[$title] = $title;
        }

        return $options;
    }

    private static function title(bool $wrapping, bool $noshi): string
    {
        return match (true) {
            $wrapping && $noshi => 'ラッピング・のし',
            $wrapping => 'ラッピング',
            default => 'のし',
        };
    }

    private static function yen(int $amount): string
    {
        return $amount === 0 ? '無料' : Money::format($amount);
    }
}
