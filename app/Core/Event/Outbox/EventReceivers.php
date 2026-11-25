<?php

declare(strict_types=1);

namespace App\Core\Event\Outbox;

use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Notice;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * 記録（ドメインイベント）の受け手の登録簿（ADR 0089。拡張点「イベント」の確かな道）。核と App はサービスプロバイダの boot で足す。
 *
 * - `on()`: 記録の種類で受ける（`transaction.phase_changed` など）
 * - `notice()`: 核の知らせ（`PaymentConfirmed` など。`App\Core\Event\Notice`）で受ける。記録の `notice.*` から知らせを作り直して渡す
 *
 * どちらも、送り出し役（EventRelay）がコミットの後に届け、落ちたら見回りが届け直す（少なくとも 1 回。2 回届くことがある）。
 * 受け手は冪等に書く（同じ記録が 2 回届いても、メール 2 通・ポイント 2 回・チケット 2 枚にならない）。
 * 届く順は、同じ集約（同じ取引など）の記録なら記録の順。違う集約の間の順は約束しない
 */
final class EventReceivers
{
    /** 受け手の名前の形（`<App の名前か core>.<何をするか>`） */
    private const string NAME = '/\A[a-z][a-z0-9-]*(\.[a-z][a-z0-9_-]*)+\z/';

    /** @var array<string, EventReceiver> */
    private array $receivers = [];

    /** @var (Closure(): array<string, array{enabled: bool, changed_at: CarbonImmutable|null}>)|null */
    private ?Closure $appStates = null;

    /**
     * 記録の種類で受ける
     *
     * @param  list<string>  $types
     * @param  Closure(DomainEvent): mixed  $handler  返した値は使わない
     */
    public function on(string $name, array $types, Closure $handler, ?string $app = null): void
    {
        if (preg_match(self::NAME, $name) !== 1 || strlen($name) > 64) {
            throw new InvalidArgumentException("受け手の名前の形が違います: {$name}");
        }

        if (isset($this->receivers[$name])) {
            throw new LogicException("同じ名前の受け手がもうあります: {$name}");
        }

        if ($types === []) {
            throw new InvalidArgumentException('受け取る記録の種類がありません');
        }

        $this->receivers[$name] = new EventReceiver($name, array_values(array_unique($types)), $handler, $app);
    }

    /**
     * 核の知らせで受ける。handler は知らせと、元の記録（いつ起きたか・処理の ID）を受け取る。
     * テストで `event(new PaymentConfirmed(…))` と直に出したときも同じ handler が動く（届け直しの守りは無く、落ちたら報告だけ。元の記録は null）
     *
     * @template T of Notice
     *
     * @param  class-string<T>  $notice
     * @param  Closure(T, DomainEvent|null): mixed  $handler  返した値は使わない
     */
    public function notice(string $name, string $notice, Closure $handler, ?string $app = null): void
    {
        $this->on($name, [$notice::type()], static fn (DomainEvent $event) => $handler($notice::fromEvent($event), $event), $app);

        Event::listen($notice, function (Notice $sent) use ($handler, $app): void {
            if (! $this->allows($app, null)) {
                return;
            }

            try {
                $handler($sent, null);
            } catch (Throwable $error) {
                report($error);
            }
        });
    }

    /**
     * @return array<string, EventReceiver> 登録した順
     */
    public function all(): array
    {
        return $this->receivers;
    }

    public function get(string $name): ?EventReceiver
    {
        return $this->receivers[$name] ?? null;
    }

    /**
     * App の使う・使わないの読み方（核の文脈どうしの頼り合いを作らないよう、サービスプロバイダがつなぐ）
     *
     * @param  Closure(): array<string, array{enabled: bool, changed_at: CarbonImmutable|null}>  $states
     */
    public function useAppStates(Closure $states): void
    {
        $this->appStates = $states;
    }

    /**
     * 店の App の使う・使わない（店の文脈の中で読む）
     *
     * @return array<string, array{enabled: bool, changed_at: CarbonImmutable|null}>
     */
    public function appStates(): array
    {
        return $this->appStates === null ? [] : ($this->appStates)();
    }

    /**
     * App の受け手に、この時に起きた記録を届けてよいか。App を使っていて、記録が今の「使う」にした時より後のものだけ
     * （止めていた間の記録は、使うに戻しても届けない。ADR 0089）。App に結びつかない受け手はいつでもよい
     *
     * @param  array<string, array{enabled: bool, changed_at: CarbonImmutable|null}>|null  $states  先に読んだ状態（無ければ読む）
     */
    public function allows(?string $app, ?CarbonImmutable $occurredAt, ?array $states = null): bool
    {
        if ($app === null) {
            return true;
        }

        $state = ($states ?? $this->appStates())[$app] ?? null;

        if ($state === null || ! $state['enabled']) {
            return false;
        }

        // 記録の時刻は秒で切ってある（EventStore）。使うにした時刻も秒で切って比べる（同じ秒の記録は届ける）
        return $occurredAt === null || $state['changed_at'] === null || ! $occurredAt->lessThan($state['changed_at']->startOfSecond());
    }
}
