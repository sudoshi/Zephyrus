<?php

namespace App\Models\Org;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Layer 1: a geographic market within an organization.
 */
class Market extends Model
{
    protected $table = 'hosp_org.markets';

    protected $primaryKey = 'market_id';

    protected $fillable = [
        'organization_id',
        'market_key',
        'name',
        'region',
        'state',
        'metadata',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'organization_id');
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class, 'market_id', 'market_id');
    }
}
