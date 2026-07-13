<?php

namespace App\Models\Governance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessReviewRemediation extends Model
{
    protected $table = 'governance.access_review_remediations';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'result' => 'array',
        'executed_at' => 'immutable_datetime',
    ];

    public function decision(): BelongsTo
    {
        return $this->belongsTo(AccessReviewDecision::class, 'decision_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }
}
