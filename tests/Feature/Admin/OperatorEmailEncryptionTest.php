<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
use App\Core\Mail\RedactedFailedJobs;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * 運営のメールアドレスの暗号化（ADR 0112。段階 2）。中央 DB だけを持ち出されても、運営のアドレスは読めない。
 * 探すのと重複を防ぐのは照合用の値（email_index）。平文と照合用の値の無い行は DB が断る。
 * あわせて、失敗した処理の記録の例外の文からメールアドレスを伏せる。
 */
final class OperatorEmailEncryptionTest extends TestCase
{
    use DatabaseTruncation;

    public function test_the_email_is_stored_encrypted_and_read_back_as_the_lowercase_address(): void
    {
        $operator = Operator::create(['name' => '山田', 'email' => ' Owner@Example.COM ']);

        $row = DB::table('operators')->where('id', $operator->id)->first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('@', (string) $row->email);
        $this->assertStringNotContainsStringIgnoringCase('owner', (string) $row->email);
        $this->assertSame('owner@example.com', Crypt::decryptString((string) $row->email));
        $this->assertSame(Operator::emailIndex('owner@example.com'), $row->email_index);
        $this->assertSame('owner@example.com', $operator->fresh()?->email);

        // 中央 DB のどの文字の列にも、平文のアドレスは無い（監査ログ・セッション・パスキーにも入れていない）
        $this->assertSame([], $this->columnsContaining('owner@example.com'));
    }

    public function test_lookups_go_through_the_index_and_ignore_case_and_spaces(): void
    {
        $operator = Operator::create(['name' => '山田', 'email' => 'owner@example.com']);

        $this->assertSame($operator->id, Operator::query()->whereEmail('  OWNER@example.com')->value('id'));
        $this->assertNull(Operator::query()->whereEmail('other@example.com')->first());
        // 暗号文と比べる素の where は見つけない（使い方を間違えると、いつも「いない」になる。下の歯止めのテストが止める）
        $this->assertNull(Operator::query()->where('email', 'owner@example.com')->first());
    }

    public function test_saving_other_fields_keeps_the_ciphertext(): void
    {
        $operator = Operator::create(['name' => '山田', 'email' => 'owner@example.com']);
        $sealed = DB::table('operators')->where('id', $operator->id)->value('email');

        $operator->refresh()->update(['name' => '山田 太郎', 'last_login_at' => now()]);
        $this->assertSame($sealed, DB::table('operators')->where('id', $operator->id)->value('email'));
    }

    public function test_the_same_address_cannot_be_registered_twice_even_with_another_case(): void
    {
        Operator::create(['name' => '山田', 'email' => 'owner@example.com']);

        $this->expectException(QueryException::class);
        Operator::create(['name' => '別の人', 'email' => 'OWNER@example.com']);
    }

    public function test_the_database_refuses_a_plaintext_address_a_missing_index_or_a_malformed_index(): void
    {
        $operator = Operator::create(['name' => '山田', 'email' => 'owner@example.com']);

        foreach ([['email' => 'owner@example.com'], ['email_index' => 'not-a-hash'], ['email_index' => null]] as $values) {
            try {
                DB::transaction(static fn () => DB::table('operators')->where('id', $operator->id)->update($values));
                $this->fail('DB が断らなかった: '.json_encode($values));
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/operators_email_sealed|operators_email_index_format|null value in column "email_index"/', $e->getMessage());
            }
        }
    }

    public function test_changing_the_address_updates_the_index(): void
    {
        $operator = Operator::create(['name' => '山田', 'email' => 'owner@example.com']);

        $operator->update(['email' => 'New@Example.com']);
        $this->assertSame($operator->id, Operator::query()->whereEmail('new@example.com')->value('id'));
        $this->assertNull(Operator::query()->whereEmail('owner@example.com')->first());
    }

    public function test_the_index_key_is_derived_from_the_app_key_and_is_not_a_plain_hash(): void
    {
        $index = Operator::emailIndex('owner@example.com');
        $this->assertNotSame(hash('sha256', 'owner@example.com'), $index);
        $this->assertNotSame(hash_hmac('sha256', 'owner@example.com', (string) config('app.key')), $index);

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertNotSame($index, Operator::emailIndex('owner@example.com'));

        config(['app.key' => '']);
        $this->expectException(RuntimeException::class);
        Operator::emailIndex('owner@example.com');
    }

    public function test_no_code_looks_an_operator_up_by_the_raw_email_column(): void
    {
        // 段階 2 から email は暗号文になるので、素の比べ方はいつも「いない」になる（回復やメンバーの重複の確かめがすり抜ける）
        $patterns = [
            "/Operator::(query\\(\\)->)?(where|firstWhere|firstOrCreate|updateOrCreate|firstOrNew)\\(\\[?'email'/",
            "/'operators\\.email'/",
            '/from operators where email/i',
        ];
        $found = [];

        foreach (['app', 'routes', 'plugins', 'database'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/vendor/')) {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $source) === 1) {
                        $found[] = $file->getPathname();
                    }
                }
            }
        }

        $this->assertSame([], $found, '運営をメールアドレスで探す時は Operator::query()->whereEmail() か Operator::matchEmail() を使う');
    }

    public function test_failed_jobs_keep_the_exception_without_email_addresses(): void
    {
        $failer = app('queue.failer');
        $this->assertInstanceOf(RedactedFailedJobs::class, $failer);

        $uuid = (string) Str::uuid();
        $failer->log('redis', 'default', json_encode(['uuid' => $uuid, 'displayName' => 'テスト'], JSON_THROW_ON_ERROR),
            new RuntimeException('Expected response code "250" but got code "550", with message "550 5.1.1 <Owner.Name+x@mail.example.co.jp>: Recipient address rejected". cc to second@example.com'));

        $exception = (string) DB::table('failed_jobs')->where('uuid', $uuid)->value('exception');
        $this->assertStringNotContainsString('@', $exception);
        $this->assertStringContainsString('550 5.1.1 <'.RedactedFailedJobs::MASK.'>: Recipient address rejected', $exception);
        $this->assertStringContainsString('cc to '.RedactedFailedJobs::MASK, $exception);
        // ほかの操作は元の部品のまま
        $this->assertSame(1, $failer->count());
        $this->assertNotNull($failer->find($uuid));
    }

    /**
     * 中央 DB の文字の列のうち、$needle を含む列
     *
     * @return list<string>
     */
    private function columnsContaining(string $needle): array
    {
        $found = [];

        foreach (DB::select("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = 'public' AND data_type IN ('text', 'character varying', 'character', 'jsonb', 'json')") as $column) {
            if (DB::table($column->table_name)->whereRaw('"'.$column->column_name.'"::text ILIKE ?', ['%'.$needle.'%'])->exists()) {
                $found[] = $column->table_name.'.'.$column->column_name;
            }
        }

        return $found;
    }
}
