<?php

namespace App\Http\Requests\Deployment\Staffing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase F4: start a dry-run staffing import. Content is supplied inline (csv string /
 * fhir bundle) with an optional per-run field mapping. The connector build validates
 * that the content matches the source transport (RuntimeException -> 422 in the
 * controller), so this request only guards shape.
 */
class StartStaffImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-gated by can:manageDeploymentConfig
    }

    public function rules(): array
    {
        return [
            'source_id' => ['nullable', 'integer'],
            'source_key' => ['nullable', 'string', 'max:80'],
            'facility_key' => ['required', 'string', 'max:80'],
            'csv' => ['nullable', 'string'],
            'bundle' => ['nullable', 'array'],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->filled('source_id') && ! $this->filled('source_key')) {
                $validator->errors()->add('source_id', 'A source_id or source_key is required.');
            }
        });
    }
}
