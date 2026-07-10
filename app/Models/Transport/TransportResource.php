<?php

namespace App\Models\Transport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportResource extends Model
{
    protected $table = 'prod.transport_resources';

    protected $primaryKey = 'transport_resource_id';

    protected $fillable = [
        'resource_uuid', 'resource_key', 'resource_type', 'display_name', 'capacity',
        'actor_user_id', 'capabilities', 'metadata', 'source', 'is_active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'actor_user_id' => 'integer',
        'capabilities' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(TransportAssignment::class, 'transport_resource_id', 'transport_resource_id');
    }
}
