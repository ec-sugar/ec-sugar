// メニューの定義（案 C: 灰の地に浮かせた白い板のメニューで、上に主な項目、区切り線の下に設定）。メニュー（AppShell）・店の切り替えの中の運営全体・設定の目次・
// 画面の中のタブ・パンくず・画面の題・ブラウザのタブの題は、すべてここから作る。
// 項目を足す・名前を変えるときはここだけを直す（画面ごとに写さない）。できた画面だけを載せる（画面の無い項目は設定の目次の「近日」だけ）。
// ほかのファイルを読み込まない（ブラウザのテストがこのファイルを直接読み、画面のメニューと照らし合わせる）。
// 店ごとに変わる項目（App の画面。ADR 0051）は、ここに形と作り方（appPageItems）だけを置き、店の答えは呼ぶ側から引数で受け取る。
//
// level: その項目の画面を読むのに要る、店での段階（read・operate・money。ADR 0024）。読む API が断る（403）段階の人には項目を出さない
// owner: オーナーだけの項目（読む API が RequireOwner で断る）
// 出し分けは見た目のためだけ。守るのはサーバーの認可（EnterTenant の段階と RequireOwner）

// 店の中のメニュー。group: 'main' は上の主なメニュー、'settings' は下の区切り線の下（「設定 ›」）。
// tabs: その項目の中の画面（画面の見出しの下にタブで並べる）。最初のタブが項目の行き先
export const TENANT_ITEMS = [
    { key: 'home', label: 'ホーム', icon: 'home', route: 'tenant', match: ['tenant'], level: 'read', group: 'main' },
    { key: 'orders', label: '注文', icon: 'receipt', route: 'transactions', match: ['transactions', 'transaction'], level: 'read', group: 'main' },
    // 売上の集計（ADR 0060）。台帳から数えた店の売上・注文の数・商品の上位。金額は店のお金なので「会計」の段階から（API も 403 で断る）
    { key: 'sales', label: '売上', icon: 'chart', route: 'sales', match: ['sales'], level: 'money', group: 'main' },
    // お客さん（ADR 0044 の 4）。一覧と 1 人の画面は件数と金額だけで閲覧から。名前と連絡先・まとめる・分け直すは画面の中で運用から
    { key: 'customers', label: 'お客さん', icon: 'customer', route: 'customers', match: ['customers', 'customer'], level: 'read', group: 'main' },
    {
        key: 'listings',
        label: '商品',
        icon: 'box',
        route: 'listings',
        match: ['listings', 'listing', 'listing-new', 'listing-import', 'categories'],
        level: 'read',
        group: 'main',
        tabs: [
            { key: 'listings', label: '商品の一覧', route: 'listings', match: ['listings'] },
            { key: 'categories', label: 'カテゴリ', route: 'categories', match: ['categories'] },
        ],
    },
    // クーポン（ADR 0054 の区切り 2）。販促なので商品のすぐ下。見るのは閲覧から、作る・直す・止めるは画面の中で運用から
    { key: 'coupons', label: 'クーポン', icon: 'ticket', route: 'coupons', match: ['coupons', 'coupon', 'coupon-new'], level: 'read', group: 'main' },
    { key: 'photos', label: '写真', icon: 'image', route: 'photos', match: ['photos'], level: 'read', group: 'main' },
    {
        // テーマの選択と中身・自由なページ（ADR 0039）。ページの編集の画面（theme-page）は画面の全体を使うので、ここが入口
        key: 'design',
        label: 'デザイン',
        icon: 'brush',
        route: 'theme-content',
        match: ['theme-content', 'theme-page', 'shop-pages'],
        level: 'read',
        group: 'main',
        tabs: [
            { key: 'theme', label: 'テーマ', route: 'theme-content', match: ['theme-content'] },
            { key: 'pages', label: 'ページ', route: 'shop-pages', match: ['shop-pages'] },
        ],
    },
    // プラグインは、管理画面の上では「App」（docs/design/terms.md）
    { key: 'apps', label: 'App', icon: 'plug', route: 'tenant-plugins', match: ['tenant-plugins', 'tenant-plugin'], level: 'read', group: 'main' },
    // AI エージェントの案（承認の一覧。ADR 0101）。見るのも運用から（API も 403 で断る）。承認は画面の中で、その案に要る段階の人だけ
    { key: 'agent-proposals', label: 'AI エージェントの提案', icon: 'check', route: 'agent-proposals', match: ['agent-proposals', 'agent-proposal'], level: 'operate', group: 'main' },
    {
        key: 'settings',
        label: '設定',
        icon: 'settings',
        route: 'settings',
        match: ['settings', 'settings-basic', 'settings-legal', 'settings-privacy', 'settings-order-terms', 'settings-order-fields', 'settings-payments', 'settings-shipping', 'settings-delivery-dates', 'settings-points', 'settings-gift-cards', 'settings-consent', 'settings-records', 'settings-mails', 'settings-seo', 'settings-agents'],
        level: 'read',
        group: 'settings',
    },
];

