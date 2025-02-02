<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Reference\Service;
use Illuminate\Database\Eloquent\Casts\Attribute;

class ORCase extends Model
{
    protected $table = 'or_cases';

    protected $fillable = [
        'patient_name',
        'procedure_name',
        'provider_id',
        'room_id',
        'service_id',
        'status',
        'phase',
        'resource_status',
        'progress_percentage',
        'notes',
        'alerts',
        'scheduled_date',
        'scheduled_start_time',
        'expected_duration',
        'pre_procedure_location',
        'post_procedure_location',
        'safety_status',
        'journey_progress'
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'scheduled_start_time' => 'datetime',
        'alerts' => 'array',
        'progress_percentage' => 'integer',
        'expected_duration' => 'integer',
        'journey_progress' => 'integer'
    ];

    // Constants
    const STATUS_SCHEDULED = 'Scheduled';
    const STATUS_PRE_OP = 'Pre-Op';
    const STATUS_IN_PROGRESS = 'In Progress';
    const STATUS_RECOVERY = 'Recovery';
    const STATUS_COMPLETED = 'Completed';
    const STATUS_DELAYED = 'Delayed';

    const PHASE_PRE_OP = 'Pre-Op';
    const PHASE_PROCEDURE = 'Procedure';
    const PHASE_RECOVERY = 'Recovery';

    const SAFETY_STATUS_NORMAL = 'Normal';
    const SAFETY_STATUS_REVIEW = 'Review_Required';
    const SAFETY_STATUS_ALERT = 'Alert';

    // Relationships
    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function staff()
    {
        return $this->belongsToMany(User::class, 'case_staff')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function resources()
    {
        return $this->hasMany(CaseResource::class, 'case_id');
    }

    public function measurements()
    {
        return $this->hasMany(CaseMeasurement::class, 'case_id');
    }

    public function milestones()
    {
        return $this->hasMany(CareJourneyMilestone::class, 'case_id');
    }

    public function transports()
    {
        return $this->hasMany(CaseTransport::class, 'case_id');
    }

    public function timings()
    {
        return $this->hasMany(CaseTiming::class, 'case_id');
    }

    public function safetyNotes()
    {
        return $this->hasMany(CaseSafetyNote::class, 'case_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_SCHEDULED,
            self::STATUS_PRE_OP,
            self::STATUS_IN_PROGRESS,
            self::STATUS_DELAYED
        ]);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('scheduled_date', today());
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeDelayed($query)
    {
        return $query->where('status', self::STATUS_DELAYED);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopePreOp($query)
    {
        return $query->where('phase', self::PHASE_PRE_OP);
    }

    public function scopeInPhase($query, $phase)
    {
        return $query->where('phase', $phase);
    }

    public function scopeRequiringReview($query)
    {
        return $query->where('safety_status', '!=', self::SAFETY_STATUS_NORMAL);
    }

    public function scopeWithPendingMilestones($query)
    {
        return $query->whereHas('milestones', function ($q) {
            $q->whereNull('completed_at');
        });
    }

    public function scopeWithPendingTransport($query)
    {
        return $query->whereHas('transports', function ($q) {
            $q->where('status', 'Pending');
        });
    }

    public function scopeWithUnacknowledgedSafetyNotes($query)
    {
        return $query->whereHas('safetyNotes', function ($q) {
            $q->whereNull('acknowledged_at');
        });
    }

    // Accessors & Mutators
    protected function progressPercentage(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($this->phase === self::PHASE_RECOVERY) {
                    return 100;
                }
                return $value;
            }
        );
    }

