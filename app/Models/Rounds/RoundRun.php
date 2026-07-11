<?php

namespace App\Models\Rounds;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoundRun extends Model
{
    public const STATUSES = ['draft', 'scheduled', 'active', 'paused', 'closing', 'completed', 'cancelled'];

    public const TERMINAL_STATUSES = ['completed', 'cancelled'];

    /** Allowed FSM transitions (§6.3). Cancel is allowed from any non-terminal state. */
    public const TRANSITIONS = [
        'draft' => ['scheduled', 'active', 'cancelled'],
        'scheduled' => ['active', 'cancelled'],
        'active' => ['paused', 'closing', 'completed', 'cancelled'],
        'paused' => ['active', 'cancelled'],
        'closing' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    protected $table = 'rounds.runs';

    protected $primaryKey = 'run_id';

    protected $fillable = [
        'run_uuid', 'template_id', 'template_version', 'facility_key',
        'scope_type', 'scope_key', 'scope_label', 'mode', 'status',
        'planned_start_at', 'window_end_at', 'started_at', 'completed_at',
        'cancelled_at', 'queue_version', 'source_cutoff_at',
        'completion_exception', 'created_by', 'metadata',
    ];

    protected $casts = [
        'template_id' => 'integer',
        'template_version' => 'integer',
        'planned_start_at' => 'datetime',
        'window_end_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'queue_version' => 'integer',
        'source_cutoff_at' => 'datetime',
        'completion_exception' => 'array',
        'created_by' => 'integer',
        'metadata' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(RoundTemplate::class, 'template_id', 'template_id');
    }

    public function patients(): HasMany
    {
        return $this->hasMany(RoundPatient::class, 'run_id', 'run_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(RoundParticipant::class, 'run_id', 'run_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(RoundTask::class, 'run_id', 'run_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::TERMINAL_STATUSES);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