// App の画面（ADR 0051。定期便の一覧など）。使っている App ごとに店で変わるので、ここには形だけを置き、
// 項目は店の答え（GET /api/tenants/{店}/app-pages の [{ plugin, title }]。読むのは appPages.js）から appPageItems で作る。
// メニューでは主なメニューの「App」のすぐ下に、App ごとに 1 行（字下げ）。経路は App をまたいで 2 つ（一覧と 1 件）なので、
// 今いる項目は経路の名前でなく App の名前（params.plugin）で決める（isCurrentItem）
export const APP_PAGE_ROUTES = ['app-page', 'app-record'];
const APP_PAGE_ITEM = { icon: 'list', level: 'read', group: 'main', after: 'apps' };

// 設定の目次（まとまりごと）。「設定 ›」で横に開く目次と、設定の目次の画面の両方をここから作る。
// route の無い項目は「近日」の札だけで、押せない。settings: false は設定の外の画面へ移る項目（目次の画面からだけ）
// 支払いの API は読むのは閲覧から（変えるのはお金の段階。画面の中で出し分ける）
export const SETTINGS_SECTIONS = [
    {
        key: 'shop',
        title: 'ショップ',
        items: [
            { key: 'basic', label: '基本', description: 'ショップの名前と URL、準備中と公開の切り替え、準備中のショップに入るパスワード', route: 'settings-basic', level: 'read' },
            { key: 'legal', label: '特商法の表記', description: 'ショップの「特定商取引法に基づく表記」のページに出す項目と内容', route: 'settings-legal', level: 'read' },
            // プライバシーポリシー（ADR 0094）
            { key: 'privacy', label: 'プライバシーポリシー', description: 'ショップの「プライバシーポリシー」のページに出す本文（書き始めのひな形つき）', route: 'settings-privacy', level: 'read' },
            { key: 'order-terms', label: '注文の案内', description: '注文の最終確認の画面に出す、届く目安と返品・交換の条件', route: 'settings-order-terms', level: 'read' },
            // 注文の項目（ADR 0066）
            { key: 'order-fields', label: '注文の項目', description: '注文の入力に追加する欄（のしの有無・用途・ご要望など）', route: 'settings-order-fields', level: 'read' },
            // 検索と共有（ADR 0061）。商品・カテゴリ・ページの題と説明は、それぞれの画面の「検索と共有」で
            { key: 'seo', label: 'SEO', description: '検索結果と共有した時の見え方（トップのタイトルと説明・共有の写真）と、古い URL からの転送', route: 'settings-seo', level: 'read' },
        ],
    },
    {
        key: 'money',
        title: '支払いと配送',
        items: [
            { key: 'payments', label: '支払い', description: 'お支払いを受ける Stripe のアカウント', route: 'settings-payments', level: 'read' },
            { key: 'shipping', label: '配送と送料', description: '配送の方法ごとの都道府県の送料と、送料を無料にする金額', route: 'settings-shipping', level: 'read' },
            // お届け日時の指定（ADR 0059）
            { key: 'delivery-dates', label: 'お届け日時', description: 'お客さんがお届け日と時間帯を選べるようにする設定。発送までの日数・休業日・当日発送の締め切り時刻', route: 'settings-delivery-dates', level: 'read' },
            // ポイント（ADR 0068）
            { key: 'points', label: 'ポイント', description: 'ポイントを使うかどうかと、会員に付けるポイントの率（既定 1%）。まだ使われていないポイントの合計', route: 'settings-points', level: 'read' },
            // ギフトカード（ADR 0076）。売るのは App、発行したカードと残高は核
            { key: 'gift-cards', label: 'ギフトカード', description: '発行したギフトカードの残高と有効期限、停止と残高の調整、まだ使われていない残高の合計', route: 'settings-gift-cards', level: 'read' },
            { key: 'tax', label: '税率', description: '商品ごとの税率（標準・軽減）', route: null, level: 'read' },
        ],
    },
    {
        key: 'apps',
        title: 'App',
        items: [
            { key: 'apps', label: '使う App', description: 'この店で使う App を選び、App ごとに設定する', route: 'tenant-plugins', level: 'read', settings: false },
            // 解析・広告のタグの同意の帯。既定は出さない。海外のお客さんを相手にする店が入れる
            { key: 'consent', label: '同意バナー', description: '解析・広告のタグを、同意したお客さんにだけ読み込むか（海外のお客さんを相手にする店向け）', route: 'settings-consent', level: 'read' },
        ],
    },
    {
        key: 'records',
        title: '記録',
        items: [
            { key: 'records', label: '記録の確認', description: '注文や在庫の記録が、あとから書き換えられていないかを確認する', route: 'settings-records', level: 'read' },
            // メールの記録（ADR 0095）。見るのは閲覧から、宛先と試しのメールは画面の中で運用から
            { key: 'mails', label: 'メールの記録', description: 'お客さんや店へ送ったメールと、届かなかった理由。テストメールを自分に送る', route: 'settings-mails', level: 'read' },
        ],
    },
    {
        key: 'ai',
        title: 'AI',
        items: [
            // AI エージェントの鍵（ADR 0100）。鍵は運営ごと（店の中の段階は要らない。発行した人のその店での段階の中だけ読める）なので、どの運営にも出す
            { key: 'agents', label: 'AI エージェント', description: '自分の AI エージェント（Claude など）から店のデータを見る・変更するためのアクセスキーと接続の方法。変更をすぐ反映するか承認してからにするか、お客さんの情報を渡すか', route: 'settings-agents', level: 'read' },
        ],
    },
];

