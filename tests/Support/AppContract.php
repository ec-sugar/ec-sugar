<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Plugin\Manifest\PackageManifest;
use App\Core\Plugin\PluginDefinition;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * 全部の App に共通のテスト（App の契約のテスト）の部品。
 * テストのデータの提供者は Laravel が起きる前に動くので、App の一覧は plugins/ の composer.json から読む
 * （登録簿と同じ一覧かは AppDeclarationsTest が確かめる）。
 *
 * 例外の一覧もここに置く。どれも「今の App で見つかったもの」で、理由を必ず書く。直したら一覧から消す
 * （直ったのに一覧に残ると、テストが「一覧から消す」と落ちる）
 */
final class AppContract
{
    /**
     * 公開用の木（1.0）に入れない App（ADR 0098。release/public-paths.txt と合わせる）。開発用の木には入っている。
     * App の一覧を名前で書いたテストは、この中の入っていない App だけを一覧から外す（ほかの App が無くなったら、今までどおり落ちる）
     */
    public const array UNPUBLISHED = ['booking', 'lottery', 'subscription', 'tickets'];

    /**
     * UNPUBLISHED のうち、この木に入っていない App の名前（開発用の木では空）
     *
     * @return list<string>
     */
    public static function absentUnpublished(): array
    {
        return array_values(array_diff(self::UNPUBLISHED, array_keys(self::packages())));
    }

    /**
     * 名前 => 値の一覧から、この木に入っていない UNPUBLISHED の App の分を外す
     *
     * @template T
     *
     * @param  array<string, T>  $list
     * @return array<string, T>
     */
    public static function withoutAbsent(array $list): array
    {
        return array_diff_key($list, array_flip(self::absentUnpublished()));
    }

    /**
     * 管理画面の 1 件の画面が今壊れている App（App の名前 => 理由）。見つけた不具合で、直すかは運営が決める。直したら消す
     *
     * @var array<string, string>
     */
    public const array BROKEN_RECORD_PAGES = [
        // 今は無い（会員ランクの 1 件の画面の 500 は PR 138〔〕で直した）
    ];

    /**
     * お金の印（money）が要りそうな言葉に当たるのに、印の無い操作（App の名前 => 操作の名前 => 理由）。
     * 見つけた不具合の候補で、直すかは運営が決める（報告）。直したら消す
     *
     * @var array<string, array<string, string>>
     */
    public const array MONEY_UNMARKED = [
        // 今は無い（友だち紹介の「ポイントを付ける」は PR 134〔〕で money を付けた）
    ];

    /**
     * お金の言葉に当たるが、お金を動かさない操作（「お金ではない」。App の名前 => 操作の名前 => 理由）。
     * 機械では決めきれない操作は、運営に聞いてから、ここか印のどちらかにする
     *
     * @var array<string, array<string, string>>
     */
    public const array MONEY_WORDS_ONLY = [
        // 確かめの文が「返金はしません」と書いている。無効にするだけ（返金するのは別の操作 void_refund で、money の印つき）
        'tickets' => ['void' => '確認の文が「返金はしません」。チケットを無効にするだけ'],
        // 確かめの文が「ポイントを付けません」と書いている。紹介を付けないに決めるだけ
        'referral' => ['decline' => '確認の文が「ポイントを付けません」。付けないと決めるだけ'],
        // 確かめの文が、フィードに載る中身（名前・値段・在庫の具合・写真）を説明している。URL を作るだけで値段は変えない
        'product-feed' => ['issue' => '確認の文がフィードに載る「値段」を説明しているだけ。URL を作る操作'],
        // 解約は定期便を cancelled にし、締め切りの前のまだ請求していない回（scheduled）を取り消すだけで、請求・返金・台帳には触れない
        // （SubscriptionChanges::cancelForShop）。この先の請求を止める操作で、運用の段階のまま（運営の判断 11-06。「再開する」を運用のままにしたのと同じ考え）
        'subscription' => ['cancel' => '解約。お金を動かさず、この先の請求を止めるだけ（運用の段階のまま。運営の判断 11-06）'],
        // 確かめの文が「値段を申し込みの時より上げていないこと」を確かめるよう促している。操作は止めた抽選を続けるだけで、
        // 当選の請求は毎分の処理（lottery:run）が行う（定期便の「再開する」を運用のままにしたのと同じ考え）
        'lottery' => ['resume' => '確認の文が「値段」を確認するよう促しているだけ。止めた抽選を続ける操作（請求は毎分の処理）'],
        // 確かめの文が「もう受けたご注文の内容（名前と料金）はそのまま残ります」と書いている。種類を消すだけで、注文の料金は変えない
        'wrapping' => ['delete' => '確認の文が、受けた注文の「料金」が残ると説明しているだけ。ラッピングの種類を消す操作'],
    ];

    /**
     * 操作の名前・画面の名前・確かめの文のどれかに当たったら「お金の印が要りそう」とする言葉
     */
    public const array MONEY_WORDS = ['返金', 'ポイント', '残高', '値段', '価格', '金額', '代金', '料金', '入金', '請求', '支払', '払い', '払っ', 'ギフトカード', '決済', '注文を取り消', '解約'];

