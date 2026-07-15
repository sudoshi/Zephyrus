<?php

namespace App\Http\Requests\Radiology;

use App\Models\Unit;
use App\Services\Radiology\RadiologyFlowBoardService;
use App\Services\Radiology\RadiologyWorklistService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RadiologyWorklistRequest extends FormRequest
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
            'unitId' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists(Unit::class, 'unit_id')],
            'state' => ['sometimes', 'nullable', 'string', Rule::in(['normal', 'warning', 'breach', 'degraded'])],
            'sort' => ['sometimes', 'string', Rule::in(RadiologyWorklistService::SORTS)],
            'search' => ['sometimes', 'nullable', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'source' => ['sometimes', 'nullable', 'string', Rule::in(RadiologyWorklistService::DEEP_LINK_SOURCES)],
            'risk' => ['sometimes', 'boolean'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