// 運営全体（店の切り替えの中から開く）。運営ダッシュボード・メンバーと権限・App とテーマはオーナーだけ。店の一覧は、見られる店が 1 つなら出さない（single: false）
export const OPERATOR_ITEMS = [
    // 運営全体の様子（ADR 0042）: 全部の店の困りごと・数・機械の調子。オーナーだけ
    { key: 'operations', label: '運営ダッシュボード', icon: 'grid', route: 'operations', match: ['operations'], owner: true },
    { key: 'tenants', label: '店の一覧', icon: 'building', route: 'tenants', match: ['tenants'], owner: false, single: false },
    { key: 'members', label: 'メンバーと権限', icon: 'users', route: 'members', match: ['members'], owner: true },
    { key: 'plugins', label: 'App とテーマ', icon: 'plug', route: 'plugins', match: ['plugins'], owner: true },
    { key: 'login-history', label: 'ログインの履歴', icon: 'clock', route: 'login-history', match: ['login-history'], owner: false },
];

// 自分のもの（下の自分の名前の横から）
export const SELF_ITEMS = [
    { key: 'passkeys', label: 'パスキー', icon: 'key', route: 'passkeys', match: ['passkeys'], owner: false },
];

// ヘルプ（下の区切り線の下、「設定 ›」の下）。店をまたいで同じ説明なので、店の外の画面
export const HELP_ITEM = { key: 'help', label: 'ヘルプ', icon: 'help', route: 'help', match: ['help', 'help-article'] };

