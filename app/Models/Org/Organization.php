<?php

namespace App\Models\Org;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Layer 1: an IDN / health-system that owns markets and facilities.
 */
class Organization extends Model
{
    protected $table = 'hosp_org.organizations';

    protected $primaryKey = 'organization_id';

    protected $fillable = [
        'organization_key',
        'name',
        'short_name',
        'kind',
        'headquarters_state',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function markets(): HasMany
    {
        return $this->hasMany(Market::class, 'organization_id', 'organization_id');
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class, 'organization_id', 'organization_id');
    }
}
