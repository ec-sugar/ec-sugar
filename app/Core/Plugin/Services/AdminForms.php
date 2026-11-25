<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Listing\Description\ProductDescription;
use App\Core\Media\Models\ShopMedia;
use App\Core\Plugin\AdminPage\AdminField;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\AdminPage\FieldType;
use App\Core\Plugin\Contracts\AdminFormHandler;
use App\Core\Plugin\Contracts\AdminFormWording;
use App\Core\Plugin\PluginDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * App の画面で記録を足す・直す（ADR 0051 の口の続き）。店の文脈の中だけで使う。
 * 出すのは、この店で使っている App のうち、画面の係が AdminFormHandler も実装しているものだけ（ほかは同じ 404。AdminPages::definition）。
 * 足すボタンの文字が null の係は「直すだけ」（足す欄を出さず、足す経路は 404）。
 *
 * 核が受け持つのは、欄の宣言の確かめ（核の形だけ。合わなければ開発の誤り）と、値の形の確かめ（文の長さ・改行と制御文字・選択肢・真偽）。
 * 書かなかった欄は、足すときは最初の値、直すときは今の値で埋め、全部の欄がそろった値を係に渡す。
 * 欄をまたぐ決まりと保存（連鎖のロック・行のロック・イベント）は係が受け持つ。
 *
 * 写真・ブロック・日時の欄（ADR 0072、App「お知らせ・ブログ」）: 係との間の値はどれも文字か null のまま（口の型を変えない）。
 * 写真は店の写真の ID（小文字の ULID。今の店の写真にあるものだけ）、ブロックは核の ProductDescription で確かめて形をそろえた並びの JSON
 * （空の並びは null）、日時は UTC の ISO 8601（Y-m-d\TH:i:s\Z。分まで）。画面へはブロックだけ並びに戻して渡す
 */
final class AdminForms
{
    private const int MAX_FIELDS = 20;

    private const int LABEL_MAX = 40;

