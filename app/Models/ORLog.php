<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ORLog extends Model
{
    public $timestamps = false;
    protected $table = 'prod.orlog';
    protected $primaryKey = 'log_id';

    protected $fillable = [
        'case_id',
        'tracking_date',
        'periop_arrival_time',
        'preop_in_time',
        'preop_out_time',
        'or_in_time',
        'anesthesia_start_time',
        'procedure_start_time',
        'procedure_closing_time',
        'procedure_end_time',
        'or_out_time',
        'anesthesia_end_time',
        'pacu_in_time',
        'pacu_out_time',
        'destination',
        'number_of_panels',
        'primary_procedure',
        'created_by',
        'modified_by',
        'created_date',
        'modified_date',
        'is_deleted'
    ];

    protected $casts = [
        'tracking_date' => 'date',
        'periop_arrival_time' => 'datetime',
        'preop_in_time' => 'datetime',
        'preop_out_time' => 'datetime',
        'or_in_time' => 'datetime',
        'anesthesia_start_time' => 'datetime',
        'procedure_start_time' => 'datetime',
        'procedure_closing_time' => 'datetime',
        'procedure_end_time' => 'datetime',
        'or_out_time' => 'datetime',
        'anesthesia_end_time' => 'datetime',
        'pacu_in_time' => 'datetime',
        'pacu_out_time' => 'datetime',
        'created_date' => 'datetime',
        'modified_date' => 'datetime',
        'is_deleted' => 'boolean'
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(ORCase::class, 'case_id', 'case_id');
    }

    public function getPreopDurationAttribute(): ?int
    {
        if ($this->preop_in_time && $this->preop_out_time) {
            return $this->preop_in_time->diffInMinutes($this->preop_out_time);
        }
        return null;
    }

    public function getAnesthesiaDurationAttribute(): ?int
    {
        if ($this->anesthesia_start_time && $this->anesthesia_end_time) {
            return $this->anesthesia_start_time->diffInMinutes($this->anesthesia_end_time);
        }
        return null;
    }

    public function getProcedureDurationAttribute(): ?int
    {
        if ($this->procedure_start_time && $this->procedure_end_time) {
            return $this->procedure_start_time->diffInMinutes($this->procedure_end_time);
        }
        return null;
    }

    public function getRoomDurationAttribute(): ?int
    {
        if ($this->or_in_time && $this->or_out_time) {
            return $this->or_in_time->diffInMinutes($this->or_out_time);
        }
        return null;
    }

    public function getPacuDurationAttribute(): ?int
    {
        if ($this->pacu_in_time && $this->pacu_out_time) {
            return $this->pacu_in_time->diffInMinutes($this->pacu_out_time);
        }
        return null;
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
