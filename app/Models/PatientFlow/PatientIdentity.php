<?php

namespace App\Models\PatientFlow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientIdentity extends Model
{
    protected $table = 'flow_core.patient_identities';

    protected $primaryKey = 'patient_ref';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'deidentified' => 'boolean',
        'metadata' => 'array',
    ];

    public function encounters(): HasMany
    {
        return $this->hasMany(FlowEncounter::class, 'patient_ref', 'patient_ref');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FlowEvent::class, 'patient_ref', 'patient_ref');
    }
}
