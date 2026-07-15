<?php

namespace App\Http\Requests\Pharmacy;

use App\Models\Unit;
use App\Services\Pharmacy\PharmacyFlowBoardService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PharmacyFlowBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lens' => ['sometimes', 'string', Rule::in(PharmacyFlowBoardService::LENSES)],
            'clockClass' => ['sometimes', 'nullable', 'string', Rule::in(PharmacyFlowBoardService::CLOCK_CLASSES)],
            'branch' => ['sometimes', 'nullable', 'string', Rule::in(PharmacyFlowBoardService::BRANCHES)],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(PharmacyFlowBoardService::STATUSES)],
            'unitId' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists(Unit::class, 'unit_id')],
            'source' => ['sometimes', 'nullable', 'string', Rule::in(PharmacyFlowBoardService::DRILL_SOURCES)],
            'forecast' => ['sometimes', 'boolean'],
        ];
    }
}
