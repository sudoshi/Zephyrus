<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Reference\Service;
use App\Models\Reference\CaseStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;

class ORCase extends Model
{
    protected $table = 'prod.or_cases';
    protected $primaryKey = 'case_id';

    protected $fillable = [
        'patient_id',
        'surgery_date',
        'room_id',
        'location_id',
        'primary_surgeon_id',
        'case_service_id',
        'scheduled_start_time',
        'scheduled_duration',
        'record_create_date',
        'status_id',
        'cancellation_reason_id',
        'asa_rating_id',
        'case_type_id',
        'case_class_id',
        'patient_class_id',
        'procedure_name',
        'created_by',
        'modified_by',
        'is_deleted',
        'pre_procedure_location',
        'post_procedure_location',
        'safety_status',
        'journey_progress'
    ];

    protected $casts = [
        'surgery_date' => 'date',
        'scheduled_start_time' => 'datetime',
        'record_create_date' => 'datetime',
        'is_deleted' => 'boolean',
        'journey_progress' => 'integer'
    ];

    protected $appends = ['statusCode'];

    // Relationships
    public function provider()
    {
        return $this->belongsTo(Provider::class, 'primary_surgeon_id', 'provider_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id', 'room_id');
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'case_service_id', 'service_id');
    }

    public function status()
    {
        return $this->belongsTo(CaseStatus::class, 'status_id', 'status_id');
    }

    public function staff()
    {
        return $this->belongsToMany(User::class, 'prod.case_staff', 'case_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function resources()
    {
        return $this->hasMany(CaseResource::class, 'case_id', 'case_id');
    }

    public function measurements()
    {
        return $this->hasMany(CaseMeasurement::class, 'case_id', 'case_id');
    }

    public function milestones()
    {
        return $this->hasMany(CareJourneyMilestone::class, 'case_id', 'case_id');
    }

    public function transports()
    {
        return $this->hasMany(CaseTransport::class, 'case_id', 'case_id');
    }

    public function timings()
    {
        return $this->hasMany(CaseTiming::class, 'case_id', 'case_id');
    }

    public function safetyNotes()
    {
        return $this->hasMany(CaseSafetyNote::class, 'case_id', 'case_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->whereHas('status', function($q) {
            $q->whereIn('code', ['SCHED', 'INPROG', 'DELAY']);
        });
    }

    public function scopeToday($query)
    {
        return $query->whereDate('surgery_date', today());
    }

    public function scopeInProgress($query)
    {
        return $query->whereHas('status', function($q) {
            $q->where('code', 'INPROG');
        });
    }

    public function scopeDelayed($query)
    {
        return $query->whereHas('status', function($q) {
            $q->where('code', 'DELAY');
        });
    }

    public function scopeCompleted($query)
    {
        return $query->whereHas('status', function($q) {
            $q->where('code', 'COMP');
        });
    }

    public function scopePreOp($query)
    {
        return $query->where('phase', 'Pre-Op');
    }

    public function scopeInPhase($query, $phase)
    {
        return $query->where('phase', $phase);
    }

    public function scopeRequiringReview($query)
    {
        return $query->where('safety_status', '!=', 'Normal');
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
    protected function statusCode(): Attribute
    {
        return Attribute::make(
            get: function () {
                return strtolower($this->status()->first()->code ?? '');
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
    protected function calculateJourneyProgress()
    {
        $completedMilestones = $this->milestones()
            ->whereNotNull('completed_at')
            ->count();
        
        $totalMilestones = $this->milestones()->count();
        
        if ($totalMilestones === 0) {
            return 0;
        }

        return round(($completedMilestones / $totalMilestones) * 100);
    }

    public function updateJourneyProgress()
    {
        $this->journey_progress = $this->calculateJourneyProgress();
        $this->save();
    }
}