    protected function resourceStatus(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($this->status === self::STATUS_DELAYED) {
                    return 'delayed';
                }
                return $value;
            }
        );
    }

    protected function journeyProgress(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null) {
                    return $this->calculateJourneyProgress();
                }
                return $value;
            }
        );
    }

    // Helper Methods
    public function updateProgress($percentage)
    {
        $this->progress_percentage = $percentage;
        $this->save();
    }

    public function addAlert($message, $severity = 'Medium')
    {
        $alerts = $this->alerts ?? [];
        $alerts[] = [
            'message' => $message,
            'severity' => $severity,
            'timestamp' => now()->toIso8601String()
        ];
        $this->alerts = $alerts;
        $this->save();

        // Create a safety note for high severity alerts
        if (in_array($severity, ['High', 'Critical'])) {
            $this->safetyNotes()->create([
                'note_type' => CaseSafetyNote::TYPE_SAFETY_ALERT,
                'content' => $message,
                'severity' => $severity
            ]);
        }
    }

    public function assignStaff($userId, $role)
    {
        $this->staff()->attach($userId, ['role' => $role]);
    }

    public function trackResource($name, $status = 'onTime')
    {
        return $this->resources()->create([
            'resource_name' => $name,
            'status' => $status
        ]);
    }

    public function recordMeasurement($data)
    {
        return $this->measurements()->create(array_merge(
            $data,
            ['measured_at' => now()]
        ));
    }

    public function addMilestone($type, $required = true)
    {
        return $this->milestones()->create([
            'milestone_type' => $type,
            'status' => 'Pending',
            'required' => $required
        ]);
    }

    public function scheduleTransport($type, $from, $to, $plannedTime)
    {
        return $this->transports()->create([
            'transport_type' => $type,
            'location_from' => $from,
            'location_to' => $to,
            'planned_time' => $plannedTime
        ]);
    }

    public function recordTiming($phase, $plannedStart, $plannedDuration)
    {
        return $this->timings()->create([
            'phase' => $phase,
            'planned_start' => $plannedStart,
            'planned_duration' => $plannedDuration
        ]);
    }

    public function addSafetyNote($content, $type, $severity, $userId)
    {
        $note = $this->safetyNotes()->create([
            'note_type' => $type,
            'content' => $content,
            'severity' => $severity,
            'created_by' => $userId
        ]);

        if ($severity === CaseSafetyNote::SEVERITY_CRITICAL) {
            $this->safety_status = self::SAFETY_STATUS_ALERT;
            $this->save();
        }

        return $note;
    }

    protected function calculateJourneyProgress()
    {
        $completedMilestones = $this->milestones()
            ->whereNotNull('completed_at')
            ->count();
        
        $totalMilestones = $this->milestones()->count();
        
        if ($totalMilestones === 0) {
            return $this->progress_percentage;
        }

        return round(($completedMilestones / $totalMilestones) * 100);
    }

    public function updateJourneyProgress()
    {
        $this->journey_progress = $this->calculateJourneyProgress();
        $this->save();
    }

    // Status Checks
    public function isScheduled()
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function isInProgress()
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isCompleted()
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isDelayed()
    {
        return $this->status === self::STATUS_DELAYED;
    }

    public function requiresSafetyReview()
    {
        return $this->safety_status !== self::SAFETY_STATUS_NORMAL;
    }

    public function hasPendingMilestones()
    {
        return $this->milestones()
            ->whereNull('completed_at')
            ->exists();
    }

    public function hasUnacknowledgedSafetyNotes()
    {
        return $this->safetyNotes()
            ->whereNull('acknowledged_at')
            ->exists();
    }

    public function getCurrentPhaseTimingAttribute()
    {
        return $this->timings()
            ->where('phase', $this->phase)
            ->whereNotNull('actual_start')
            ->whereNull('actual_duration')
            ->first();
    }

    public function getNextTransportAttribute()
    {
        return $this->transports()
            ->where('status', 'Pending')
            ->orderBy('planned_time')
            ->first();
    }

    public function getPendingMilestonesAttribute()
    {
        return $this->milestones()
            ->whereNull('completed_at')
            ->get();
    }

    public function getActiveSafetyNotesAttribute()
    {
        return $this->safetyNotes()
            ->whereNull('acknowledged_at')
            ->orderBy('severity', 'desc')
            ->get();
    }
}