    /**
     * 操作の名前（英小文字の鍵）に当たったら「お金の印が要りそう」とする言葉
     */
    public const array MONEY_KEY_WORDS = ['refund', 'point', 'balance', 'price', 'charge', 'pay', 'paid', 'credit', 'amount', 'money'];

    /**
     * 1 件の画面が今壊れている App の理由（無ければ null）。一覧は直すたびに空になることがあるので、読むのはここから（空の一覧の定数を直に引くと、
     * 静的解析が「その鍵は無い」と決めてしまう）
     */
    public static function brokenRecordPage(string $plugin): ?string
    {
        return self::reasons(self::BROKEN_RECORD_PAGES)[$plugin] ?? null;
    }

    /**
     * お金の印の無い操作のうち、一覧に理由つきで載せたもの（操作の名前 => 理由）。読み方の理由は brokenRecordPage と同じ
     *
     * @return array<string, string>
     */
    public static function moneyUnmarked(string $plugin): array
    {
        return self::actionReasons(self::MONEY_UNMARKED)[$plugin] ?? [];
    }

    /**
     * 一覧を、空でも中身のある時と同じ型として読む（App の名前 => 理由）
     *
     * @param  array<string, string>  $list
     * @return array<string, string>
     */
    private static function reasons(array $list): array
    {
        return $list;
    }

    /**
     * 同じく、操作ごとの一覧（App の名前 => 操作の名前 => 理由）
     *
     * @param  array<string, array<string, string>>  $list
     * @return array<string, array<string, string>>
     */
    private static function actionReasons(array $list): array
    {
        return $list;
    }

    /**
     * 秘密らしい名前の設定（中身を暗号化し、画面とショップに出してはいけない）。設定の名前にこれが入るなら secret の印が要る
     */
    public const array SECRET_KEY_WORDS = ['secret', 'token', 'password', 'webhook', 'api_key', 'private'];

    /**
     * App を止めた店でも開くと宣言したショップの経路の名前（PluginDefinition::$keptStorefrontRoutes。見直し）。
     * 宣言の関数を、別に作った経路の登録簿の上で動かして名前を読む（登録された経路の印から逆に決めない。印を付け忘れた経路を「止めても開く」と取り違えない）
     *
     * @return list<string>
     */
    public static function keptWhenOff(PluginDefinition $plugin): array
    {
        if ($plugin->keptStorefrontRoutes === null) {
            return [];
        }

        $app = app();
        $previous = $app->make('router');
        $router = new Router($app->make('events'), $app);
        $app->instance('router', $router);
        Facade::clearResolvedInstance('router');

        try {
            $router->group(['prefix' => "p/{$plugin->name}", 'as' => "storefront.plugin.{$plugin->name}."], $plugin->keptStorefrontRoutes);
        } finally {
            $app->instance('router', $previous);
            Facade::clearResolvedInstance('router');
        }

        return array_values(array_map(static fn (Route $route): string => (string) $route->getName(), $router->getRoutes()->getRoutes()));
    }

