<?php

namespace App\Http\Requests\Lab;

use App\Models\ORCase;
use App\Services\Lab\AnatomicPathologyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AnatomicPathologyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stage' => ['sometimes', 'string', Rule::in(['all', ...AnatomicPathologyService::STAGES])],
            'cohort' => ['sometimes', 'string', Rule::in(['all', ...AnatomicPathologyService::COHORTS])],
            'status' => ['sometimes', 'string', Rule::in(AnatomicPathologyService::STATUSES)],
            'ageBand' => ['sometimes', 'string', Rule::in(['all', ...AnatomicPathologyService::AGE_BANDS])],
            'caseId' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists(ORCase::class, 'case_id')],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
