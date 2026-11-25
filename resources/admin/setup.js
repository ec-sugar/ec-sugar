// ホームの開設の手順と「ようこそ」の言葉。済んだかはサーバー（GET …/setup）が店のデータから決め、
// ここは手順ごとの名前・説明・移る先の画面だけを持つ。並びはサーバーの ShopSetup::STEPS と同じ（サーバーの並びで出す）。
//   route: 移る先の画面（router.js の名前）。action: まだの手順のボタンの文字

export const SETUP_STEPS = {
    legal: {
        label: '店の情報と特商法',
        description: 'ショップの「特定商取引法に基づく表記」に、販売業者・所在地・連絡先などを書きます。',
        route: 'settings-legal',
        action: '入力する',
    },
    shipping: {
        label: '送料',
        description: '都道府県ごとの送料（全国同じでも）と、送料を無料にする金額を決めて保存します。クール便などの配送の方法も追加できます。',
        route: 'settings-shipping',
        action: '設定する',
    },
    payment: {
        label: '支払い',
        description: 'お客さんのお支払いを受け取る Stripe に接続します。',
        route: 'settings-payments',
        action: '接続する',
    },
    listing: {
        label: '最初の商品',
        description: '自分の商品を、写真と値段を付けて登録し、公開します。',
        route: 'listing-new',
        action: '登録する',
    },
    design: {
        label: 'デザイン',
        description: 'テーマを選び、トップページの写真と文を自分のショップのものにして公開します。',
        route: 'theme-content',
        action: '編集する',
    },
    publish: {
        label: '公開',
        description: '準備中のショップを公開して、だれでも見られるようにします。',
        route: 'settings-basic',
        action: '公開する',
    },
    customers: {
        label: 'お客さんを集める',
        description: 'アクセス解析や広告の App を使って、ショップに来る人を増やします。',
        route: 'tenant-plugins',
        action: 'App を選ぶ',
    },
};

// 手順の補足（済・未と店の状態から）。無ければ null
export function stepNote(step, setup) {
    if (step.key === 'payment' && step.done && step.mode === 'test') {
        return 'テストモードです。本物の注文を受ける前に、Stripe の本番の鍵に切り替えてください。';
    }

    if (step.key === 'publish' && !step.done && setup.samples?.remaining) {
        return 'サンプルが残っています。公開の前に、「最初の商品」の中からまとめて削除しましょう。';
    }

    return null;
}

// 「ようこそ」の 3 枚。2 枚目は店の今の様子（サンプルがあるか・公開しているか）で替える
export function welcomeSlides(setup) {
    const remaining = setup.samples?.remaining;
    const second = remaining
        ? {
            key: 'samples',
            icon: 'box',
            title: 'サンプルが入っています',
            body: `雑貨のお店のサンプル（商品 ${remaining.listings} 点など）が入っています。`
                + (setup.opened ? '' : 'ショップは準備中なので、お客さんには見えません。')
                + '自分の商品に置き換えたら、ホームからまとめて削除できます。',
        }
        : setup.opened
            ? { key: 'open', icon: 'store', title: 'ショップは公開しています', body: 'お客さんはもうショップを見られます。注文が入ると、ホームと「注文」に並びます。' }
            : { key: 'preparing', icon: 'lock', title: 'ショップはまだ準備中です', body: '公開するまで、お客さんにはショップが見えません。準備中のショップは、パスワードを入力した人だけが見られます。' };

    return [
        {
            key: 'hello',
            icon: 'home',
            title: 'EC-SUGAR へようこそ',
            body: '商品を並べ、注文を受け、お支払いを受けて発送するまでを、この管理画面で行います。メニューのホーム・注文・商品・写真・デザイン・App から始めます。',
        },
        second,
        {
            key: 'next',
            icon: 'list',
            title: '次にやること',
            body: 'ホームの「開設の手順」を上から順に進めます。終わった手順には自動でチェックが付きます。困ったときは、メニューの下の「ヘルプ」を開いてください。',
        },
    ];
}

// 「あとで見る」を押した店（この画面を開いている間だけ覚える。読み込み直すとまた出る。ブラウザの保存領域は使わない。ADR 0016）
export const welcomeLater = new Set();
