<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Middleware\EnterTenant;
use App\Admin\Http\Middleware\RequireRecentConfirmation;
use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorAccess;
use App\Core\Plugin\Services\AdminFiles;
use App\Core\Plugin\Services\AdminForms;
use App\Core\Plugin\Services\AdminPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理画面の App の画面（EnterTenant の後。ADR 0051）。この店で使っている App のうち、画面を持つものの一覧と 1 件と操作。
 * 足す・直すの欄を持つ App（よくある質問など）は、一覧に足す欄、1 件に直す欄（form）も返す。
 * 見るのは閲覧、操作と足す・直すは運用の段階から（経路の印）。取り消せない操作は直前の再確認も。画面は核の部品が描き、App の画面のコードは読み込まない。
 * 使っていない・画面の無い App、無い記録、今は押せない操作、足す口の無い App への足す・直すはどれも同じ 404。
 * CSV の書き出し・ファイルの取り込みの口を持つ App（配送の CSV など）は、一覧に書き出しの欄（export）と取り込みの欄（import）も返す。
 * 書き出しは運用の段階から（届け先などの個人情報が入るので、経路に印を付ける）。書き出したこと（誰が・どの App・何件。中身は残さない）を中央の監査ログに残す。
 * 1 件の個人情報の印の付いた事実と表（お問い合わせの名前・メール・本文など。2026-11-05）は、運用の段階の人にだけ出し（閲覧の人には隠したと知らせる）、
 * 出したら見た事実（誰が・どの App のどの記録。中身は残さない）を中央の監査ログに残す（残せなければ答えも返さない。注文・お客さんの個人情報の表示と同じ）
 */
final class AppPageController
{
    public function __construct(
        private readonly AdminPages $pages,
        private readonly AdminForms $forms,
        private readonly AdminFiles $files,
        private readonly AuditLog $audit,
    ) {}

    public function menu(): JsonResponse
    {
        return response()->json(['pages' => $this->pages->menu()]);
    }

