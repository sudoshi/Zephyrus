<?php

namespace App\Models\Radiology;

use App\Casts\JsonObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subspecialty extends Model
{
    protected $table = 'hosp_ref.rad_subspecialties';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean', 'metadata' => JsonObject::class];

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'subspecialty_code', 'code');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(Read::class, 'subspecialty_code', 'code');
    }
}
