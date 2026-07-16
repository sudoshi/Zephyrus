<?php

namespace App\Models\Governance;

use App\Authorization\GovernedAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GovernedChangeRequest extends Model
{
    protected $table = 'governance.change_requests';

    protected $primaryKey = 'change_request_uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'action_type' => GovernedAction::class,
        'organization_id' => 'integer',
        'facility_id' => 'integer',
        'author_user_id' => 'integer',
        'requested_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function decision(): HasOne
    {
        return $this->hasOne(GovernedChangeDecision::class, 'change_request_uuid', 'change_request_uuid');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(GovernedChangeExecution::class, 'change_request_uuid', 'change_request_uuid');
    }
}