// ヘルプの中の説明のページ（ヘルプの目次に、この順で並べる）。URL は /help/<key>（経路は 1 つの help-article）。
// 中身（節・手順・写し・関係する画面）は resources/admin/help/<key>.js に置き、1 つの画面（HelpArticlePage）が描く。
// 足すときは、ここに 1 行と、help/ にファイルと、help/index.js の読み込みの 3 か所（ブラウザのテスト help-data.spec.js が食い違いを落とす）
// App の画面（ADR 0051）の「説明を見る」は、ページのファイルの plugin（App の名前）と節の appScreens（app-page・app-record）で決める（help/index.js の appHelpLink）
export const HELP_PAGES = [
    { key: 'setup', label: 'はじめての設定', description: 'ショップを公開するまでの順番。ホームの「開設の手順」と同じ並びで説明します' },
    { key: 'payments', label: 'お支払い（Stripe）の始め方', description: '「Stripe へ接続」で始める手順と、自分の Stripe の鍵で設定する手順' },
    { key: 'listings', label: '商品の登録', description: '写真・説明・種類（色・サイズ）・オプション・在庫・カテゴリと、プレビュー、CSV でまとめて登録・書き出し' },
    { key: 'orders', label: '注文と発送・返金', description: '注文の流れと状態、発送の登録、取消と返金、お客さんへのメールとメールの記録（届かなかった理由）' },
    { key: 'customers', label: 'お客さんと会員', description: 'お客さんの一覧と画面、名前と連絡先の表示、同じお客さんをまとめる・分け直す、会員' },
    { key: 'coupons', label: 'クーポン', description: 'クーポンと自動の割引（全品 10%・まとめ買い）の作り方（金額・割合・送料無料）、対象と条件、複数あるときにどれが適用されるか、停止と再開、使われた後は変更できないこと' },
    { key: 'sales', label: '売上の集計', description: '期間ごとの売上・注文の数・返金・クーポン・送料、日ごとの動き、よく売れた商品、新規のお客さんとリピーター、CSV の書き出し' },
    { key: 'subscriptions', label: '定期便', description: '定期便の一覧と状態の見方、依頼された時のお休み・再開・解約、カードが通らず止まったとき、商品の「定期便」の欄' },
    { key: 'booking', label: '予約（App）', description: '予約できる商品の作り方（曜日と時刻・定員・締め切り・先払いか当日払い）、今日の予約と来店の受付、枠の停止と定員の変更' },
    { key: 'gift-cards', label: 'ギフトカード', description: 'ギフトカード（商品券）の売り方（App）、お客さんの使い方と使う順、発行したカードの残高・停止・残高の調整、資金決済法の目安' },
    { key: 'gifts', label: '贈り物（App）', description: '住所を知らなくても贈れるしくみ、受け取り待ちの一覧、取り消しと返金、受け取りの期限、返金が止まったとき' },
    { key: 'tickets', label: 'デジタルチケット（App）', description: '入場券と回数券の作り方、会員だけの売り方、当日の受付（QR・番号・お名前）、無効と返金、転売の監視' },
    { key: 'downloads', label: 'ダウンロード販売（App）', description: 'ファイルを売る商品の作り方とファイルのアップロード、お客さんの受け取り方（期限と回数）、回数を戻す・期限を延ばす・止める' },
    { key: 'preorder', label: '予約販売（App）', description: '発売日の前の商品を予約で受けるしくみ、予約の数の上限と受付の終わり、発売日の変え方と通知、予約の注文の発送' },
    { key: 'age-check', label: '年齢確認（App）', description: 'お酒・たばこなど年齢確認が必要な商品の設定、注文の時の生年月日と同意、会員の記録、注文のラベルと納品書・配送の CSV、ショップを開いたときに年齢をたずねる画面' },
    { key: 'wrapping', label: 'ラッピング・のし（App）', description: 'ラッピングの種類と料金、のしの表書き・掛け方・名入れの決まり、商品ごとの選べる・選べない、注文・納品書・配送の CSV での表示' },
    { key: 'lottery', label: '抽選販売（App）', description: '会員がカードを登録して申し込む抽選のしくみ、受付の期間と当選数の決め方、当選した方のお支払いとカードの変更、購入の権利の失効と再抽選' },
    { key: 'sale-period', label: '公開の予約と販売期間（App）', description: '商品ごとに「この日時から公開する」「この日時で販売を終える」を設定する方法、ショップでの表示、予約の一覧の見方' },
    { key: 'bundle', label: 'セット商品（App）', description: '複数の商品をまとめて 1 つの値段で売る設定、セットに含まれる商品の在庫の減り方、用意できるセットの数と一覧の見方' },
    { key: 'purchase-limits', label: '購入の制限（App）', description: '商品ごとの「1 回のご注文で何点まで」と、お店の「◯ 円以上から」の決め方、カートでの表示' },
    { key: 'product-faq', label: '商品のよくある質問（App）', description: '商品の画面に出す質問と回答の追加・編集・並べ替え、ショップでの表示' },
    { key: 'product-feed', label: '商品フィード（App）', description: 'Google Merchant Center と Meta のカタログに商品を載せるための URL の作り方と登録の手順、商品ごとに載せる・載せないの設定' },
    { key: 'catalog-sort', label: '並び順と絞り込み（App）', description: 'ショップの商品一覧の並びをお店で決める手順、タグの付け方、お客さんが使う価格・タグ・在庫の絞り込み' },
    { key: 'product-reviews', label: '商品レビュー（App）', description: 'お客さんが書いたレビューの公開・非公開・返信・削除、書ける人とショップでの表示' },
    { key: 'shipping-csv', label: '配送の CSV（App）', description: '送り状のソフトへの書き出しと、送り状番号の取り込みでまとめて発送にする手順' },
    { key: 'restock', label: '再入荷のお知らせ（App）', description: '売り切れの商品に再入荷のお知らせを申し込んだ人の数の見方、ショップでの表示と入荷した時のお知らせ' },
    { key: 'shop-notify', label: '店への通知（App）', description: '注文が入った・取り消された・在庫が少なくなったを、Slack か店のメールで受け取る設定と、テストで送る手順' },
    { key: 'contact', label: 'お問い合わせ（App）', description: 'お問い合わせの読み方と返信、状態、ショップのフォーム、通知のメールと削除までの期間' },
    { key: 'newsletter', label: 'メルマガ・一斉メール（App）', description: 'お客さんが受け取るかどうかを選ぶ場所、配信の書き方・テスト送信・予約、送信者の表示と配信の停止' },
    { key: 'cart-reminder', label: 'カゴ落ちのお知らせ（App）', description: 'お支払いの前に離れたお客さんへのお知らせ、送る相手と送らない場合、送った数・ショップに戻った数・購入された数の見方' },
    { key: 'blog', label: 'お知らせ・ブログ（App）', description: '記事の書き方、公開・予約・取り下げ、プレビュー、ショップの /news と新着のお知らせのセクション' },
    { key: 'favorites', label: 'お気に入り（App）', description: 'お気に入りの多い商品のランキングの見方、ショップのハートのボタンとマイページ、値下げ・再入荷のお知らせが届く人' },
    { key: 'recommendations', label: 'おすすめのセクション（App）', description: 'ページに追加できる関連商品・最近見た商品・売れ筋ランキングのセクション、表示される商品の選ばれ方' },
    { key: 'referral', label: '友だち紹介（App）', description: '紹介のリンクと付けるポイントの決め方、ポイントが付く時と付かない時、確認待ちの選び方' },
    { key: 'member-ranks', label: '会員ランク（App）', description: '直近 12 か月の購入額でのランクの決め方、会員価格・ポイントの上乗せ・会員だけの商品、ランクの手動変更' },
    { key: 'line', label: 'LINE 連携（App）', description: 'LINE でのログイン・会員登録と、注文と発送の LINE での通知。LINE の管理画面での準備、接続の方法、チャネルの鍵が漏れたとき' },
    { key: 'design', label: 'テーマとページの編集', description: 'テーマの選び方、セクションを積んで作るページの編集、写真、自由なページ' },
    { key: 'seo', label: '検索と共有（SEO）', description: '検索結果と LINE・X で共有した時の見え方、タイトルと説明の書き方、古い URL からの転送、検索結果に出ない画面' },
    { key: 'apps', label: 'App と外部送信', description: '解析・広告のタグの App の使い方、同意バナー、外部送信の公表' },
    { key: 'access', label: '公開・メンバー・パスキー', description: '準備中とパスワード、公開、メンバーと権限、パスキーをなくしたとき' },
    { key: 'agents', label: 'AI エージェントから接続する', description: '自分の AI エージェント（Claude など）から店のデータを見る・変更するアクセスキーの発行・接続の方法・できること・変更の承認・お客さんの情報・取り消し' },
    { key: 'faq', label: 'よくある質問', description: 'ショップが見えない・メールが届かない・支払いが確定しない など' },
];

