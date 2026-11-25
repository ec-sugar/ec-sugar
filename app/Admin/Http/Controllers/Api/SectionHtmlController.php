<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Core\Section\SectionHtml;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 区画の HTML の確かめ（EnterTenant の後）: 打った HTML を、保存とショップで使うのと同じ決まりで整えて返す。
 * 何も書かない（保存は今までどおりページの下書きの API。そこでも同じ整え方を通す）。
 *
 * - html: 整えた HTML（保存するとこの形になる）
 * - removed: 外したもの（管理画面の「自由な HTML」の欄の下に出す）
 * - tree: 整えた HTML の木（「見たまま書ける文」が DOM を要素ごとに組むため。管理画面は HTML の文字を DOM に書き込まない）
 *
 * 上限は保存・描くときと同じ（SectionHtml::clean の既定。字数は整えた後の形で数えるので、保存してある整えた値〔文字の参照で長い〕も通る）。
 * 保存で断るもの（大きすぎる・しまう形が長すぎる・深すぎる）は、ここでも同じ理由で 422
 */
final class SectionHtmlController
{
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'html' => ['present', 'nullable', 'string', 'max:'.SectionHtml::MAX_STORED_CHARS],
        ]);

        $clean = SectionHtml::clean((string) ($data['html'] ?? ''));

        if ($clean->refused !== null) {
            throw ValidationException::withMessages(['html' => $clean->refused]);
        }

        return response()->json([
            'html' => $clean->html,
            'removed' => $clean->removed,
            'tree' => SectionHtml::tree($clean->html),
        ]);
    }
}
