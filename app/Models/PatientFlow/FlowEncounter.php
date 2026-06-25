<?php

namespace App\Models\PatientFlow;

use App\Models\Encounter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowEncounter extends Model
{
    protected $table = 'flow_core.encounters';

    protected $primaryKey = 'encounter_ref';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientIdentity::class, 'patient_ref', 'patient_ref');
    }

    public function prodEncounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'prod_encounter_id', 'encounter_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FlowEvent::class, 'encounter_ref', 'encounter_ref');
    }
}
