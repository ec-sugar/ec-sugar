<?php

declare(strict_types=1);

namespace EcSugar\PluginReferral;

use App\Core\Plugin\AdminPage\AdminAction;
use App\Core\Plugin\AdminPage\AdminAttention;
use App\Core\Plugin\AdminPage\AdminColumn;
use App\Core\Plugin\AdminPage\AdminFact;
use App\Core\Plugin\AdminPage\AdminFilter;
use App\Core\Plugin\AdminPage\AdminRecord;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\AdminPage\AdminRow;
use App\Core\Plugin\AdminPage\AdminRows;
use App\Core\Plugin\AdminPage\CellType;
use App\Core\Plugin\Contracts\AdminPageHandler;
use App\Core\Points\Services\PointSettings;
use EcSugar\PluginReferral\Models\Referral;
use Illuminate\Database\Eloquent\Builder;

/**
 * 管理画面の「友だち紹介」（ADR 0051 の App の画面。ADR 0075 の 4）。絞り込みの数が、確かめ待ち・注文を待つ・付けた（付けたポイントの合計つき）・付けなかった。
 * 行は紹介した人と紹介された人（核の「お客さん」の画面へのリンク。会員の ID の末尾 8 文字だけ）・状態・最初の注文・付けたポイント・日時で、名前やメールは出さない。
 * 確かめ待ちの 1 件には「付ける」「付けない」（取り消せないので直前の再確認の後。actor をイベントに残す）。
 * 「付ける」はポイント＝お金に近い（手で足す口と同じ）ので「お金」の段階の人だけ（money）。「付けない」はポイントを動かさないので運用の段階から
 */
final class ReferralAdminPage implements AdminPageHandler
{
    private const array STATUS = [
        Referral::LINKED => ['label' => '最初の注文待ち', 'tone' => 'muted'],
        Referral::ORDERED => ['label' => '発送待ち', 'tone' => 'muted'],
        Referral::REVIEW => ['label' => '確認待ち', 'tone' => 'warn'],
        Referral::REWARDED => ['label' => 'ポイントを付けた', 'tone' => 'good'],
        Referral::DECLINED => ['label' => '店の判断で付けなかった', 'tone' => 'muted'],
        Referral::REFUSED => ['label' => '付けなかった', 'tone' => 'muted'],
    ];

    public function title(): string
    {
        return '友だち紹介';
    }

    public function filters(): array
    {
        $given = (int) Referral::query()->where('status', Referral::REWARDED)->selectRaw('COALESCE(SUM(referrer_points + friend_points), 0) AS total')->value('total');

        return [
            new AdminFilter('review', '確認待ち', self::filtered('review')->count()),
            new AdminFilter('waiting', '注文・発送待ち', self::filtered('waiting')->count()),
            new AdminFilter('rewarded', 'ポイントを付けた（計 '.number_format($given).' ポイント）', self::filtered('rewarded')->count()),
            new AdminFilter('not_rewarded', '付けなかった', self::filtered('not_rewarded')->count()),
            new AdminFilter('all', 'すべての紹介', self::filtered('all')->count()),
        ];
    }

