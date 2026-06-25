<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;

class OperationalConstraint extends Model
{
    protected $table = 'ops.constraints';

    protected $primaryKey = 'constraint_id';

    protected $guarded = [];

    protected $casts = [
        'expression' => 'array',
        'metadata' => 'array',
    ];
}
