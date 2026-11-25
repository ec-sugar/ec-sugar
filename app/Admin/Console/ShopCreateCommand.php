<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Services\AuditLog;
use App\Admin\Services\ShopNames;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 店（テナント）を作る（ADR 0031）。インストーラーが最初の店を作るのに使う。
 *
 *   php artisan shop:create demo --name="はじめての店" --domain=demo.shop.example.com
 *
 * 店の DB とロールを作る力は CLI（サーバーの root）だけが持つ。管理画面（Web のプロセス）からは作らない。
 * 同じ内容で流し直しても何も変えない（インストーラーの冪等のため）。名前だけが違うときも通す（名前は後から管理画面で変えられる）。ホスト名の違う同じ店・ほかの店のホスト名は断る。
 * DB の用意の途中で落ちたら、この流れが作った店の行と DB を片付ける（ほかの流れが作った店には触らない）。
 *
 * 複数の店の形（config の tls.shops が multi で、ショップのドメインがある。ADR 0090）で新しく作る店のホストは、
 * ショップのドメインの直下の 1 段だけ（入口の nginx・Caddy・cloudflared が受けるのは *.<ショップのドメイン> だけなので、ほかの名前の店は開けない）。
 * 1 段目は店の ID と違ってよい（ID demosake・ホスト demo-sake.ec-sugar.jp。店の DB の名前にハイフンを使わないため）。
 * 管理画面・中央のホスト（api）・同じドメインのほかのサーバーのサイトやメールの名前（www・dev・mail など）と紛れる名前は断る。
 * もうある店を同じ内容で流し直すとき（1 店舗の形から広げたサーバーのインストーラー）は、この決まりを当てない
 */
final class ShopCreateCommand extends Command
{
    /** 店の住所に使わせない名前（管理画面や将来の役目の名前と紛れる） */
    private const array RESERVED = ['admin', 'api', 'app', 'assets', 'cdn', 'internal', 'mail', 'root', 'smtp', 'static', 'staging', 'status', 'system', 'www'];

    /**
     * 複数の店の形で、店のホストの 1 段目に使わせない名前（店の ID の RESERVED に加えて、同じドメインに置かれやすいほかのサイト・メール・
     * DNS・ホスティングの管理の名前と、自動で探される名前〔mta-sts・wpad・autoconfig など〕。ec-sugar.jp では dev と www が別のサーバーにある。
     * ec-sugar-tls-probe はインストーラーが証明書のワイルドカードを確かめる名前）
     */
    private const array RESERVED_LABELS = [
        ...self::RESERVED,
        'autoconfig', 'autodiscover', 'blog', 'cpanel', 'cpcalendars', 'cpcontacts', 'dev', 'docs', 'ec-sugar-tls-probe', 'ftp', 'imap', 'isatap',
        'localhost', 'mta-sts', 'mx', 'ns', 'ns1', 'ns2', 'ns3', 'ns4', 'plesk', 'pop', 'pop3', 'webdisk', 'webmail', 'whm', 'wpad',
    ];

    protected $signature = 'shop:create
        {id : 店の ID（英小文字・数字・ハイフン、2〜32 文字。店の DB の名前にも使う）}
        {--name= : 店の名前（1〜100 文字。後から管理画面で変えられる）}
        {--domain= : 店のホスト名（例 demo.shop.example.com。複数の店の形ではショップのドメインの直下の 1 段）}';

    protected $description = '店（テナント）とその DB を作る';

    public function handle(AuditLog $audit): int
    {
        $id = (string) $this->argument('id');
        $name = trim((string) $this->option('name'));
        $domain = (string) $this->option('domain');

        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,30}[a-z0-9]\z/', $id) !== 1 || in_array($id, self::RESERVED, true)) {
            $this->error('店の ID は英小文字・数字・ハイフンの 2〜32 文字で、先頭と末尾は英小文字か数字にしてください（admin・www などは使えません）');

