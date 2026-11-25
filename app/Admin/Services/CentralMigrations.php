<?php

declare(strict_types=1);

namespace App\Admin\Services;

use Illuminate\Database\Migrations\Migrator;
use RuntimeException;

/**
 * 確かめ口（/up）の中身の 1 つ（ADR 0043 の 4）: 中央の DB に流していないマイグレーションがあれば落とす（/up が 500 を返す）。
 * 更新の後にマイグレーションを流し忘れると、新しい表を読む画面だけが 500 になり、気づきにくい。
 * 店の DB の流し忘れは見ない（中央から店の DB へ入らない。運営の様子の「数えられなかった店」で分かる。ADR 0042）
 */
final class CentralMigrations
{
    public function __construct(private readonly Migrator $migrator) {}

    /**
     * 流していないマイグレーションの数
     */
    public function pending(): int
    {
        $central = (string) config('tenancy.database.central_connection');

        return $this->migrator->usingConnection($central, function (): int {
            $repository = $this->migrator->getRepository();

            if (! $repository->repositoryExists()) {
                throw new RuntimeException('中央のデータベースにマイグレーションの記録の表がありません');
            }

            // 店のマイグレーション（database/migrations/tenant）は下のディレクトリなので入らない（getMigrationFiles は下へ降りない）
            $files = $this->migrator->getMigrationFiles([database_path('migrations'), ...$this->migrator->paths()]);

            return count(array_diff(array_keys($files), $repository->getRan()));
        });
    }

    /**
     * /up の DiagnosingHealth から。流していないものがあれば投げる（文は /up に出るので、名前は出さず数だけ）
     */
    public function assertCurrent(): void
    {
        $pending = $this->pending();

        if ($pending > 0) {
            throw new RuntimeException("中央のデータベースに流していないマイグレーションが {$pending} 件あります");
        }
    }
}
