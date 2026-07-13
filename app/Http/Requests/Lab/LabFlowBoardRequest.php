<?php

namespace App\Http\Requests\Lab;

use App\Models\Unit;
use App\Services\Lab\LabFlowBoardService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LabFlowBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lens' => ['sometimes', 'string', Rule::in(LabFlowBoardService::LENSES)],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(LabFlowBoardService::PRIORITIES)],
            'testFamily' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'unitId' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists(Unit::class, 'unit_id')],
            'shift' => ['sometimes', 'nullable', 'string', Rule::in(['am_draw', 'day', 'evening', 'night'])],
            'source' => ['sometimes', 'nullable', 'string', Rule::in(LabFlowBoardService::DRILL_SOURCES)],
        ];
    }
}
