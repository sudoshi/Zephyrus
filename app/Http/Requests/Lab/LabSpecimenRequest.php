<?php

namespace App\Http\Requests\Lab;

use App\Services\Lab\LabFlowBoardService;
use App\Services\Lab\LabSpecimenService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LabSpecimenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(LabSpecimenService::STATUSES)],
            'testFamily' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'unitId' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:prod.units,unit_id'],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(LabFlowBoardService::PRIORITIES)],
            'rejection' => ['sometimes', 'string', Rule::in(LabSpecimenService::REJECTION_FILTERS)],
            'age' => ['sometimes', 'string', Rule::in(LabSpecimenService::AGE_BANDS)],
            'orderUuid' => ['sometimes', 'nullable', 'uuid'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