// メニューに出さない画面の題（パンくずとタブの題に使う）
const OTHER_TITLES = {
    login: 'ログイン',
    enroll: 'パスキーの登録',
    recover: 'パスキーを使えないとき',
    'recover-email': 'メールでパスキーを登録し直す',
    'listing-new': '新しい商品',
    'coupon-new': '新しいクーポン',
    coupon: 'クーポン',
    'listing-import': 'CSV で取り込む',
    listing: '商品',
    transaction: '注文',
    customer: 'お客さん',
    'tenant-plugin': 'App',
    // App の画面の題は App の名前（読めていない間だけこの題）
    'app-page': 'App の画面',
    'app-record': 'App の画面',
    'theme-page': 'ページの編集',
    trace: '処理の追跡',
    'agent-proposal': 'AI エージェントの提案',
    'oauth-consent': 'AI エージェントの接続',
    'not-found': '見つかりません',
};

const SETTINGS_ITEMS = SETTINGS_SECTIONS.flatMap((section) => section.items);
const TAB_ITEMS = TENANT_ITEMS.flatMap((item) => item.tabs ?? []);

/**
 * 段階を満たす項目だけを残す。can(level) はこの店での自分の段階が level 以上か（tenants.js の canInTenant）。
 * 閲覧（read）の項目は、店に入れる人なら誰でも読める（入れない人には API が 404 を返す）ので、段階を読み終える前から出す
 */
