<?php

namespace App\Http\Requests\Radiology;

use App\Services\Radiology\RadiologyFlowBoardService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RadiologyFlowBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lens' => ['sometimes', 'string', Rule::in(RadiologyFlowBoardService::LENSES)],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(['stat', 'urgent', 'routine', 'discharge'])],
            'modality' => ['sometimes', 'nullable', 'string', 'max:16', 'regex:/^[A-Z0-9_]+$/'],
            'unitId' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:prod.units,unit_id'],
        ];
    }
}
