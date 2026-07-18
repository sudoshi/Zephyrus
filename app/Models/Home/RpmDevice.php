<?php

namespace App\Models\Home;

use App\Casts\JsonObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RpmDevice extends Model
{
    protected $table = 'prod.rpm_devices';

    protected $primaryKey = 'rpm_device_id';

    protected $guarded = [];

    protected $casts = [
        'battery_pct' => 'integer',
        'last_transmission_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
        'is_deleted' => 'boolean',
    ];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(RpmKit::class, 'rpm_kit_id', 'rpm_kit_id');
    }
}
