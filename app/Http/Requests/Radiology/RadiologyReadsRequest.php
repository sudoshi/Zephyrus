<?php

namespace App\Http\Requests\Radiology;

use App\Models\Radiology\Modality;
use App\Models\Radiology\Subspecialty;
use App\Services\Radiology\RadiologyReadsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RadiologyReadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'string', Rule::in(RadiologyReadsService::STATES)],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(['stat', 'urgent', 'routine', 'discharge'])],
            'subspecialty' => ['sometimes', 'nullable', 'string', 'max:40', Rule::exists(Subspecialty::class, 'code')],
            'modality' => ['sometimes', 'nullable', 'string', 'max:16', Rule::exists(Modality::class, 'code')],
            'windowHours' => ['sometimes', 'integer', Rule::in(RadiologyReadsService::WINDOW_HOURS)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
