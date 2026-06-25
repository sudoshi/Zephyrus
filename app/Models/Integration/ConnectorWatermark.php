<?php

namespace App\Models\Integration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorWatermark extends Model
{
    protected $table = 'integration.connector_watermarks';

    protected $primaryKey = 'connector_watermark_id';

    protected $guarded = [];

    protected $casts = [
        'last_success_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }
}
