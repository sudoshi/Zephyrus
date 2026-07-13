<?php

namespace App\Http\Requests\Lab;

use App\Models\Unit;
use App\Services\Lab\LabDecisionPendingService;
use App\Services\Lab\LabFlowBoardService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LabDecisionPendingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decisionClass' => ['sometimes', 'string', Rule::in(['all', ...LabDecisionPendingService::DECISION_CLASSES])],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(LabFlowBoardService::PRIORITIES)],
            'unitId' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists(Unit::class, 'unit_id')],
            'urgency' => ['sometimes', 'string', Rule::in(LabDecisionPendingService::URGENCIES)],
            'orderUuid' => ['sometimes', 'nullable', 'uuid'],
            'source' => ['sometimes', 'nullable', 'string', Rule::in(LabDecisionPendingService::DRILL_SOURCES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
