<?php

declare(strict_types=1);

namespace App\Core\Payment\Contracts;

use App\Core\Payment\Models\PaymentAttempt;

/**
 * 店の決済代行の鍵の置き場（ADR 0026・ADR 0107 の段 3）。
 * 核の金の流れ（PaymentService・StoredPayments・PaymentRefunds・返金の照合）と店頭・App は、この 3 つしか使わない。
 * 鍵の形・置き方・つなぐ手順（Stripe の sk_ / whsec_ / Connect）は、この口の向こう側＝ドライバの側の話。
 *
 * 鍵そのものはここからも出ない。返すのは PaymentCredentials（どのアカウントで受けるか・live か test だけを見せる値）
 */
interface PaymentCredentialStore
{
    /**
     * 新しい支払いに使う鍵。置いていなければ null（店頭はお支払いに進めない）。
     * 準備中のショップで、ふだんの鍵が本番か無く、公開の前に試す鍵があればそちら（ADR 0096）
     */
    public function credentials(): ?PaymentCredentials;

    /**
     * その支払いの試みを作ったアカウントとモードの鍵（返金・問い合わせ・画面を閉じるとき）。
     * 今の設定にその鍵が無ければ null（別のアカウントで受けた支払いは、ここからは返金できない）
     */
    public function credentialsForAttempt(PaymentAttempt $attempt): ?PaymentCredentials;

    /**
     * ふだんの鍵のモード（'live' か 'test'）。置いていなければ null。
     * 開設の手順は、試すための鍵だけでは「支払い」を済みにしない（公開すると払えないため）
     */
    public function primaryMode(): ?string;
}
