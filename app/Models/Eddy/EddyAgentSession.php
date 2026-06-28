<?php

namespace App\Models\Eddy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The long-lived agentic session ledger (port of Parthenon's agent_sessions).
 * One session => many ops.agent_runs. The /ingest telemetry path increments the
 * running cost/token totals.
 */
class EddyAgentSession extends Model
{
    protected $table = 'eddy.eddy_agent_sessions';

    protected $primaryKey = 'eddy_agent_session_id';

    protected $guarded = [];

    protected $casts = [
        'cost_usd' => 'float',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'context_json' => 'array',
        'last_active_at' => 'datetime',
    ];

    public function scopeForSubject(Builder $query, string $profile, string $subjectType, ?int $subjectId): Builder
    {
        return $query->where('profile', $profile)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId);
    }

    /** Increment running totals from an /ingest telemetry callback. */
    public function recordUsage(float $costUsd, int $tokensIn, int $tokensOut): void
    {
        $this->increment('cost_usd', $costUsd);
        $this->increment('tokens_in', $tokensIn);
        $this->increment('tokens_out', $tokensOut);
        $this->forceFill(['last_active_at' => now()])->save();
    }
}