    /**
     * plugins/ にある App（type が plugin のパッケージ）の、名前 => パッケージの根
     *
     * @return array<string, string>
     */
    public static function packages(): array
    {
        $out = [];

        foreach (glob(dirname(__DIR__, 2).'/plugins/*/composer.json') ?: [] as $path) {
            try {
                $package = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException("{$path} を読めません", previous: $e);
            }

            $manifest = is_array($package) ? PackageManifest::of($package) : null;

            if (is_array($manifest) && ($manifest['type'] ?? null) === 'plugin' && is_string($manifest['name'] ?? null)) {
                $out[$manifest['name']] = dirname($path);
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * データの提供者の形（App ごとに 1 件。名前で失敗が分かる）
     *
     * @return array<string, array{0: string}>
     */
    public static function provider(): array
    {
        $out = [];

        foreach (array_keys(self::packages()) as $name) {
            $out[$name] = [$name];
        }

        return $out;
    }

    /**
     * App のパッケージの src/ の中の、管理画面の操作（new AdminAction(…)）の宣言を、PHP の字句から読む。
     * 鍵が文字の中に変数を含むとき（use_{$n} など）は {n} の形にする。money は名前つきの引数の true だけを印とみなし、
     * 値が式のときは null（決めきれない）
     *
     * @return list<array{file: string, line: int, key: string, text: string, money: bool|null}>
     */
    public static function adminActions(string $root): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $tokens = array_values(array_filter(
                \PhpToken::tokenize((string) file_get_contents($file->getPathname())),
                static fn (\PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
            ));

            foreach ($tokens as $i => $token) {
                $class = $tokens[$i + 1] ?? null;

                if (! $token->is(T_NEW) || $class === null || ! in_array(ltrim($class->text, '\\'), ['AdminAction', 'App\\Core\\Plugin\\AdminPage\\AdminAction'], true) || ($tokens[$i + 2]->text ?? '') !== '(') {
                    continue;
                }

                $out[] = ['file' => substr($file->getPathname(), strlen($root) + 1), 'line' => $token->line] + self::arguments(array_slice($tokens, $i + 3));
            }
        }

        usort($out, static fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return $out;
    }

    /**
     * @param  list<\PhpToken>  $tokens  開きの括弧の後ろから
     * @return array{key: string, text: string, money: bool|null}
     */
    private static function arguments(array $tokens): array
    {
        $depth = 0;
        $argument = 0;
        $key = '';
        $text = '';
        $money = false;

        foreach ($tokens as $j => $token) {
            if (in_array($token->text, ['(', '[', '{'], true) || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    break;
                }

                $depth--;
            } elseif ($token->text === ',' && $depth === 0) {
                $argument++;

                continue;
            }

            // 文字の中身（画面の名前・確かめの文・鍵）。変数を含む文字は {名前} にする
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $text .= substr($token->text, 1, -1).' ';
            } elseif ($token->is(T_ENCAPSED_AND_WHITESPACE)) {
                $text .= $token->text;
            } elseif ($token->is(T_VARIABLE) && $depth > 0) {
                $text .= '{'.ltrim($token->text, '$').'}';
            }

            if ($argument === 0) {
                $key .= match (true) {
                    $token->is(T_CONSTANT_ENCAPSED_STRING) => substr($token->text, 1, -1),
                    $token->is(T_ENCAPSED_AND_WHITESPACE) => $token->text,
                    $token->is(T_VARIABLE) => '{'.ltrim($token->text, '$').'}',
                    default => '',
                };
            }

            // 名前つきの引数 money: … （true だけを印とみなす。式なら決めきれない）
            if ($depth === 0 && $token->is(T_STRING) && $token->text === 'money' && ($tokens[$j + 1]->text ?? '') === ':') {
                $value = strtolower($tokens[$j + 2]->text ?? '');
                $next = $tokens[$j + 3]->text ?? ')';
                $money = $value === 'true' && in_array($next, [',', ')'], true) ? true : ($value === 'false' && in_array($next, [',', ')'], true) ? false : null);
            }
        }

        return ['key' => $key, 'text' => $text, 'money' => $money];
    }

    /**
     * App のショップの経路（/p/<名前>/… と、検索に載せるページの頭〔ADR 0072〕の下、経路の名前が storefront.plugin.<名前>. で始まるもの、
     * ショップの印と plugin:<名前> の印が両方付いたもの）。どれか 1 つに当たれば App の経路とみなす（印を付け忘れた経路も拾う）。
     *
     * 使う店の印（plugin:<名前>）だけではショップの経路とみなさない: 管理画面の経路にも付くことがある
     * （商品の CSV は中身が App で画面と受け口は核のまま。ADR 0036・ADR 0107 の段 1。使っていない店では核の経路が 404 になる）
     *
     * @return list<Route>
     */
    public static function shopRoutes(Router $router, string $plugin, ?string $pagesPrefix): array
    {
        $out = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            $middleware = $route->gatherMiddleware();
            $mine = $uri === "p/{$plugin}" || str_starts_with($uri, "p/{$plugin}/")
                || ($pagesPrefix !== null && ($uri === $pagesPrefix || str_starts_with($uri, "{$pagesPrefix}/")))
                || str_starts_with((string) $route->getName(), "storefront.plugin.{$plugin}.")
                || (in_array("plugin:{$plugin}", $middleware, true) && in_array('storefront', $middleware, true));

            if ($mine) {
                $out[] = $route;
            }
        }

        return $out;
    }

    /**
     * 経路の引数を、経路の形の決まりに合う値で埋めた道筋（ULID・トークン・決まった語など。合う値が無ければ止める）
     */
    public static function fill(Route $route): string
    {
        return (string) preg_replace_callback('/\{(\w+)\??\}/', static function (array $m) use ($route): string {
            $pattern = $route->wheres[$m[1]] ?? null;

            if ($pattern === null) {
                return 'probe';
            }

            // 決まった語のどれか（google|meta など）は最初の語
            if (preg_match('/\A[a-z]+(\|[a-z]+)+\z/', $pattern) === 1) {
                return explode('|', $pattern)[0];
            }

            foreach ([strtolower((string) Str::ulid()), str_repeat('a', 40), str_repeat('A', 43), '2222222222', 'probe'] as $candidate) {
                if (preg_match('#\A(?:'.$pattern.')\z#', $candidate) === 1) {
                    return $candidate;
                }
            }

            throw new RuntimeException("経路 {$route->uri()} の {$m[1]}（{$pattern}）に合う試しの値がありません。AppContract::fill に足す");
        }, $route->uri());
    }

    /**
     * 操作がお金の言葉に当たるか（当たった言葉。当たらなければ null）
     */
    public static function moneyWord(string $key, string $text): ?string
    {
        foreach (self::MONEY_KEY_WORDS as $word) {
            if (str_contains($key, $word)) {
                return $word;
            }
        }

        foreach (self::MONEY_WORDS as $word) {
            if (str_contains($text, $word)) {
                return $word;
            }
        }

        return null;
    }
}
