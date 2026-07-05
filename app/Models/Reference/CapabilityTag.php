<?php

namespace App\Models\Reference;

use App\Casts\PgTextArray;
use Illuminate\Database\Eloquent\Model;

/**
 * Structured bed/room/facility-space capability vocabulary
 * (ventilator, crrt, negative_pressure, stroke_priority, ...).
 * Projected into hosp_ref.capability_tags from config/hospital/capability-tags.php.
 */
class CapabilityTag extends Model
{
    protected $table = 'hosp_ref.capability_tags';

    protected $primaryKey = 'tag_code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'tag_code',
        'tag_category',
        'display_name',
        'description',
        'applies_to',
    ];

    protected $casts = [
        'applies_to' => PgTextArray::class,
    ];
}
