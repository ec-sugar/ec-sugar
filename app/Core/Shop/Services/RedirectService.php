<?php

declare(strict_types=1);

namespace App\Core\Shop\Services;

use App\Core\Event\EventStore;
use App\Core\Shop\Models\Redirect;
use App\Core\Shop\Seo\RedirectPath;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 転送（ADR 0061 の 10・11）を読む・足す・消す。変えるのはここだけ。書き込みは EventStore::lock() を先に取る（ADR 0012）。
 *
 * 鎖とループを作らない決まり（いつも「どの転送の行き先も、ほかの転送の元ではない」を保つ）:
 * - 足す転送の行き先が別の転送の元なら、その転送の行き先へ寄せる（店の人が足す時）。寄せた先が元と同じなら断る（ループ）
 * - slug を変えた時の自動の転送では、行き先は今ある画面なので、行き先を元にしていた転送は消す（今ある画面を転送は上書きしない）
 * - 新しい元を行き先にしていた転送は、新しい行き先へ付け替える
 */
final class RedirectService
{
    public function __construct(private readonly EventStore $events) {}

    /**
     * @return Collection<int, Redirect> 新しい順
     */
    public function list(): Collection
    {
        return Redirect::query()->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /**
     * 届いた要求の道筋（と ? の後ろ）に合う転送の行き先。? の後ろまで合うものを先に、無ければ道筋だけで
     */
    public function find(string $path, ?string $query): ?string
    {
        $key = RedirectPath::key($path);

        if ($key === null || mb_strlen($key) > RedirectPath::MAX) {
            return null;
        }

        $candidates = $query === null || $query === '' ? [$key] : [RedirectPath::key("{$path}?{$query}"), $key];
        $candidates = array_values(array_filter($candidates, static fn (?string $c): bool => $c !== null && mb_strlen($c) <= RedirectPath::MAX));

        foreach ($candidates as $candidate) {
            $to = Redirect::query()->where('from_path', $candidate)->value('to_path');

            if (is_string($to)) {
                return $to;
            }
        }

        return null;
    }

    /**
     * 店の人が足す。形が違う・同じ元がもうある・ループ・上限は ValidationException
     *
     * @param  array<string, mixed>  $input  from・to
     */
    public function add(array $input, ?string $actor = null): Redirect
    {
        $from = RedirectPath::from($input['from'] ?? null);
        $to = RedirectPath::to($input['to'] ?? null);

        return DB::transaction(function () use ($from, $to, $actor): Redirect {
            $this->events->lock();

            if (Redirect::query()->where('from_path', $from)->exists()) {
                throw ValidationException::withMessages(['from' => 'この古い URL の転送はすでにあります。変更するときは、削除してから追加してください。']);
            }

            $to = $this->forward($to);

            if (RedirectPath::key($to) === $from) {
                throw ValidationException::withMessages(['to' => '古い URL と新しい URL が同じになります（転送が無限に繰り返されます）。']);
            }

            $this->assertRoom();
            $redirect = Redirect::create(['from_path' => $from, 'to_path' => $to, 'source' => Redirect::MANUAL]);
            $this->events->append('shop.redirect_added', 'redirect', $redirect->id, ['from' => $from, 'to' => $to, 'source' => Redirect::MANUAL], $actor);
            $this->rewriteTargets($from, $to, $actor);

            return $redirect;
        });
    }

    /**
     * 消す（無い ID は ValidationException）
     */
    public function remove(string $id, ?string $actor = null): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $this->events->lock();
            $redirect = Redirect::query()->whereKey(strtolower($id))->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['redirect' => '転送が見つかりません。']);
            $redirect->delete();
            $this->events->append('shop.redirect_removed', 'redirect', $redirect->id, ['from' => $redirect->from_path], $actor);
        });
    }

    /**
     * slug を変えた時の自動の転送（ADR 0061 の 11）。古い道筋 → 新しい道筋。呼ぶ側は自分のトランザクションの中で
     * EventStore::lock() を取ってから呼ぶ。上限に達していれば足さない（slug の変更は止めない）。足したかを返す
     */
    public function followMove(string $oldPath, string $newPath, ?string $actor = null): bool
    {
        $from = RedirectPath::key($oldPath);
        $to = RedirectPath::to($newPath);

        if ($from === null || $from === '/' || RedirectPath::key($to) === $from) {
            return false;
        }

        $this->events->lock();

        // 行き先は今ある画面。それを元にしていた転送は効かない（今ある画面を上書きしない）ので消す
        foreach (Redirect::query()->where('from_path', RedirectPath::key($to))->lockForUpdate()->get() as $shadowed) {
            $shadowed->delete();
            $this->events->append('shop.redirect_removed', 'redirect', $shadowed->id, ['from' => $shadowed->from_path, 'reason' => 'target_live'], $actor);
        }

        $existing = Redirect::query()->where('from_path', $from)->lockForUpdate()->first();

        if ($existing !== null) {
            $existing->update(['to_path' => $to, 'source' => Redirect::AUTO]);
            $this->events->append('shop.redirect_updated', 'redirect', $existing->id, ['from' => $from, 'to' => $to], $actor);
        } elseif (Redirect::query()->count() >= Redirect::MAX) {
            $this->rewriteTargets($from, $to, $actor);

            return false;
        } else {
            $redirect = Redirect::create(['from_path' => $from, 'to_path' => $to, 'source' => Redirect::AUTO]);
            $this->events->append('shop.redirect_added', 'redirect', $redirect->id, ['from' => $from, 'to' => $to, 'source' => Redirect::AUTO], $actor);
        }

        $this->rewriteTargets($from, $to, $actor);

        return true;
    }

    /**
     * 行き先が別の転送の元なら、その転送の行き先へ寄せる（決まりにより 1 段で終わる）
     */
    private function forward(string $to): string
    {
        $key = RedirectPath::key($to);
        $next = $key === null ? null : Redirect::query()->where('from_path', $key)->value('to_path');

        return is_string($next) ? $next : $to;
    }

    /**
     * $from を行き先にしていた転送を、$to へ付け替える（鎖を作らない）
     */
    private function rewriteTargets(string $from, string $to, ?string $actor): void
    {
        foreach (Redirect::query()->where('from_path', '<>', $from)->lockForUpdate()->get() as $redirect) {
            if (RedirectPath::key($redirect->to_path) !== $from) {
                continue;
            }

            if (RedirectPath::key($to) === $redirect->from_path) {
                // 付け替えると自分へ戻る転送は、もう要らない（その道筋は今ある画面か、新しい行き先そのもの）
                $redirect->delete();
                $this->events->append('shop.redirect_removed', 'redirect', $redirect->id, ['from' => $redirect->from_path, 'reason' => 'loop'], $actor);

                continue;
            }

            $redirect->update(['to_path' => $to]);
            $this->events->append('shop.redirect_updated', 'redirect', $redirect->id, ['from' => $redirect->from_path, 'to' => $to], $actor);
        }
    }

    private function assertRoom(): void
    {
        if (Redirect::query()->count() >= Redirect::MAX) {
            throw ValidationException::withMessages(['from' => '転送は '.number_format(Redirect::MAX).' 件までです。使わなくなった転送を削除してから追加してください。']);
        }
    }
}
