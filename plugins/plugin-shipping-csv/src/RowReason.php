<?php

declare(strict_types=1);

namespace EcSugar\PluginShippingCsv;

/**
 * 取り込んだ行を飛ばす理由（列挙型。自由な文は残さない。conventions の 1）
 */
enum RowReason: string
{
    case BadReference = 'bad_reference';
    case NoTracking = 'no_tracking';
    case BadTracking = 'bad_tracking';
    case NotFound = 'not_found';
    case Ambiguous = 'ambiguous';
    case Duplicate = 'duplicate';
    case AlreadyShipped = 'already_shipped';
    case Cancelled = 'cancelled';
    case Unpaid = 'unpaid';
    case NotShippable = 'not_shippable';
    case AwaitingAddress = 'awaiting_address';
    case CashOnDelivery = 'cash_on_delivery';
    case BeforeRelease = 'before_release';

    /** 店の人に見せる一言 */
    public function label(): string
    {
        return match ($this) {
            self::BadReference => '注文番号が正しくありません',
            self::NoTracking => '送り状番号がありません',
            self::BadTracking => '送り状番号が正しくありません（英数字とハイフンで 64 文字まで）',
            self::NotFound => 'この注文番号の注文が見つかりません',
            self::Ambiguous => '荷物が 2 つ以上に分かれる注文です（書き出した CSV の番号〔末尾の -1・-2〕で取り込んでください）',
            self::Duplicate => '前の行と同じ荷物です',
            self::AlreadyShipped => 'すでに発送済みです',
            self::Cancelled => '取り消した注文です',
            self::Unpaid => 'まだ入金されていない注文です',
            self::NotShippable => 'この注文は今は発送済みにできません（注文の画面で状態を確認してください）',
            self::AwaitingAddress => 'お届け先がまだ決まっていません（贈り物の受け取り待ち）',
            // 代金引換（ADR 0074）: この CSV の送り状は発払い。代引きの送り状は運送会社の画面で作り、発送は注文の画面で登録する
            self::CashOnDelivery => '代金引換の注文です（運送会社の画面で代引きの送り状を作り、注文の画面で発送を登録してください）',
            self::BeforeRelease => '発売日の前の予約の注文です（発売日から発送できます）',
        };
    }
}