    public function attention(): array
    {
        return [
            new AdminAttention('review', '確認待ちの友だち紹介', '同じ接続元（IP アドレス）から何人も会員登録があった紹介です。内容を確認して「ポイントを付ける」か「付けない」を選んでください。',
                Referral::query()->where('status', Referral::REVIEW)->count()),
            new AdminAttention('all', 'ポイントを使わない設定なので、友だち紹介を受け付けていません', '設定の「ポイント」を「使う」にすると、紹介のリンクとポイントの付与が働きます。ポイントを使わないなら、App の「友だち紹介」を止めてください。',
                app(PointSettings::class)->enabled() ? 0 : 1, 'bad'),
        ];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('friend', '紹介された会員', CellType::Link),
            new AdminColumn('referrer', '紹介した会員', CellType::Link),
            new AdminColumn('status', '状態', CellType::Status),
            new AdminColumn('order', '最初の注文', CellType::Link),
            new AdminColumn('points', '付けたポイント（2 人の計）', CellType::Number),
            new AdminColumn('linked', '会員になった日時', CellType::DateTime),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $query = self::filtered($filter);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('linked_at')->orderByDesc('id')->forPage($page, $perPage)->get();

        return new AdminRows($rows->map(static fn (Referral $r): AdminRow => new AdminRow($r->id, [
            'friend' => self::member($r->friend_account_id),
            'referrer' => self::member($r->referrer_account_id),
            'status' => self::status($r),
            'order' => $r->transaction_id === null ? null : self::order($r->transaction_id),
            'points' => $r->status === Referral::REWARDED ? $r->referrer_points + $r->friend_points : null,
            'linked' => $r->linked_at,
        ]))->values()->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $r = Referral::query()->find(strtolower($id));

        if ($r === null) {
            return null;
        }

        return new AdminRecord('友だち紹介 '.strtoupper(substr($r->id, -8)), [
            new AdminFact('状態', CellType::Status, self::status($r)),
            new AdminFact('紹介した会員', CellType::Link, self::member($r->referrer_account_id)),
            new AdminFact('紹介された会員', CellType::Link, self::member($r->friend_account_id)),
            new AdminFact('最初の注文', CellType::Link, $r->transaction_id === null ? null : self::order($r->transaction_id)),
            new AdminFact('会員になった日時', CellType::DateTime, $r->linked_at),
            new AdminFact('最初の注文の支払い日時', CellType::DateTime, $r->ordered_at),
            new AdminFact('結果が決まった日時', CellType::DateTime, $r->decided_at),
            new AdminFact('紹介した会員に付けたポイント', CellType::Number, $r->status === Referral::REWARDED ? $r->referrer_points : null),
            new AdminFact('紹介された会員に付けたポイント', CellType::Number, $r->status === Referral::REWARDED ? $r->friend_points : null),
        ], actions: $r->status === Referral::REVIEW ? [
            new AdminAction('approve', 'ポイントを付ける', '紹介した会員と紹介された会員に、設定のポイントを付けます。取り消せません。', danger: true, money: true),
            new AdminAction('decline', '付けない', 'この紹介にはポイントを付けません。取り消せません。', danger: true),
        ] : []);
    }

    public function act(string $id, string $action, string $actor): void
    {
        match ($action) {
            'approve' => app(Referrals::class)->approve($id, $actor),
            'decline' => app(Referrals::class)->decline($id, $actor),
            default => throw new AdminRecordNotFound,
        };
    }

    /**
     * @return array{label: string, tone: string}
     */
    private static function status(Referral $r): array
    {
        $status = self::STATUS[$r->status] ?? ['label' => $r->status, 'tone' => 'muted'];

        if ($r->reason !== null) {
            $status['label'] = mb_strimwidth($status['label'].'（'.(Referral::REASONS[$r->reason] ?? $r->reason).'）', 0, CellType::TEXT_MAX);
        }

        return $status;
    }

    /**
     * 会員（核の「お客さん」の画面へ。見せるのは台帳の ID の末尾 8 文字だけ）
     *
     * @return array{label: string, kind: string, id: string}
     */
    private static function member(string $accountId): array
    {
        return ['label' => '会員 '.strtoupper(substr($accountId, -8)), 'kind' => 'customer', 'id' => $accountId];
    }

    /**
     * @return array{label: string, kind: string, id: string}
     */
    private static function order(string $transactionId): array
    {
        return ['label' => strtoupper(substr($transactionId, -8)), 'kind' => 'transaction', 'id' => $transactionId];
    }

    /**
     * @return Builder<Referral>
     */
    private static function filtered(string $filter): Builder
    {
        $query = Referral::query();

        return match ($filter) {
            'review' => $query->where('status', Referral::REVIEW),
            'waiting' => $query->whereIn('status', [Referral::LINKED, Referral::ORDERED]),
            'rewarded' => $query->where('status', Referral::REWARDED),
            'not_rewarded' => $query->whereIn('status', [Referral::REFUSED, Referral::DECLINED]),
            default => $query,
        };
    }
}