export function visibleTenantItems(group, can, appPages = []) {
    const items = TENANT_ITEMS.filter((item) => item.group === group && (item.level === 'read' || can(item.level)));
    const extra = APP_PAGE_ITEM.group === group && (APP_PAGE_ITEM.level === 'read' || can(APP_PAGE_ITEM.level)) ? appPageItems(appPages) : [];

    if (extra.length === 0) {
        return items;
    }

    const at = items.findIndex((item) => item.key === APP_PAGE_ITEM.after);

    return at === -1 ? [...items, ...extra] : [...items.slice(0, at + 1), ...extra, ...items.slice(at + 1)];
}

/**
 * App の画面のメニューの項目（pages は店の答えの [{ plugin, title }]）。行き先は一覧（app-page）。sub は「App」の下の字下げ
 */
export function appPageItems(pages) {
    return (pages ?? []).map((page) => ({
        key: `app-${page.plugin}`,
        label: page.title,
        icon: APP_PAGE_ITEM.icon,
        route: 'app-page',
        params: { plugin: page.plugin },
        match: [],
        plugin: page.plugin,
        level: APP_PAGE_ITEM.level,
        group: APP_PAGE_ITEM.group,
        sub: true,
    }));
}

/**
 * メニューの項目が今の画面か。App の画面の項目は App の名前で、ほかは経路の名前で決める
 */
export function isCurrentItem(item, routeName, params = {}) {
    if (item.plugin !== undefined) {
        return APP_PAGE_ROUTES.includes(routeName) && params.plugin === item.plugin;
    }

    return item.match.includes(routeName);
}

/**
 * 運営全体の項目。オーナーだけの項目はオーナーにだけ、店の一覧は見られる店が 2 つ以上（か、まだ分からない）ときだけ
 */
export function visibleOperatorItems(isOwner, single = false) {
    return OPERATOR_ITEMS.filter((item) => (!item.owner || isOwner) && !(single && item.single === false));
}

export function visibleSettingsSections(can) {
    return SETTINGS_SECTIONS
        .map((section) => ({ ...section, items: section.items.filter((item) => item.level === 'read' || can(item.level)) }))
        .filter((section) => section.items.length > 0);
}

/**
 * 画面の題（見出しとタブの題）。メニューの項目の画面はその名前、タブの画面はタブの名前、設定の画面は目次の名前。
 * App の画面は App の名前（appTitle。店の答えから呼ぶ側が渡す。このファイルはほかを読み込まない決まりなので値を受け取るだけ）
 */
