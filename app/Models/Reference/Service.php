<?php

namespace App\Models\Reference;

use App\Models\ORCase;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends BaseReference
{
    public $timestamps = true;
    protected $table = 'prod.services';
    protected $primaryKey = 'service_id';

    protected $fillable = [
        'name',
        'code',
        'active_status',
        'created_by',
        'modified_by',
        'created_at',
        'updated_at',
        'is_deleted'
    ];

    protected $casts = [
        'active_status' => 'boolean',
        'is_deleted' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function cases(): HasMany
    {
        return $this->hasMany(ORCase::class, 'case_service_id', 'service_id');
    }
}
