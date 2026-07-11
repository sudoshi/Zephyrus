<?php

namespace App\Models\Rounds;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoundPatient extends Model
{
    public const STATUSES = ['queued', 'in_progress', 'awaiting_input', 'ready_for_review', 'rounded', 'deferred', 'skipped'];

    /**
     * Allowed FSM transitions (§6.3). Reopen is modeled as rounded ->
     * in_progress with a `patient.reopened` audit event.
     */
    public const TRANSITIONS = [
        'queued' => ['in_progress', 'awaiting_input', 'deferred', 'skipped'],
        'in_progress' => ['awaiting_input', 'ready_for_review', 'rounded', 'deferred', 'skipped'],
        'awaiting_input' => ['in_progress', 'ready_for_review', 'deferred', 'skipped'],
        'ready_for_review' => ['in_progress', 'rounded', 'deferred', 'skipped'],
        'rounded' => ['in_progress'],
        'deferred' => ['queued'],
        'skipped' => ['queued'],
    ];

    protected $table = 'rounds.patients';

    protected $primaryKey = 'round_patient_id';

    protected $fillable = [
        'round_patient_uuid', 'run_id', 'encounter_ref', 'prod_encounter_id',
        'patient_ref', 'snapshot_unit_id', 'snapshot_facility_space_id',
        'snapshot_service_line_code', 'snapshot_room', 'snapshot_bed', 'status',
        'priority_score', 'priority_band', 'priority_reasons', 'pinned_by',
        'pinned_at', 'pin_reason', 'queue_position', 'eta_window_start',
        'eta_window_end', 'estimated_duration_minutes', 'inclusion', 'version',
        'rounded_by', 'rounded_at', 'status_reason', 'metadata',
    ];

    protected $casts = [
        'run_id' => 'integer',
        'prod_encounter_id' => 'integer',
        'snapshot_unit_id' => 'integer',
        'snapshot_facility_space_id' => 'integer',
        'priority_score' => 'float',
        'priority_band' => 'integer',
        'priority_reasons' => 'array',
        'pinned_by' => 'integer',
        'pinned_at' => 'datetime',
        'queue_position' => 'integer',
        'eta_window_start' => 'datetime',
        'eta_window_end' => 'datetime',
        'estimated_duration_minutes' => 'integer',
        'inclusion' => 'array',
        'version' => 'integer',
        'rounded_by' => 'integer',
        'rounded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(RoundRun::class, 'run_id', 'run_id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(RoundContribution::class, 'round_patient_id', 'round_patient_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(RoundQuestion::class, 'round_patient_id', 'round_patient_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(RoundTask::class, 'round_patient_id', 'round_patient_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(RoundParticipant::class, 'round_patient_id', 'round_patient_id');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
