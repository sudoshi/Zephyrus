<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A registered mobile device (Hummingbird) for push delivery. One row per app
 * instance, keyed by push token. No PHI is stored on this model.
 */
class MobileDevice extends Model
{
    protected $table = 'prod.mobile_devices';

    protected $primaryKey = 'mobile_device_id';

    protected $fillable = [
        'device_uuid',
        'user_id',
        'platform',
        'push_token',
        'app_version',
        'os_version',
        'device_name',
        'locale',
        'last_seen_at',
        'revoked_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MobileDevice $device) {
            if (empty($device->device_uuid)) {
                $device->device_uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
