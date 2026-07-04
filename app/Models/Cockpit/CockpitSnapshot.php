<?php

namespace App\Models\Cockpit;

use Illuminate\Database\Eloquent\Model;

/**
 * The single replaced server-computed cockpit snapshot row per facility
 * (Zephyrus 2.0 P1, decision D1: facility_key TEXT PK, manifest-keyed).
 */
class CockpitSnapshot extends Model
{
    protected $table = 'prod.cockpit_snapshots';

    protected $primaryKey = 'facility_key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'generated_at' => 'datetime',
    ];
}
