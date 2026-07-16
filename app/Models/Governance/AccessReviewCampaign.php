<?php

namespace App\Models\Governance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessReviewCampaign extends Model
{
    protected $table = 'governance.access_review_campaigns';

    protected $guarded = [];

    protected $casts = [
        'review_period_start' => 'immutable_date',
        'review_period_end' => 'immutable_date',
        'due_at' => 'immutable_datetime',
        'snapshot_at' => 'immutable_datetime',
        'opened_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'campaign_uuid';
    }

    public function items(): HasMany
    {
        return $this->hasMany(AccessReviewItem::class, 'campaign_id');
    }

    public function exports(): HasMany
    {
        return $this->hasMany(AccessReviewExport::class, 'campaign_id');
    }

    public function primaryReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_reviewer_user_id');
    }

    public function alternateReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'alternate_reviewer_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
