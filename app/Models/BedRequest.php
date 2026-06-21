<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BedRequest extends Model
{
    protected $table = 'prod.bed_requests';

    protected $primaryKey = 'bed_request_id';

    protected $fillable = [
        'patient_ref', 'source', 'sex', 'service', 'acuity_tier',
        'isolation_required', 'required_unit_type', 'status', 'created_by', 'modified_by', 'is_deleted',
    ];

    protected $casts = [
        'acuity_tier' => 'integer',
        'is_deleted' => 'boolean',
    ];

    public function scopePending($query)
    {
        return $query->where('status', 'pending')->where('is_deleted', false);
    }
}
