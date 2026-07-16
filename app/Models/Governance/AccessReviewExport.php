<?php

namespace App\Models\Governance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessReviewExport extends Model
{
    protected $table = 'governance.access_review_exports';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'exported_at' => 'immutable_datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AccessReviewCampaign::class, 'campaign_id');
    }

    public function exportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by_user_id');
    }
}
