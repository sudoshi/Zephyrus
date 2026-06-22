<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GmlosReference extends Model
{
    protected $table = 'prod.gmlos_references';

    protected $primaryKey = 'gmlos_reference_id';

    protected $guarded = [];

    protected $casts = [
        'gmlos_days' => 'decimal:2',
        'effective_from' => 'date',
    ];
}
