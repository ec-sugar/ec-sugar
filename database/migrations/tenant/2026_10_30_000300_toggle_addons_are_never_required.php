<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 付ける（toggle）のオプションは必須にしない（運営の判断 2026-10-30）。
 *
 * 付けるは「付けない」も選び方の 1 つで、必須にすると付けないとカートに入れられなくなる。
 * 今ある値は false に直し、この後は DB の CHECK でも断る（核の AddonService も断る）。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('listing_addons')->where('kind', 'toggle')->where('required', true)->update(['required' => false]);
        DB::statement("ALTER TABLE listing_addons ADD CONSTRAINT listing_addons_toggle_not_required CHECK (kind <> 'toggle' OR required = false)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE listing_addons DROP CONSTRAINT IF EXISTS listing_addons_toggle_not_required');
    }
};
