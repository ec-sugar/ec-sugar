<?php

declare(strict_types=1);

namespace App\Admin\Http\Requests;

use EcSugar\PluginProductCsv\PhotoArchive;
use EcSugar\PluginProductCsv\ProductCsvReader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * 商品の CSV を上げる（docs/design/product-csv.md）。ここではファイルが 1 つと大きさの上限だけを見る。中身の形は核（ProductCsvReader）が見る。
 * 写真の ZIP（photos）も一緒に上げられる。大きさの上限だけをここで見て、中身（数・名前・展開した大きさ・画像か）は核（PhotoArchive）が見る
 */
final class ListingCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 認可は経路のミドルウェア（運用の段階）
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // 拡張子や MIME（ファイルの種類の申告）は信じない（表計算ソフトごとに違う）。中身は核が CSV として読めるかで決める
            'file' => ['required', 'file', 'max:'.(ProductCsvReader::MAX_BYTES / 1024)],
            // ZIP も申告は信じない（中身は ZipArchive が ZIP として開けるかで決める）
            'photos' => ['nullable', 'file', 'max:'.(PhotoArchive::MAX_BYTES / 1024)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['file' => 'CSV のファイル', 'photos' => '写真の ZIP'];
    }

    public function bytes(): string
    {
        $file = $this->file('file');
        $bytes = $file instanceof UploadedFile ? $file->get() : false;

        return is_string($bytes) ? $bytes : '';
    }

    /**
     * 上げた ZIP の一時のファイルの道筋（上げていなければ null）
     */
    public function photosPath(): ?string
    {
        $file = $this->file('photos');

        return $file instanceof UploadedFile && $file->isValid() ? $file->getRealPath() ?: null : null;
    }
}
