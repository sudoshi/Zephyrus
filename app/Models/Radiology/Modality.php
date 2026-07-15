<?php

namespace App\Models\Radiology;

use App\Casts\JsonObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Modality extends Model
{
    protected $table = 'hosp_ref.rad_modalities';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'is_cross_sectional' => 'boolean',
        'supports_portable' => 'boolean',
        'contrast_screening_applicable' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => JsonObject::class,
    ];

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'modality_code', 'code');
    }

    public function scanners(): HasMany
    {
        return $this->hasMany(Scanner::class, 'modality_code', 'code');
    }
}
