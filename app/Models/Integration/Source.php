<?php

namespace App\Models\Integration;

use App\Models\Raw\InboundMessage;
use App\Models\Raw\IngestRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $table = 'integration.sources';

    protected $primaryKey = 'source_id';

    protected $guarded = [];

    protected $casts = [
        'smart_supported' => 'boolean',
        'bulk_supported' => 'boolean',
        'subscriptions_supported' => 'boolean',
        'phi_allowed' => 'boolean',
        'metadata' => 'array',
    ];

    public function ingestRuns(): HasMany
    {
        return $this->hasMany(IngestRun::class, 'source_id', 'source_id');
    }

    public function inboundMessages(): HasMany
    {
        return $this->hasMany(InboundMessage::class, 'source_id', 'source_id');
    }

    public function canonicalEvents(): HasMany
    {
        return $this->hasMany(CanonicalEventRecord::class, 'source_id', 'source_id');
    }
}
