<?php

namespace App\Models\Home;

use App\Casts\JsonObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RpmObservation extends Model
{
    protected $table = 'prod.rpm_observations';

    protected $primaryKey = 'rpm_observation_id';

    protected $guarded = [];

    protected $casts = [
        'value' => 'float',
        'observed_at' => 'immutable_datetime',
        'received_at' => 'immutable_datetime',
        'is_breach' => 'boolean',
        'metadata' => JsonObject::class,
        'is_deleted' => 'boolean',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(RpmEnrollment::class, 'rpm_enrollment_id', 'rpm_enrollment_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(RpmDevice::class, 'rpm_device_id', 'rpm_device_id');
    }
}