    // 1 行の文で断る文字: 制御文字（改行を含む）と、文字の向きを変える印（見た目と中身をずらす）
    private const string LINE_UNSAFE = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    // 何行かの文で断る文字: 改行（\n）とタブのほかの制御文字と、文字の向きを変える印
    private const string BLOCK_UNSAFE = '/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    // 日時の欄で受け取る形（ISO 8601。時差の印が要る。画面は手元の時刻を UTC に直して送る）
    private const string DATETIME = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})\z/';

    /** 係との間の日時の形（UTC） */
    public const string DATETIME_FORMAT = 'Y-m-d\TH:i:s\Z';

    private const string ULID = '/\A[0-9a-hjkmnp-tv-z]{26}\z/';

    public function __construct(private readonly AdminPages $pages) {}

    /**
     * 足す欄（最初の値つき）。足す口の無い App は null
     *
     * @return array{label: string, fields: list<array<string, mixed>>, values: array<string, mixed>}|null
     */
    public function blank(string $plugin): ?array
    {
        [$definition, $handler] = $this->handler($plugin);

        $label = $handler?->createLabel();

        // 直すだけの係（足すボタンの文字が null）は足す欄を出さない
        if ($handler === null || $label === null) {
            return null;
        }

        $fields = $this->fields($definition, $handler->fields());

        return $this->present($definition, $label, $fields, $this->defaults($fields));
    }

    /**
     * 直す欄（今の値つき）。足す口の無い App・直せない記録は null。欄の言葉を決める係（AdminFormWording）なら wording（見出し・ボタン・知らせ）も
     *
     * @return array{label: string, fields: list<array<string, mixed>>, values: array<string, mixed>, wording?: array{title: string, submit: string, done: string}}|null
     */
    public function of(string $plugin, string $id): ?array
    {
        [$definition, $handler] = $this->handler($plugin);

        if ($handler === null) {
            return null;
        }

        $fields = $this->fields($definition, $handler->fields());
        $current = $handler->values($id);

        if ($current === null) {
            return null;
        }

        // 直すだけの係の欄は「直す」の名前で渡す（画面は直す欄の名前を使わない）
        $form = $this->present($definition, $handler->createLabel() ?? '編集', $fields, $this->known($fields, $current));

        // 欄の言葉を決める係（返信を送る欄など）は、見出し・ボタン・送った後の知らせも渡す（ほかの係の答えの形は変えない）
        if ($handler instanceof AdminFormWording) {
            $wording = ['title' => $handler->editTitle(), 'submit' => $handler->editSubmit(), 'done' => $handler->editDone()];

            foreach ($wording as $text) {
                if (! self::fits($text, self::LABEL_MAX) || preg_match('/\p{C}/u', $text) === 1) {
                    throw new LogicException("App {$definition->name}: 直す欄の言葉は 1〜".self::LABEL_MAX.' 文字の 1 行');
                }
            }

            $form['wording'] = $wording;
        }

        return $form;
    }

    /**
     * ほかの口（書き出し・取り込みの欄。AdminFiles）の欄を、画面に渡す形にする（最初の値つき）。
     * 欄の宣言の決まりは足す・直すの欄と同じ（合わなければ開発の誤り）
     *
     * @param  list<AdminField>  $fields
     * @return array{label: string, fields: list<array<string, mixed>>, values: array<string, mixed>}
     */
    public function blankOf(PluginDefinition $plugin, string $label, array $fields): array
    {
        $declared = self::withoutBlocks($plugin, $this->fields($plugin, $fields));

        return $this->present($plugin, $label, $declared, $this->defaults($declared));
    }

    /**
     * ほかの口の欄の値を確かめ、全部の欄がそろった値を返す（書かなかった欄は最初の値で埋める）
     *
     * @param  list<AdminField>  $fields
     * @param  array<mixed>  $input
     * @return array<string, bool|string|null>
     *
     * @throws ValidationException 欄に合わない値（values.<名前>）
     */
    public function valuesOf(PluginDefinition $plugin, array $fields, array $input): array
    {
        $declared = self::withoutBlocks($plugin, $this->fields($plugin, $fields));

        return $this->check($declared, $input, $this->defaults($declared));
    }

    /**
     * 足して、新しい記録の ID を返す
     *
     * @param  array<mixed>  $input  欄の名前 => 値
     *
     * @throws AdminRecordNotFound 足す口の無い App
     * @throws ValidationException 欄に合わない値（values.<名前>）
     */
    public function create(string $plugin, array $input, string $actor): string
    {
        [$definition, $handler] = $this->handler($plugin);

        // 足す口の無い App と、直すだけの係は同じ 404
        if ($handler === null || $handler->createLabel() === null) {
            throw new AdminRecordNotFound;
        }

        $fields = $this->fields($definition, $handler->fields());
        $values = $this->check($fields, $input, $this->defaults($fields));
        $id = self::forward(static fn (): string => $handler->create($values, $actor));

        if (preg_match('/\A[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}\z/', $id) !== 1) {
            throw new LogicException("App {$definition->name}: 足した記録の ID は ULID");
        }

        return $id;
    }

    /**
     * 直す
     *
     * @param  array<mixed>  $input  欄の名前 => 値
     *
     * @throws AdminRecordNotFound 足す口の無い App・無い記録
     * @throws ValidationException 欄に合わない値（values.<名前>）
     */
    public function update(string $plugin, string $id, array $input, string $actor): void
    {
        [$definition, $handler] = $this->handler($plugin);
        $current = $handler?->values($id);

        if ($handler === null || $current === null) {
            throw new AdminRecordNotFound;
        }

        $fields = $this->fields($definition, $handler->fields());
        $values = $this->check($fields, $input, $this->known($fields, $current));
        self::forward(static function () use ($handler, $id, $values, $actor): void {
            $handler->update($id, $values, $actor);
        });
    }

    /**
     * @return array{PluginDefinition, ?AdminFormHandler}
     */
    private function handler(string $plugin): array
    {
        $definition = $this->pages->definition($plugin);

        return [$definition, $definition->adminPage instanceof AdminFormHandler ? $definition->adminPage : null];
    }

    /**
     * 係の欄の宣言を確かめる（App の作り間違いなので、合わなければ開発の誤りとして止める）
     *
     * @param  list<AdminField>  $fields
     * @return array<string, AdminField>
     */
    private function fields(PluginDefinition $plugin, array $fields): array
    {
        if ($fields === [] || count($fields) > self::MAX_FIELDS) {
            throw new LogicException("App {$plugin->name}: 入力の欄は 1〜".self::MAX_FIELDS.' 個');
        }

        $out = [];

        foreach ($fields as $field) {
            $wrong = match (true) {
                preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $field->key) !== 1 => '名前は英小文字・数字・_ の 32 文字まで',
                isset($out[$field->key]) => '名前が重なっています',
                ! self::fits($field->label, self::LABEL_MAX) => '名前は 1〜'.self::LABEL_MAX.' 文字',
                $field->help !== null && ! self::fits($field->help, 200) => '案内は 1〜200 文字',
                $field->type === FieldType::Text && ($field->max < 1 || $field->max > FieldType::TEXT_MAX) => '1 行の文の長さの上限は 1〜'.FieldType::TEXT_MAX,
                $field->type === FieldType::Textarea && ($field->max < 1 || $field->max > FieldType::TEXTAREA_MAX) => '何行かの文の長さの上限は 1〜'.FieldType::TEXTAREA_MAX,
                $field->type === FieldType::Choice && ($field->options === [] || count($field->options) > FieldType::OPTIONS_MAX) => '選択肢は 1〜'.FieldType::OPTIONS_MAX.' 個',
                $field->type === FieldType::Choice && ! self::optionsFit($field->options) => '選択肢の値と名前は 1〜200 文字',
                $field->type === FieldType::Choice && $field->default !== null && ! (is_string($field->default) && isset($field->options[$field->default])) => '最初の値は選択肢のどれか',
                $field->type === FieldType::Toggle && $field->default !== null && ! is_bool($field->default) => '切り替えの最初の値は真偽',
                default => null,
            };

            if ($wrong !== null) {
                throw new LogicException("App {$plugin->name}: 入力の欄 {$field->key}: {$wrong}");
            }

            $out[$field->key] = $field;
        }

        return $out;
    }

    /**
     * 画面に渡す形。選択肢は並びを保つため [値, 名前] の並びで渡す（JavaScript のオブジェクトは数のような鍵を先に並べ替えるので）
     *
     * @param  array<string, AdminField>  $fields
     * @param  array<string, bool|string|null>  $values
     * @return array{label: string, fields: list<array<string, mixed>>, values: array<string, mixed>}
     */
    private function present(PluginDefinition $plugin, string $label, array $fields, array $values): array
    {
        if (! self::fits($label, self::LABEL_MAX)) {
            throw new LogicException("App {$plugin->name}: ボタンの文字は 1〜".self::LABEL_MAX.' 文字');
        }

        return [
            'label' => $label,
            'fields' => array_values(array_map(static fn (AdminField $f): array => array_filter([
                'key' => $f->key,
                'type' => $f->type->value,
                'label' => $f->label,
                'help' => $f->help,
                'required' => $f->required,
                'max' => in_array($f->type, [FieldType::Text, FieldType::Textarea], true) ? $f->max : null,
                'options' => $f->type === FieldType::Choice ? array_map(static fn (string $value, string $name): array => [$value, $name], array_keys($f->options), $f->options) : null,
                // 最初の値のある選ぶ欄は「選ばない」を出さない（画面の部品の決まり）
                'default' => $f->type === FieldType::Choice ? $f->default : null,
                // ブロックの欄は、画面のエディタの上限（守るのはサーバー。商品の説明と同じ値）
                'limits' => $f->type === FieldType::Blocks ? ProductDescription::blockLimits() : null,
            ], static fn (mixed $v): bool => $v !== null), $fields)),
            // ブロックの欄だけは、係との間の JSON の文字を並びに戻して渡す（空は空の並び）
            'values' => self::presentValues($fields, $values),
        ];
    }

    /**
     * 足すときの最初の値（文は空、切り替えは偽か宣言の値、選ぶは宣言の値か空）
     *
     * @param  array<string, AdminField>  $fields
     * @return array<string, bool|string|null>
     */
    private function defaults(array $fields): array
    {
        return array_map(static fn (AdminField $f): bool|string|null => match ($f->type) {
            FieldType::Toggle => is_bool($f->default) ? $f->default : false,
            FieldType::Choice => is_string($f->default) ? $f->default : null,
            default => null,
        }, $fields);
    }

    /**
     * 係が返した今の値を、宣言した欄のものだけにする（形の合わない値は空。選択肢から外れた値も空）
     *
     * @param  array<string, AdminField>  $fields
     * @param  array<string, bool|string|null>  $current
     * @return array<string, bool|string|null>
     */
    private function known(array $fields, array $current): array
    {
        $out = [];

        foreach ($fields as $key => $field) {
            $value = $current[$key] ?? null;
            $out[$key] = $value !== null && self::problem($field, $value) === null ? $value : ($field->type === FieldType::Toggle ? false : null);
        }

        return $out;
    }

    /**
     * 値の形を確かめ、書かなかった欄は $base で埋める。文は前後の空白を落とし、改行は \n にそろえ、空は null
     *
     * @param  array<string, AdminField>  $fields
     * @param  array<mixed>  $input
     * @param  array<string, bool|string|null>  $base
     * @return array<string, bool|string|null>
     */
    private function check(array $fields, array $input, array $base): array
    {
        $errors = [];

        if (count($input) > count($fields)) {
            throw ValidationException::withMessages(['values' => '項目が多すぎます。']);
        }

        foreach (array_keys($input) as $key) {
            if (! is_string($key) || ! isset($fields[$key])) {
                $errors['values'] = '使えない項目があります。';
            }
        }

        $values = [];

        foreach ($fields as $key => $field) {
            $value = array_key_exists($key, $input) ? $input[$key] : ($base[$key] ?? null);

            if (is_string($value) && in_array($field->type, [FieldType::Text, FieldType::Textarea], true)) {
                $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
            }

            // ブロックの並びは核の決まりで確かめ、形をそろえた JSON の文字にする（今の値〔JSON の文字〕で埋めたときも読み直して確かめる）
            if ($field->type === FieldType::Blocks) {
                // 画面から来るのは並びか null だけ（JSON の文字を受けるのは、係の今の値で埋めたときだけ）
                if (array_key_exists($key, $input) && $value !== null && ! is_array($value)) {
                    $errors["values.{$key}"] = 'ブロックの形式が正しくありません。';

                    continue;
                }

                try {
                    $values[$key] = self::blocksValue($value);
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $path => $messages) {
                        $errors[preg_replace('/\Adescription_blocks/', "values.{$key}", $path) ?? "values.{$key}"] = $messages[0];
                    }

                    continue;
                }

                if ($field->required && $values[$key] === null) {
                    $errors["values.{$key}"] = "「{$field->label}」を入力してください。";
                }

                continue;
            }

            // 写真の ID は小文字にそろえ、日時は係との間の形（UTC・分まで）にする（形の違う値はそのまま。下で断る）
            if ($field->type === FieldType::Media && is_string($value)) {
                $value = strtolower(trim($value));
            }

            if ($field->type === FieldType::DateTime && is_string($value) && trim($value) !== '') {
                $value = self::moment(trim($value)) ?? $value;
            }

            $value = $value === '' ? null : $value;
            $problem = match (true) {
                $field->type === FieldType::Toggle && ! is_bool($value) => 'オン・オフの値が正しくありません。',
                $value === null => $field->required ? ($field->type === FieldType::Choice ? "「{$field->label}」を選んでください。" : "「{$field->label}」を入力してください。") : null,
                default => self::problem($field, $value),
            };

            if ($problem !== null) {
                $errors["values.{$key}"] = $problem;

                continue;
            }

            /** @var bool|string|null $value */
            $values[$key] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /**
     * 1 つの値が欄に合わないときの一言（合えば null。null は呼ぶ側で扱う）
     */
    private static function problem(AdminField $field, mixed $value): ?string
    {
        return match ($field->type) {
            FieldType::Media => is_string($value) && preg_match(self::ULID, $value) === 1 && ShopMedia::query()->whereKey($value)->exists() ? null : '写真が見つかりません。選び直してください。',
            FieldType::DateTime => is_string($value) && self::moment($value) === $value ? null : '日時の形式が正しくありません。',
            FieldType::Blocks => is_string($value) && json_validate($value) ? null : 'ブロックの形式が正しくありません。',
            FieldType::Toggle => is_bool($value) ? null : 'オン・オフの値が正しくありません。',
            FieldType::Choice => is_string($value) && isset($field->options[$value]) ? null : '選択肢から選んでください。',
            FieldType::Text, FieldType::Textarea => match (true) {
                ! is_string($value) || ! mb_check_encoding($value, 'UTF-8') => '文字で入力してください。',
                mb_strlen($value) > $field->max => "{$field->max} 文字以内で入力してください。",
                preg_match($field->type === FieldType::Text ? self::LINE_UNSAFE : self::BLOCK_UNSAFE, $value) !== 0 => $field->type === FieldType::Text ? '改行や使えない文字が入っています。' : '使えない文字が入っています。',
                default => null,
            },
        };
    }

    /**
     * 画面に渡す値（ブロックの欄だけ、係との間の JSON の文字を並びに戻す。空は空の並び）
     *
     * @param  array<string, AdminField>  $fields
     * @param  array<string, bool|string|null>  $values
     * @return array<string, mixed>
     */
    private static function presentValues(array $fields, array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            $out[$key] = ($fields[$key] ?? null)?->type === FieldType::Blocks ? self::blocksOf($value) : $value;
        }

        return $out;
    }

    /**
     * 日時の値を係との間の形（UTC・分まで）にする。形の違う値・2000〜2099 年の外は null
     */
    private static function moment(string $value): ?string
    {
        if (strlen($value) > 40 || preg_match(self::DATETIME, $value) !== 1) {
            return null;
        }

        try {
            $at = CarbonImmutable::parse($value)->utc()->startOfMinute();
        } catch (Throwable) {
            return null;
        }

        return $at->year < 2000 || $at->year > 2099 ? null : $at->format(self::DATETIME_FORMAT);
    }

    /**
     * ブロックの欄の入力（画面からは並び、今の値からは JSON の文字）を確かめて、係に渡す JSON の文字にする（空の並びは null）
     *
     * @throws ValidationException 道筋（description_blocks.3.text など）ごとの誤り
     */
    private static function blocksValue(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = json_decode($value, true, 16);
        }

        $blocks = ProductDescription::validateBlocks($value ?? []);

        return $blocks === [] ? null : json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * 係との間の JSON の文字を並びにする（崩れたブロックは捨てる。ProductDescription::readBlocks）。App が描くときにも使う
     *
     * @return list<array<string, mixed>>
     */
    public static function blocksOf(mixed $value): array
    {
        return is_string($value) && json_validate($value) ? ProductDescription::readBlocks(json_decode($value, true, 16)) : [];
    }

    /**
     * 書き出し・取り込みの欄にブロックは使えない（ファイルと一緒に送る形と、表の 1 つの値に収まらない）
     *
     * @param  array<string, AdminField>  $fields
     * @return array<string, AdminField>
     */
    private static function withoutBlocks(PluginDefinition $plugin, array $fields): array
    {
        foreach ($fields as $field) {
            if ($field->type === FieldType::Blocks) {
                throw new LogicException("App {$plugin->name}: 書き出し・取り込みの欄にブロックは使えない（{$field->key}）");
            }
        }

        return $fields;
    }

    /**
     * 係の誤りは欄の名前で来る。画面がほかの誤りと同じ所に出せるよう values. を付けてそろえる（file はファイルの誤りとしてそのまま）
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function forward(callable $work): mixed
    {
        try {
            return $work();
        } catch (ValidationException $e) {
            $out = [];

            foreach ($e->errors() as $key => $messages) {
                $out[$key === 'values' || $key === 'file' || str_starts_with($key, 'values.') ? $key : "values.{$key}"] = $messages;
            }

            throw ValidationException::withMessages($out);
        }
    }

    private static function fits(string $text, int $max): bool
    {
        return $text !== '' && mb_check_encoding($text, 'UTF-8') && mb_strlen($text) <= $max;
    }

    /**
     * @param  array<mixed>  $options
     */
    private static function optionsFit(array $options): bool
    {
        foreach ($options as $value => $name) {
            if (! is_string($value) || ! is_string($name) || ! self::fits($value, 200) || ! self::fits($name, 200)) {
                return false;
            }
        }

        return true;
    }
}
