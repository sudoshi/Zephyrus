<?php

namespace App\Models\Ancillary;

use App\Casts\JsonObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AncillaryMilestoneType extends Model
{
    protected $table = 'hosp_ref.ancillary_milestone_types';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'ordinal' => 'integer',
        'is_terminal' => 'boolean',
        'is_optional' => 'boolean',
        'is_minimum_feed' => 'boolean',
        'source_precedence' => 'array',
        'process_ids' => 'array',
        'display_metadata' => JsonObject::class,
    ];

    public function milestones(): HasMany
    {
        return $this->hasMany(AncillaryMilestone::class, 'milestone_code', 'code');
    }
}
