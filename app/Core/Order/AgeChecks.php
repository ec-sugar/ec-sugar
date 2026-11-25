<?php

declare(strict_types=1);

namespace App\Core\Order;

use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Plugin\AgeCheck\AgeAnswer;
use App\Core\Plugin\AgeCheck\AgeCheckRefused;
use App\Core\Plugin\AgeCheck\AgeRule;
use App\Core\Plugin\Contracts\AgeCheckHandler;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 年齢の確かめ（ADR 0077）の核の側。口（AgeCheckHandler）を持つ App をこの店で使っている時だけ、年齢の確かめが要る品のある注文で
 * 生年月日（入力の時に満年齢を数えて捨てる）か会員が前に確かめた年齢と同意をそろえ、注文の確定の錠の中で今の決まりと照らし直す。
 *
 * - 確かめた事実は、注文の確定と同じトランザクションのイベント order.age_verified（何歳以上で・どう確かめたか・同意した文の版・贈り物の受け取る人の同意）。
 *   生年月日はイベントにも列にもログにも入れない。App を使うのをやめても、注文の記録は残る（納品書・配送の CSV・管理画面の札はここから読む）
 * - App が落ちた時: 注文の確定では「確かめられない」として断る（年齢の要る品を確かめずに売らない）。商品の画面の一言と年齢の門は出さない（ショップは止めない）。
 *   App の読み出しは入れ子のトランザクション（savepoint）で包む。注文の確定の錠の中で App の DB の読み出しが落ちても、外側のトランザクションを中断させない
 *   （PostgreSQL は誤りの後の文を断るので、包まないと続きの書き込みが 500 になる）
 * - App を「使わない」にした店では確かめない（商品の年齢の決まりは App の表に残るが、核は聞かない）。止める前に App の説明・ヘルプ・README で知らせる
 */
final class AgeChecks
{
    public const string VERIFIED = 'order.age_verified';

