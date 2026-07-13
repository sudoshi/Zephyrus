<?php

namespace App\Http\Requests\Lab;

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
            'unitId' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:prod.units,unit_id'],
            'urgency' => ['sometimes', 'string', Rule::in(LabDecisionPendingService::URGENCIES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
