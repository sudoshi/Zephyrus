<?php

namespace App\Models\Governance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GovernedChangeExecution extends Model
{
    protected $table = 'governance.change_executions';

    protected $primaryKey = 'change_execution_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'executed_by_user_id' => 'integer',
        'executed_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(GovernedChangeRequest::class, 'change_request_uuid', 'change_request_uuid');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }
}
