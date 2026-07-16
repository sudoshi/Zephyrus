<?php

namespace App\Models\Governance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AccessReviewDecision extends Model
{
    protected $table = 'governance.access_review_decisions';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'decided_at' => 'immutable_datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(AccessReviewItem::class, 'campaign_item_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function remediation(): HasOne
    {
        return $this->hasOne(AccessReviewRemediation::class, 'decision_id');
    }
}
