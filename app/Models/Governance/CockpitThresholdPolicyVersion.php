<?php

namespace App\Models\Governance;

use App\Models\Ops\MetricDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only cockpit threshold policy version (PostgreSQL trigger enforced).
 * The effective policy on ops.metric_definitions is a projection of the latest
 * non-proposal version; rollback is a NEW version referencing a prior one.
 */
class CockpitThresholdPolicyVersion extends Model
{
    protected $table = 'governance.cockpit_threshold_policy_versions';

    protected $primaryKey = 'cockpit_threshold_policy_version_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'metric_definition_id' => 'integer',
        'version_number' => 'integer',
        'previous_version_id' => 'integer',
        'rolled_back_to_version_id' => 'integer',
        'policy' => 'array',
        'effective_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'created_by_user_id' => 'integer',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(MetricDefinition::class, 'metric_definition_id', 'metric_definition_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function governedChange(): BelongsTo
    {
        return $this->belongsTo(GovernedChangeRequest::class, 'governed_change_request_uuid', 'change_request_uuid');
    }
}
