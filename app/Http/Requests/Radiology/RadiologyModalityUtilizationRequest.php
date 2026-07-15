<?php

namespace App\Http\Requests\Radiology;

use App\Models\Radiology\Modality;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RadiologyModalityUtilizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'startTime' => ['sometimes', 'date_format:H:i'],
            'endTime' => ['sometimes', 'date_format:H:i', 'after:startTime'],
            'modality' => ['sometimes', 'nullable', 'string', 'max:16', 'regex:/^[A-Z0-9_]+$/', Rule::exists(Modality::class, 'code')],
        ];
    }
}
