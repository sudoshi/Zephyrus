<?php

namespace App\Http\Requests\Deployment\Staffing;

use App\Models\Org\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase F4: create/update a staffing connector source. Route gated by
 * manageDeploymentConfig. NEVER accepts connector secrets — for API connectors those
 * live encrypted in integration.sources; the shipped file/FHIR upload path carries
 * content per request and stores none.
 */
class StoreStaffingSourceRequest extends FormRequest
{
    public const CONNECTOR_TYPES = ['hris', 'scheduling', 'credentialing', 'identity', 'ehr_master', 'on_call', 'manual'];

    public const TRANSPORTS = ['rest_api', 'sftp', 'scim', 'hl7_mfn', 'fhir_practitioner', 'db_view', 'file_upload'];

    public function authorize(): bool
    {
        return true; // route-gated by can:manageDeploymentConfig
    }

    public function rules(): array
    {
        return [
            'source_key' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9_]+$/'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'connector_type' => ['required', Rule::in(self::CONNECTOR_TYPES)],
            'transport' => ['required', Rule::in(self::TRANSPORTS)],
            'organization_id' => ['nullable', 'integer', Rule::exists(Organization::class, 'organization_id')],
            'default_facility_key' => ['nullable', 'string', 'max:80'],
            'mapping_template' => ['nullable', 'array'],
            'sync_schedule' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'source_key.regex' => 'source_key must be UPPER_SNAKE_CASE (letters, digits, underscore).',
        ];
    }
}
