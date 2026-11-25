<?php

declare(strict_types=1);

namespace App\Core\Plugin\AgeCheck;

/**
 * お客さんが注文の入力で答えた年齢の確かめ（ADR 0077）。確定までセッションに置き、注文の確定の錠の中で今の決まりと照らし直す。
 * **生年月日は持たない**（入力の時に満年齢を数えて捨てる）。持つのは、聞いた年齢・生年月日で満たした年齢（選べる年齢のいちばん上。
 * 生年月日を聞かなかった〔会員が前に確かめた〕なら 0）・同意したか・贈り物の受け取る人の同意・同意した文の版だけ
 */
final readonly class AgeAnswer
{
    public function __construct(
        public int $age,
        public int $level,
        public bool $consent,
        public bool $recipient,
        public string $version = AgeRule::CONSENT_VERSION,
    ) {}

    /**
     * $required 歳の品を、この答えで売ってよいか（年齢は生年月日か会員が前に確かめた年齢 $remembered のどちらか）。
     * 同意は今の版で、聞いた年齢が $required 以上の時だけ数える（入力の後に 18 歳の品から 20 歳の品に変わったら聞き直す）
     */
    public function satisfies(int $required, int $remembered, bool $gift): bool
    {
        return $this->consent && $this->version === AgeRule::CONSENT_VERSION && $this->age >= $required
            && max($this->level, $remembered) >= $required && (! $gift || $this->recipient);
    }

    /**
     * @return array{age: int, level: int, consent: bool, recipient: bool, version: string}
     */
    public function toArray(): array
    {
        return ['age' => $this->age, 'level' => $this->level, 'consent' => $this->consent, 'recipient' => $this->recipient, 'version' => $this->version];
    }

    /**
     * セッションから戻す（形が違えば null。聞き直す）
     */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_int($data['age'] ?? null) || ! is_int($data['level'] ?? null) || ! is_bool($data['consent'] ?? null)
            || ! is_bool($data['recipient'] ?? null) || ! is_string($data['version'] ?? null)) {
            return null;
        }

        if (! AgeRule::isAge($data['age']) || ($data['level'] !== 0 && ! AgeRule::isAge($data['level'])) || strlen($data['version']) > 32) {
            return null;
        }

        return new self($data['age'], $data['level'], $data['consent'], $data['recipient'], $data['version']);
    }
}