            return self::INVALID;
        }

        if ($name === '' || mb_strlen($name) > ShopNames::MAX || preg_match('/\p{C}/u', $name) !== 0) {
            $this->error('--name に 1〜'.ShopNames::MAX.' 文字の店の名前を指定してください（制御文字は使えません。名前は後から管理画面の「設定 › 基本」で変えられます）');

            return self::INVALID;
        }

        if (! $this->isShopHost($domain)) {
            $this->error('--domain に店のホスト名を英小文字で指定してください（例 demo.shop.example.com。管理画面のホストや IP の住所は使えません）');

            return self::INVALID;
        }

        $existing = Tenant::query()->find($id);

        if ($existing instanceof Tenant) {
            // 名前は後から管理画面で変えられるので、ホスト名が同じなら名前が違っても同じ店として通す（インストーラーの流し直しを止めない）。名前は書き戻さない
            if ($existing->domains()->pluck('domain')->all() === [$domain]) {
                $this->info($existing->name === $name
                    ? "店 {$id} はもうあります（何も変えていません）"
                    : "店 {$id} はもうあります（何も変えていません。名前は管理画面で変えた「{$existing->name}」のままです）");

                return self::SUCCESS;
            }

            $this->error("店 {$id} はもう別の内容であります（ホスト名が違う）。別の ID にするか、管理画面で今の店を確認してください");

            return self::FAILURE;
        }

        if (DB::table('domains')->where('domain', $domain)->exists()) {
            $this->error("ホスト名 {$domain} はほかの店が使っています");

            return self::FAILURE;
        }

        $problem = $this->multiShopHostProblem($id, $domain);

        if ($problem !== null) {
            $this->error($problem);

            return self::INVALID;
        }

        $tenant = new Tenant(['id' => $id, 'name' => $name]);

        try {
            // 行を足すと店の DB とロールの作成・移行・初期の値（TenancyServiceProvider）が同期で走る
            $tenant->save();
            $tenant->domains()->create(['domain' => $domain]);
        } catch (Throwable $e) {
            // 店の DB の移行・初期の値の途中で落ちると、店の文脈に入ったままになることがある。中央へ戻ってから片付ける
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            report($e);
            $this->error("店 {$id} を作れませんでした: ".$e::class);
            // 行を足す前に落ちた（同じ ID を先に作られたなど）ときは、この流れの物は何も無い。他人の店を消さない
            if ($tenant->exists) {
                $this->cleanUp($tenant);
            }

            return self::FAILURE;
        }

        $audit->record('tenant.created', null, ['by' => 'cli:'.(get_current_user() ?: 'unknown'), 'domain' => $domain], $id);
        $this->info("店 {$id}（{$name}）を作りました: https://{$domain}/");
        // 新しい店のショップは準備中
        $this->line('ショップは準備中です。管理画面の「設定 › 基本」で、準備中に入るパスワードを決めるか、公開にしてください。');

        return self::SUCCESS;
    }

    private function isShopHost(string $domain): bool
    {
        // ラベルは英小文字・数字・ハイフン、最後のラベルは英字で始まる（IP の住所を弾く）、2 つ以上のラベル
        return strlen($domain) <= 253
            && preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $domain) === 1
            && ! in_array($domain, (array) config('tenancy.central_domains'), true)
            && $domain !== config('admin.domain');
    }

    /**
     * 複数の店の形で、新しい店のホストが入口の受ける名前（ショップのドメインの直下の 1 段）で、紛れる名前でないか。
     * 1 店舗の形（と開発。ショップのドメインが無い）では何も見ない。断る理由を返す（よければ null）
     */
    private function multiShopHostProblem(string $id, string $domain): ?string
    {
        $parent = config('tls.shop_domain');

        if (config('tls.shops') !== 'multi' || ! is_string($parent) || $parent === '') {
            return null;
        }

        $suffix = '.'.$parent;
        $label = str_ends_with($domain, $suffix) ? substr($domain, 0, -strlen($suffix)) : '';

        // 1 段だけ（点を含まない）。英小文字・数字・ハイフン、先頭と末尾は英数字
        if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $label) !== 1) {
            return "複数の店の形では、店のホスト名はショップのドメイン {$parent} の直下の 1 段だけにしてください（例 {$id}.{$parent}）";
        }

        // 3〜4 文字目の -- は国際化ドメイン（xn--）などに取ってある形（RFC 5891）。見た目の似た名前で店を装わせない
        if (substr($label, 2, 2) === '--') {
            return "{$label} は使えません（3〜4 文字目に -- を含む名前は、国際化ドメインなどの形と紛れる）";
        }

        if (in_array($label, self::RESERVED_LABELS, true)) {
            return "{$label}.{$parent} は使えません（管理画面・中央のホスト・同じドメインのほかのサイトやメールの名前と紛れる）";
        }

        return null;
    }

    private function cleanUp(Tenant $tenant): void
    {
        try {
            // 消すと店の DB とロールも消える（TenancyServiceProvider の TenantDeleted）
            $tenant->delete();
            $this->warn("作りかけの店 {$tenant->getTenantKey()} を片付けました。原因を解消して再実行してください");
        } catch (Throwable $e) {
            report($e);
            $this->error("作りかけの店 {$tenant->getTenantKey()} を片付けられませんでした。中央 DB の tenants の行と、店の DB・ロールを手動で確認してください");
        }
    }
}
