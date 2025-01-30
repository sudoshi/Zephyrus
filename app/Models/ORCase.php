<?php

namespace App\Models;

use App\Models\Reference\ASARating;
use App\Models\Reference\CancellationReason;
use App\Models\Reference\CaseClass;
use App\Models\Reference\CaseStatus;
use App\Models\Reference\CaseType;
use App\Models\Reference\PatientClass;
use App\Models\Reference\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ORCase extends Model
{
    public $timestamps = false;
    protected $table = 'prod.orcase';
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
        'created_date',
        'modified_date',
        'is_deleted'
    ];

    protected $casts = [
        'surgery_date' => 'date',
        'scheduled_start_time' => 'datetime',
        'record_create_date' => 'datetime',
        'created_date' => 'datetime',
        'modified_date' => 'datetime',
        'is_deleted' => 'boolean'
    ];

    protected $with = ['surgeon', 'room', 'service', 'status'];

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

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->created_date = $model->freshTimestamp();
            $model->modified_date = $model->freshTimestamp();
        });

        static::updating(function ($model) {
            $model->modified_date = $model->freshTimestamp();
        });
    }
}
