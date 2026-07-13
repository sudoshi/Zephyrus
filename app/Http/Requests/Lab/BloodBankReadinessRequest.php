<?php

namespace App\Http\Requests\Lab;

use App\Models\ORCase;
use App\Services\Lab\BloodBankReadinessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BloodBankReadinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'string', Rule::in(['all', ...BloodBankReadinessService::GATE_STATES])],
            'productClass' => ['sometimes', 'string', Rule::in(['all', ...BloodBankReadinessService::PRODUCT_CLASSES])],
            'service' => ['sometimes', 'nullable', 'string', 'max:120'],
            'room' => ['sometimes', 'nullable', 'string', 'max:120'],
            'caseId' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists(ORCase::class, 'case_id')],
        ];
    }
}
