<?php

namespace App\Models\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MobileTokenSession extends Model
{
    protected $table = 'prod.mobile_token_sessions';

    protected $primaryKey = 'mobile_token_session_id';

    protected $attributes = [
        'status' => 'active',
    ];

    protected $fillable = [
        'session_uuid',
        'user_id',
        'token_family_uuid',
        'access_token_id',
        'refresh_token_id',
        'status',
        'installation_uuid',
        'platform',
        'device_name',
        'app_version',
        'os_version',
        'environment',
        'last_seen_at',
        'expires_at',
        'revoked_at',
        'revocation_reason',
    ];

    protected $hidden = [
        'access_token_id',
        'refresh_token_id',
    ];

    protected $casts = [
        'access_token_id' => 'integer',
        'refresh_token_id' => 'integer',
        'last_seen_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MobileTokenSession $session): void {
            if (blank($session->session_uuid)) {
                $session->session_uuid = (string) Str::uuid7();
            }
            if (blank($session->token_family_uuid)) {
                $session->token_family_uuid = (string) Str::uuid7();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where('expires_at', '>', now());
    }
}
