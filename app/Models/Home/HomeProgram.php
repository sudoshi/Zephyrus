<?php

namespace App\Models\Home;

use App\Casts\JsonObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HomeProgram extends Model
{
    protected $table = 'prod.home_programs';

    protected $primaryKey = 'home_program_id';

    protected $guarded = [];

    protected $casts = [
        'payer_rules' => JsonObject::class,
        'conditions' => 'array',
        'zone_slot_capacity' => JsonObject::class,
        'slot_capacity' => 'integer',
        'is_active' => 'boolean',
        'metadata' => JsonObject::class,
        'is_deleted' => 'boolean',
    ];

    public function referrals(): HasMany
    {
        return $this->hasMany(HomeReferral::class, 'home_program_id', 'home_program_id');
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(HomeEpisode::class, 'home_program_id', 'home_program_id');
    }
}
