<?php

namespace App\Models\Eddy;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-call cloud-cost + redaction audit (append-only). Local (Ollama) calls are
 * not logged here — they have no cost. `sanitizer_redaction_count > 0` on any row
 * is a compliance signal (PHI scrubbed before egress).
 */
class EddyCloudUsage extends Model
{
    protected $table = 'eddy.eddy_cloud_usage';

    protected $primaryKey = 'eddy_cloud_usage_id';

    /** Append-only — created_at managed, no updated_at column. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'cost_usd' => 'float',
        'sanitizer_redaction_count' => 'integer',
        'response_latency_ms' => 'float',
        'usage_metadata' => 'array',
        'created_at' => 'datetime',
    ];
}
