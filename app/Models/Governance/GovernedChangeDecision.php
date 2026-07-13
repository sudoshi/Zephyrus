<?php

namespace App\Models\Governance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GovernedChangeDecision extends Model
{
    protected $table = 'governance.change_decisions';

    protected $primaryKey = 'change_decision_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'decided_by_user_id' => 'integer',
        'decided_at' => 'immutable_datetime',
    ];

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(GovernedChangeRequest::class, 'change_request_uuid', 'change_request_uuid');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
