// 商品の説明のブロック（ADR 0032）: サーバーの形と、画面で編集する形の写し替え。形を確認するのはサーバー（ProductDescription）
// 画面の形はブロックごとに key（並べ替えても入力欄が入れ替わらないための印。サーバーへは送らない）を持つ。箇条書きは 1 行に 1 つの文で編集する

export const BLOCK_TYPES = [
    { type: 'paragraph', label: '段落' },
    { type: 'heading', label: '見出し' },
    { type: 'list', label: '箇条書き' },
    { type: 'image', label: '写真' },
    { type: 'table', label: '表' },
    { type: 'note', label: '注意書き' },
];

export const BLOCK_LABELS = Object.fromEntries(BLOCK_TYPES.map((t) => [t.type, t.label]));

let counter = 0;

function nextKey() {
    counter += 1;
    return `b${counter}`;
}

// 新しいブロック（空の中身）
export function newBlock(type) {
    const key = nextKey();

    switch (type) {
        case 'heading': return { key, type, level: 2, text: '' };
        case 'list': return { key, type, style: 'bullet', lines: '' };
        case 'image': return { key, type, media_id: null, caption: '' };
        case 'table': return { key, type, header: true, caption: '', rows: [['', ''], ['', '']] };
        case 'note': return { key, type, tone: 'info', text: '' };
        default: return { key, type: 'paragraph', text: '' };
    }
}

// サーバーの形から画面の形へ
export function fromServer(blocks) {
    return (blocks ?? []).map((block) => {
        const key = nextKey();

        switch (block.type) {
            case 'list': return { key, type: 'list', style: block.style, lines: block.items.join('\n') };
            case 'image': return { key, type: 'image', media_id: block.media_id, caption: block.caption ?? '' };
            case 'table': return { key, type: 'table', header: block.header, caption: block.caption ?? '', rows: block.rows.map((row) => [...row]) };
            default: return { key, ...block };
        }
    });
}

// 画面の形からサーバーの形へ（箇条書きの空の行は外す。ほかの空はそのまま送り、サーバーが場所つきで断る）
export function toServer(blocks) {
    return blocks.map((block) => {
        switch (block.type) {
            case 'paragraph': return { type: 'paragraph', text: block.text };
            case 'heading': return { type: 'heading', level: block.level, text: block.text };
            case 'list': return { type: 'list', style: block.style, items: block.lines.split('\n').map((line) => line.trim()).filter((line) => line !== '') };
            case 'image': return { type: 'image', media_id: block.media_id, caption: block.caption === '' ? null : block.caption };
            case 'table': return { type: 'table', header: block.header, caption: block.caption === '' ? null : block.caption, rows: block.rows.map((row) => [...row]) };
            case 'note': return { type: 'note', tone: block.tone, text: block.text };
            default: return block;
        }
    });
}

// 画面の形のブロックの文をつないだ素の文（検索の結果の自動の説明の試し見。ADR 0061。写真と表は入れない）
export function plainOf(blocks) {
    return blocks.map((block) => (block.type === 'list' ? block.lines : (['paragraph', 'heading', 'note'].includes(block.type) ? block.text : ''))).filter((text) => text).join(' ');
}

// 仕様の表: 画面の行（key つき）とサーバーの形。空の行もそのまま送る（誤りの場所が画面の行の番号とずれないように。サーバーが「消してください」と断る）
export function specsFromServer(specs) {
    return (specs ?? []).map((spec) => ({ key: nextKey(), label: spec.label, value: spec.value }));
}

export function newSpec() {
    return { key: nextKey(), label: '', value: '' };
}

export function specsToServer(specs) {
    return specs.map((spec) => ({ label: spec.label, value: spec.value }));
}

// 並びの中で 1 つ動かした新しい並び
export function moved(list, index, step) {
    const target = index + step;

    if (target < 0 || target >= list.length) {
        return list;
    }

    const next = [...list];
    [next[index], next[target]] = [next[target], next[index]];

    return next;
}

// サーバーの誤り（description_blocks.3.text など）のうち、そのブロックの最初の 1 つ
export function errorAt(errors, prefix) {
    const key = Object.keys(errors).find((name) => name === prefix || name.startsWith(`${prefix}.`));

    return key ? errors[key][0] : null;
}
