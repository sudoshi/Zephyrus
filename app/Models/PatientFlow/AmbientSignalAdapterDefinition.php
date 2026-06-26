<?php

namespace App\Models\PatientFlow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AmbientSignalAdapterDefinition extends Model
{
    protected $table = 'flow_realtime.ambient_signal_adapters';

    protected $primaryKey = 'ambient_signal_adapter_id';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'base_confidence' => 'decimal:4',
        'capability_payload' => 'array',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(AmbientSignalEvent::class, 'ambient_signal_adapter_id', 'ambient_signal_adapter_id');
    }
}
