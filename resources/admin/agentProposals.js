// AI エージェントの案（ADR 0101）の言葉。一覧と 1 件の画面で使う。状態の名前はサーバー（AgentProposals::label）と同じ
export const PROPOSAL_STATUS = {
    pending: { label: '承認待ち', tone: 'warn' },
    approved: { label: '承認・反映済み', tone: 'ok' },
    rejected: { label: '却下', tone: 'neutral' },
    expired: { label: '期限切れ', tone: 'neutral' },
    failed: { label: '反映に失敗', tone: 'danger' },
};

export const PROPOSAL_TOOLS = {
    create_product: '商品の登録',
    update_product: '商品の編集',
    adjust_stock: '在庫の変更',
    mark_shipped: '発送済みにする',
    cancel_order: '注文の取消',
    refund_order: '返金する',
    create_coupon: 'クーポンの作成',
};