    public const string UNAVAILABLE = '年齢の確認が必要な商品がありますが、現在確認できません。時間をおいて、もう一度お試しください。';

    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
        private readonly EventStore $events,
    ) {}

    /**
     * この店で使っている年齢の確かめの App の口（無ければ null）
     */
    public function handler(): ?AgeCheckHandler
    {
        $plugin = $this->plugins->ageCheckPlugin();

        return $plugin !== null && $this->states->enabled($plugin->name) ? $plugin->ageCheck : null;
    }

    /**
     * 商品の画面の一言の年齢（要らない・App を使っていない・App が落ちた時は null）
     */
    public function forListing(string $listingId): ?int
    {
        try {
            $age = $this->required([$listingId]);
        } catch (AgeCheckRefused) {
            return null;
        }

        return $age === 0 ? null : $age;
    }

    /**
     * 品の中でいちばん上の年齢（要らなければ 0）。App が落ちたら AgeCheckRefused（確かめずに売らない）
     *
     * @param  list<string>  $listingIds
     *
     * @throws AgeCheckRefused
     */
    public function required(array $listingIds): int
    {
        // 大文字の ID も小文字にそろえて比べる。形の違う ID は黙って捨てず、断る（確かめずに売らない）
        $listingIds = array_values(array_unique(array_map(strtolower(...), $listingIds)));

        foreach ($listingIds as $id) {
            if (preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) !== 1) {
                throw new AgeCheckRefused(self::UNAVAILABLE);
            }
        }

        $handler = $listingIds === [] ? null : $this->handler();

        if ($handler === null) {
            return 0;
        }

        try {
            $required = 0;

            foreach ($this->ask(static fn (): array => $handler->requirements($listingIds)) as $listingId => $age) {
                // 頼んだ商品で、選べる年齢のものだけ（App の誤りで知らない年齢を通さない）
                if (in_array(strtolower((string) $listingId), $listingIds, true) && AgeRule::isAge($age)) {
                    $required = max($required, $age);
                }
            }

            return $required;
        } catch (Throwable $e) {
            Log::error('年齢の確認の決まりを読めなかった', ['error' => $e::class]);

            throw new AgeCheckRefused(self::UNAVAILABLE, previous: $e);
        }
    }

    /**
     * 会員が前に確かめた年齢（会員でない・App を使っていない・落ちた時は 0。生年月日を聞く側に倒す）
     */
    public function remembered(?string $customerAccountId): int
    {
        $handler = $customerAccountId === null ? null : $this->handler();

        if ($handler === null) {
            return 0;
        }

        try {
            $age = $this->ask(static fn (): int => $handler->remembered((string) $customerAccountId));

            return AgeRule::isAge($age) ? $age : 0;
        } catch (Throwable $e) {
            Log::error('会員の確認した年齢を読めなかった', ['error' => $e::class]);

            return 0;
        }
    }

    /**
     * App の読み出しを入れ子のトランザクションで包む（錠の中なら savepoint。App の中の DB の誤りで、注文の確定のトランザクションを中断させない）
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    private function ask(Closure $read): mixed
    {
        return DB::transaction($read);
    }

    /**
     * 注文の確定の錠の中で照らし直す。$required 歳の品を、答え（生年月日・同意）か会員が前に確かめた年齢で売ってよいか。だめなら AgeCheckRefused。
     * $answerRequired（注文の入力を通る注文）は同意がいつも要る。カートを通さない注文で答えが無い時（定期便の回など）は、会員が前に確かめた年齢だけで決める
     *
     * @throws AgeCheckRefused
     */
    public function guard(int $required, ?AgeAnswer $answer, ?string $memberId, bool $gift, bool $answerRequired): void
    {
        if ($required === 0) {
            return;
        }

        $remembered = $this->remembered($memberId);
        $ok = $answer !== null ? $answer->satisfies($required, $remembered, $gift) : (! $answerRequired && ! $gift && $remembered >= $required);

        if (! $ok) {
            throw new AgeCheckRefused("{$required} 歳以上の方だけがお求めいただける商品があります。生年月日を入力し、「{$required} 歳以上です」にチェックを入れてから、もう一度お進みください。");
        }
    }

    /**
     * 確かめた事実を注文に残す（注文の確定のトランザクションの中、guard の後）。会員が生年月日で確かめた年齢は App が覚える（次から生年月日を聞かない）
     */
    public function record(string $transactionId, int $required, ?AgeAnswer $answer, ?string $memberId, string $actor): void
    {
        if ($required === 0) {
            return;
        }

        $byBirthdate = $answer !== null && $answer->level >= $required;
        $remember = $byBirthdate && $memberId !== null && $this->remembered($memberId) < $answer->level;
        $this->events->append(self::VERIFIED, 'transaction', $transactionId, [
            'age' => $required,
            'via' => $byBirthdate ? 'birthdate' : 'member',
            'consent' => $answer?->version,
            'recipient' => $answer !== null && $answer->recipient,
            'remembered' => $remember,
        ], $actor);

        if ($remember) {
            // 落ちたら注文ごと巻き戻す（覚えたことと記録を食い違わせない）
            $this->handler()?->remember((string) $memberId, $answer->level, $actor);
        }
    }

    /**
     * 注文ごとの確かめた事実（納品書・配送の CSV・管理画面の札）。確かめていない注文は入らない
     *
     * @param  list<string>  $transactionIds
     * @return array<string, array{age: int, recipient: bool, label: string}>
     */
    public function verifiedFor(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $out = [];

        foreach (DomainEvent::query()->where('aggregate_type', 'transaction')->whereIn('aggregate_id', $transactionIds)->where('type', self::VERIFIED)
            ->orderBy('sequence')->get(['aggregate_id', 'payload']) as $event) {
            $age = (int) ($event->payload['age'] ?? 0);

            if (AgeRule::isAge($age)) {
                $out[(string) $event->aggregate_id] = ['age' => $age, 'recipient' => ($event->payload['recipient'] ?? false) === true, 'label' => AgeRule::verifiedLabel($age)];
            }
        }

        return $out;
    }

    /**
     * 商品の画面の年齢の門へ送る URL（店の設定で出さない・答えた人・App が落ちた時は null）
     */
    public function gate(int $age, string $returnPath, Request $request): ?string
    {
        try {
            $url = $this->handler()?->gate($age, $returnPath, $request);

            // リダイレクト先は同じショップの中のパスだけ（App の誤りで外へ送らない）
            return is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $url : null;
        } catch (Throwable $e) {
            Log::error('年齢の門を決められなかった', ['error' => $e::class]);

            return null;
        }
    }
}