    public function index(Request $request, string $plugin): JsonResponse
    {
        $this->pages->definition($plugin);
        $data = $request->validate([
            'filter' => ['sometimes', 'nullable', 'string', 'regex:/\A[a-z][a-z0-9_]{0,31}\z/'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);

        return response()->json($this->pages->list($plugin, $data['filter'] ?? null, (int) ($data['page'] ?? 1)) + [
            'form' => $this->forms->blank($plugin),
            // 読む・探す の欄（チケットの受付の QR・番号など）
            'lookup' => $this->pages->lookupForm($plugin),
            'export' => $this->files->exportForm($plugin),
            'import' => $this->files->importForm($plugin),
        ]);
    }

    public function show(Request $request, string $plugin, string $recordId): JsonResponse
    {
        $record = $this->viewed($request, $plugin, $recordId, $this->pages->record($plugin, $recordId, self::personal($request)));

        return response()->json(['record' => $record, 'form' => $this->forms->of($plugin, $recordId)]);
    }

    public function store(Request $request, string $plugin): JsonResponse
    {
        $id = $this->forms->create($plugin, self::values($request), TenantActor::of($request));

        return response()->json(['id' => strtolower($id)], 201);
    }

    public function update(Request $request, string $plugin, string $recordId): JsonResponse
    {
        $this->forms->update($plugin, $recordId, self::values($request), TenantActor::of($request));

        return $this->show($request, $plugin, $recordId);
    }

    /**
     * CSV を書き出す。ファイルとして返し、書き出したことを監査ログに残す（残せなければファイルも返さない）
     */
    public function export(Request $request, string $plugin): Response
    {
        // 使っていない App は、入力の形より先に同じ 404
        $this->pages->definition($plugin);
        $file = $this->files->export($plugin, self::values($request), TenantActor::of($request));
        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);
        $this->audit->record('operator.app_file_exported', $operator, ['plugin' => $plugin, 'file' => $file['filename'], 'rows' => $file['rows']], (string) tenant()?->getTenantKey(), $request->ip());

        return response($file['body'], 200, [
            'Content-Type' => 'text/csv; charset='.$file['charset'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * ファイルを取り込み、確かめの記録を作る。状態はまだ変えない（その 1 件の画面の操作で変える）
     */
    public function import(Request $request, string $plugin): JsonResponse
    {
        $this->pages->definition($plugin);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.intdiv(AdminFiles::IMPORT_MAX_BYTES, 1024)],
            'values' => ['sometimes', 'array', 'max:20'],
        ], ['file.max' => 'ファイルが大きすぎます（'.intdiv(AdminFiles::IMPORT_MAX_BYTES, 1024).' KB まで）。分けて取り込んでください。'], ['file' => 'ファイル']);
        $file = $data['file'];
        assert($file instanceof UploadedFile);
        $id = $this->files->import($plugin, (string) $file->get(), $data['values'] ?? [], TenantActor::of($request));

        return response()->json(['id' => strtolower($id)], 201);
    }

    public function act(Request $request, string $plugin, string $recordId, string $action): JsonResponse
    {
        // 取り消せない操作（解約など）は、パスキーでの直前の再確認の後だけ（注文の取消と同じ）。
        // 操作で記録が無くなった（消す）ときは record も form も null。欄のある操作の値は values で来る（形は核が欄ごとに確かめる）
        $input = $request->validate(['values' => ['sometimes', 'array', 'max:20']])['values'] ?? [];
        // お金に触れる操作（当日払いの受け取り ・返金 ）は「お金」の段階の人だけで、直前の再確認の後だけ（注文の入金の確認・取消の経路と同じ。ADR 0024）
        $money = static function () use ($request): void {
            $level = $request->attributes->get(EnterTenant::LEVEL);
            abort_unless(is_string($level) && (OperatorAccess::LEVELS[$level] ?? 0) >= OperatorAccess::LEVELS['money'], 403, 'お金を扱う操作ができるのは、この店の「会計」の権限のある人だけです。');
            RequireRecentConfirmation::require($request);
        };
        $record = $this->pages->act($plugin, $recordId, $action, TenantActor::of($request), static fn () => RequireRecentConfirmation::require($request), self::personal($request), $input, $money);
        $record = $record === null ? null : $this->viewed($request, $plugin, $recordId, $record);

        return response()->json(['record' => $record, 'form' => $record === null ? null : $this->forms->of($plugin, $recordId)]);
    }

    /**
     * 読む・探す（チケットの受付で QR の中身・番号・名前から 1 件へ）。運用の段階から（経路の印）。答えは 1 件の ID だけ
     */
    public function lookup(Request $request, string $plugin): JsonResponse
    {
        $this->pages->definition($plugin);
        $data = $request->validate(['input' => ['required', 'string', 'max:500']], [], ['input' => '読み取った文字']);

        return response()->json(['id' => $this->pages->lookup($plugin, (string) $data['input'], TenantActor::of($request))]);
    }

    /**
     * この店での段階が運用から上か（個人情報の印の付いた事実と表を見てよいか。ADR 0024 の「個人情報の表示」）
     */
    private static function personal(Request $request): bool
    {
        $level = $request->attributes->get(EnterTenant::LEVEL);

        return is_string($level) && isset(OperatorAccess::LEVELS[$level]) && OperatorAccess::LEVELS[$level] >= OperatorAccess::LEVELS['operate'];
    }

    /**
     * 個人情報を出した 1 件なら、見た事実を監査ログに残してから返す
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function viewed(Request $request, string $plugin, string $recordId, array $record): array
    {
        if (($record['personal'] ?? null) === 'shown') {
            $operator = Auth::guard('operator')->user();
            assert($operator instanceof Operator);
            $this->audit->record('operator.personal_data_viewed', $operator, ['subject' => 'app_record', 'plugin' => $plugin, 'record_id' => strtolower($recordId)], (string) tenant()?->getTenantKey(), $request->ip());
        }

        return $record;
    }

    /**
     * 欄の値（名前 => 値）。中身の形は核の AdminForms が欄ごとに確かめる
     *
     * @return array<mixed>
     */
    private static function values(Request $request): array
    {
        $data = $request->validate(['values' => ['present', 'array', 'max:20']]);

        return $data['values'];
    }
}
