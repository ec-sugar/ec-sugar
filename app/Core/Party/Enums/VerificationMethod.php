<?php

declare(strict_types=1);

namespace App\Core\Party\Enums;

/**
 * 本人確認の方法（古物営業法の確認、仕事の報酬支払い先の確認に使う）。
 */
enum VerificationMethod: string
{
    case Ekyc = 'ekyc';            // 外部 eKYC 事業者
    case Document = 'document';    // 書類の提出（画像は暗号化して S3 互換に置き、参照だけ持つ）
    case InPerson = 'in_person';   // 店頭での対面確認
}
