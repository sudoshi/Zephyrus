<?php

namespace App\Models\Governance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only Zephyrus/Eddy AI provider policy version (PostgreSQL trigger
 * enforced). The eddy.* provider/surface rows are a projection of the latest
 * non-proposal version; rollback is a NEW version referencing a prior one.
 */
class AiProviderPolicyVersion extends Model
{
    protected $table = 'governance.ai_provider_policy_versions';

    protected $primaryKey = 'ai_provider_policy_version_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'version_number' => 'integer',
        'previous_version_id' => 'integer',
        'rolled_back_to_version_id' => 'integer',
        'policy' => 'array',
        'effective_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'created_by_user_id' => 'integer',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function governedChange(): BelongsTo
    {
        return $this->belongsTo(GovernedChangeRequest::class, 'governed_change_request_uuid', 'change_request_uuid');
    }
}