export function titleOf(routeName, params = {}, appTitle = null) {
    // 説明のページは経路が 1 つなので、題はページの名前（/help/<key>）で引く
    if (routeName === 'help-article') {
        return HELP_PAGES.find((page) => page.key === params.article)?.label ?? OTHER_TITLES['not-found'];
    }

    if (APP_PAGE_ROUTES.includes(routeName) && typeof appTitle === 'string' && appTitle !== '') {
        return appTitle;
    }

    const item = TENANT_ITEMS.find((i) => i.route === routeName)
        ?? TAB_ITEMS.find((i) => i.route === routeName)
        ?? SETTINGS_ITEMS.find((i) => i.route === routeName)
        ?? OPERATOR_ITEMS.find((i) => i.route === routeName)
        ?? SELF_ITEMS.find((i) => i.route === routeName)
        ?? (HELP_ITEM.route === routeName ? HELP_ITEM : null);

    return item?.label ?? OTHER_TITLES[routeName] ?? '管理';
}

/**
 * 画面の中のタブ（その項目の中の画面）。routeName の画面が tabs を持つ項目の中なら、タブの並び（行き先つき）を返す。無ければ空
 */
export function tabsFor(routeName, tenant) {
    const item = TENANT_ITEMS.find((i) => i.tabs?.some((tab) => tab.match.includes(routeName)));

    return (item?.tabs ?? []).map((tab) => ({
        key: tab.key,
        label: tab.label,
        to: { name: tab.route, params: { tenant } },
        current: tab.match.includes(routeName),
    }));
}

// 見られる店がちょうど 1 つか（tenants.js が店の一覧を読んだときに通知する。このファイルはほかを読み込まない決まりなので値を受け取るだけ）
let singleShop = false;

export function setSingleShop(value) {
    singleShop = value === true;
}

/**
 * パンくず（今いる場所）。店の中の画面は「店の一覧 / 店の名前 / 項目 / 今の画面」の形（店が 1 つなら「店の一覧」の段を出さない）。
 * 一覧の画面は項目の名前で終わり、詳細の画面（current を渡す）とタブの画面は項目へのリンクの後に今の画面。設定の画面は「設定」の後に目次の名前。
 * 店の外の画面は、運営全体なら「運営 / 画面」、ヘルプの中のページなら「ヘルプ / 画面」。
 * App の画面（app に { plugin, title }）は「… / 店 / App / App の画面の名前」、1 件の画面はその後に今の画面（current）
 */
export function crumbsFor(routeName, tenant = null, tenantLabel = null, current = null, app = null) {
    if (tenant === null) {
        if (routeName === 'help-article') {
            return [{ label: HELP_ITEM.label, to: { name: HELP_ITEM.route } }, { label: current ?? titleOf(routeName) }];
        }

        return [{ label: current ?? titleOf(routeName) }];
    }

    const crumbs = singleShop ? [] : [{ label: titleOf('tenants'), to: { name: 'tenants' } }];
    const label = tenantLabel ?? tenant;

    if (routeName === 'tenant') {
        return [...crumbs, { label }];
    }

    crumbs.push({ label, to: { name: 'tenant', params: { tenant } } });

    if (APP_PAGE_ROUTES.includes(routeName)) {
        const apps = TENANT_ITEMS.find((i) => i.key === APP_PAGE_ITEM.after);
        const appTitle = app?.title ?? titleOf(routeName);
        crumbs.push({ label: apps.label, to: { name: apps.route, params: { tenant } } });

        if (routeName === 'app-page' || typeof app?.plugin !== 'string') {
            return [...crumbs, { label: appTitle }];
        }

        crumbs.push({ label: appTitle, to: { name: 'app-page', params: { tenant, plugin: app.plugin } } });

        return [...crumbs, { label: current ?? appTitle }];
    }

    const item = TENANT_ITEMS.find((i) => i.match.includes(routeName));

    if (item === undefined) {
        return [...crumbs, { label: current ?? titleOf(routeName) }];
    }

    if (item.route === routeName && current === null) {
        return [...crumbs, { label: item.label }];
    }

    crumbs.push({ label: item.label, to: { name: item.route, params: { tenant } } });

    return [...crumbs, { label: current ?? titleOf(routeName) }];
}
