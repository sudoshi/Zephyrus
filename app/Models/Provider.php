<?php

namespace App\Models;

use App\Models\Reference\Specialty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    public $timestamps = false;
    protected $table = 'prod.provider';
    protected $primaryKey = 'provider_id';

    protected $fillable = [
        'npi',
        'name',
        'specialty_id',
        'provider_type',
        'active_status',
        'created_by',
        'modified_by',
        'created_date',
        'modified_date',
        'is_deleted'
    ];

    protected $casts = [
        'active_status' => 'boolean',
        'is_deleted' => 'boolean',
        'created_date' => 'datetime',
        'modified_date' => 'datetime'
    ];

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class, 'specialty_id', 'specialty_id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(ORCase::class, 'primary_surgeon_id', 'provider_id');
    }

    public function blockTemplates(): HasMany
    {
        return $this->hasMany(BlockTemplate::class, 'surgeon_id', 'provider_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active_status', true)
                    ->where('is_deleted', false);
    }

    public function scopeSurgeons($query)
    {
        return $query->where('provider_type', 'surgeon');
    }

    public function scopeAnesthesiologists($query)
    {
        return $query->where('provider_type', 'anesthesiologist');
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
