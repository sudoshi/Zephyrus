<?php

namespace App\Http\Requests\Pharmacy;

use App\Services\Pharmacy\PharmacyDischargeReadinessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PharmacyDischargeReadinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pipeline' => ['sometimes', 'nullable', 'string', Rule::in(PharmacyDischargeReadinessService::PIPELINE)],
            'encounterId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'source' => ['sometimes', 'nullable', 'string', Rule::in(PharmacyDischargeReadinessService::DRILL_SOURCES)],
        ];
    }
}
