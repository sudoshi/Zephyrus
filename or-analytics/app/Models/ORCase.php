<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'created_by',
        'modified_by',
        'is_deleted'
    ];

    protected $casts = [
        'surgery_date' => 'date',
        'scheduled_start_time' => 'datetime',
        'record_create_date' => 'datetime',
        'is_deleted' => 'boolean'
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id', 'room_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function surgeon(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'primary_surgeon_id', 'provider_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'case_service_id', 'service_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(CaseStatus::class, 'status_id', 'status_id');
    }

    public function cancellationReason(): BelongsTo
    {
        return $this->belongsTo(CancellationReason::class, 'cancellation_reason_id', 'cancellation_id');
    }

    public function asaRating(): BelongsTo
    {
        return $this->belongsTo(ASARating::class, 'asa_rating_id', 'asa_id');
    }

    public function caseType(): BelongsTo
    {
        return $this->belongsTo(CaseType::class, 'case_type_id', 'case_type_id');
    }

    public function caseClass(): BelongsTo
    {
        return $this->belongsTo(CaseClass::class, 'case_class_id', 'case_class_id');
    }

    public function patientClass(): BelongsTo
    {
        return $this->belongsTo(PatientClass::class, 'patient_class_id', 'patient_class_id');
    }

    public function log(): HasOne
    {
        return $this->hasOne(ORLog::class, 'case_id', 'case_id');
    }

    public function metrics(): HasOne
    {
        return $this->hasOne(CaseMetrics::class, 'case_id', 'case_id');
    }
}
