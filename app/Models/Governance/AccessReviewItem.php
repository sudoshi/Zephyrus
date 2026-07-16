<?php

namespace App\Models\Governance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AccessReviewItem extends Model
{
    protected $table = 'governance.access_review_items';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'entitlement_snapshot' => 'array',
        'risk_flags' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'item_uuid';
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AccessReviewCampaign::class, 'campaign_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function decision(): HasOne
    {
        return $this->hasOne(AccessReviewDecision::class, 'campaign_item_id');
    }
}
