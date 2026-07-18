<?php

namespace App\Models\Home;

use App\Casts\JsonObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RpmKit extends Model
{
    protected $table = 'prod.rpm_kits';

    protected $primaryKey = 'rpm_kit_id';

    protected $guarded = [];

    protected $casts = [
        'battery_pct' => 'integer',
        'last_seen_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
        'is_deleted' => 'boolean',
    ];

    public function devices(): HasMany
    {
        return $this->hasMany(RpmDevice::class, 'rpm_kit_id', 'rpm_kit_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(RpmEnrollment::class, 'rpm_kit_id', 'rpm_kit_id');
    }
}
