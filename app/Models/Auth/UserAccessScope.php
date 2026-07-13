<?php

namespace App\Models\Auth;

use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Effective-dated, revocable administrative access boundary. Rows are retained
 * after revocation so access-review and incident evidence remains reconstructable.
 */
class UserAccessScope extends Model
{
    protected $table = 'prod.user_access_scopes';

    protected $fillable = [
        'user_id',
        'organization_id',
        'facility_id',
        'granted_by_user_id',
        'grant_reason',
        'valid_from',
        'valid_until',
        'revoked_at',
        'revoked_by_user_id',
        'revocation_reason',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'organization_id' => 'integer',
        'facility_id' => 'integer',
        'granted_by_user_id' => 'integer',
        'revoked_by_user_id' => 'integer',
        'valid_from' => 'immutable_datetime',
        'valid_until' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'organization_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id', 'facility_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function scopeEffective(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>', now());
            });
    }
}
