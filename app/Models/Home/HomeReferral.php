<?php

namespace App\Models\Home;

use App\Casts\JsonObject;
use App\Models\Encounter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HomeReferral extends Model
{
    protected $table = 'prod.home_referrals';

    protected $primaryKey = 'home_referral_id';

    protected $guarded = [];

    protected $casts = [
        'screening' => JsonObject::class,
        'referred_at' => 'immutable_datetime',
        'status_changed_at' => 'immutable_datetime',
        'activated_at' => 'immutable_datetime',
        'declined_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
        'is_deleted' => 'boolean',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(HomeProgram::class, 'home_program_id', 'home_program_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id', 'encounter_id');
    }

    public function episode(): HasOne
    {
        return $this->hasOne(HomeEpisode::class, 'home_referral_id', 'home_referral_id');
    }
}
