<?php

namespace App\Models\Eddy;

use Illuminate\Database\Eloquent\Model;

class EddyUserProfile extends Model
{
    protected $table = 'eddy.eddy_user_profiles';

    protected $primaryKey = 'eddy_user_profile_id';

    protected $guarded = [];

    protected $casts = [
        'focus_units' => 'array',
        'role_context' => 'array',
        'interaction_preferences' => 'array',
        'frequently_used' => 'array',
        'learned_at' => 'datetime',
    ];
}
