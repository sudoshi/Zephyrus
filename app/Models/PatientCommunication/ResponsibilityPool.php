<?php

namespace App\Models\PatientCommunication;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResponsibilityPool extends Model
{
    use AssignsExternalUuid;

    public const EXTERNAL_UUID_COLUMN = 'pool_uuid';

    protected $table = 'patient_communications.responsibility_pools';

    protected $primaryKey = 'responsibility_pool_id';

    protected $fillable = [
        'pool_uuid',
        'pool_key_digest',
        'topic_code',
        'display_name',
        'routing_policy_version',
        'scope_type',
        'facility_key',
        'unit_id',
        'status',
        'response_target_minutes',
        'escalation_target_minutes',
    ];

    protected $hidden = [
        'pool_key_digest',
    ];

    protected $casts = [
        'unit_id' => 'integer',
        'response_target_minutes' => 'integer',
        'escalation_target_minutes' => 'integer',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(PoolMembership::class, 'responsibility_pool_id', 'responsibility_pool_id');
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(ThreadWorkItem::class, 'responsibility_pool_id', 'responsibility_pool_id');
    }
}
