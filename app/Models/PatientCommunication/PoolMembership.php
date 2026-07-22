<?php

namespace App\Models\PatientCommunication;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoolMembership extends Model
{
    use AssignsExternalUuid;

    public const EXTERNAL_UUID_COLUMN = 'membership_uuid';

    protected $table = 'patient_communications.pool_memberships';

    protected $primaryKey = 'pool_membership_id';

    protected $fillable = [
        'membership_uuid',
        'responsibility_pool_id',
        'staff_user_id',
        'membership_role',
        'availability_state',
        'can_claim',
        'can_reply',
        'can_reroute',
        'can_close',
        'effective_from',
        'effective_until',
    ];

    protected $casts = [
        'responsibility_pool_id' => 'integer',
        'staff_user_id' => 'integer',
        'can_claim' => 'boolean',
        'can_reply' => 'boolean',
        'can_reroute' => 'boolean',
        'can_close' => 'boolean',
        'effective_from' => 'immutable_datetime',
        'effective_until' => 'immutable_datetime',
    ];

    public function pool(): BelongsTo
    {
        return $this->belongsTo(ResponsibilityPool::class, 'responsibility_pool_id', 'responsibility_pool_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id', 'id');
    }

    public function scopeEffective(Builder $query): Builder
    {
        return $query
            ->where('availability_state', 'active')
            ->where('effective_from', '<=', now())
            ->where(function (Builder $window): void {
                $window->whereNull('effective_until')->orWhere('effective_until', '>', now());
            });
    }
}
