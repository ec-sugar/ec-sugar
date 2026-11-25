<?php

declare(strict_types=1);

namespace EcSugar\PluginAgeCheck\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 年齢の確かめが要る商品（ADR 0077）。変えるのは AgeCheckListings だけ
 *
 * @property string $listing_id
 * @property int $age
 */
final class AgeCheckListing extends Model
{
    protected $table = 'age_check_listings';

    protected $primaryKey = 'listing_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['listing_id', 'age'];

    protected $casts = ['age' => 'integer'];
}
