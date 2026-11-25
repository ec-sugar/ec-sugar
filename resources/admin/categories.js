// カテゴリの木を、字下げの深さつきの平らな並びにする（表や選択肢に使う）
export function flatten(tree, depth = 0, parentId = null) {
    return tree.flatMap((node, index) => [
        { ...node, depth, parentId, index, siblings: tree.length },
        ...flatten(node.children, depth + 1, node.id),
    ]);
}

// その下（子や孫）の ID。動かす先の候補から外す
export function descendantIds(node) {
    return node.children.flatMap((child) => [child.id, ...descendantIds(child)]);
}
