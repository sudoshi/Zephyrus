<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaseResource extends Model
{
    protected $fillable = [
        'case_id',
        'resource_name',
        'status'
    ];

    public function case()
    {
        return $this->belongsTo(ORCase::class, 'case_id');
    }
}
