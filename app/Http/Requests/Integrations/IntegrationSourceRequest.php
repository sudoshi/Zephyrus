<?php

namespace App\Http\Requests\Integrations;

use App\Rules\SafeIntegrationUrl;
use App\Security\Network\IntegrationUrlPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IntegrationSourceRequest extends FormRequest
{
    private const SYSTEM_CLASSES = [
        'ehr', 'bed_flow', 'workforce', 'transport', 'evs', 'orders_results',
        'perioperative', 'pharmacy', 'imaging', 'ems', 'facilities', 'rtls',
        'nurse_call', 'erp', 'supply_chain', 'payer', 'hie', 'public_health', 'other',
    ];

    private const INTERFACE_TYPES = [
        'fhir_r4', 'hl7v2', 'rest_api', 'webhook', 'sftp', 'file', 'mqtt',
        'dicomweb', 'x12', 'ccda', 'direct', 'other',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('manageIntegrations') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $updating = $this->route('source') !== null;
        $required = $updating ? 'sometimes' : 'required';

        return [
            'source_key' => $updating
                ? ['prohibited']
                : ['required', 'string', 'max:160', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'source_name' => [$required, 'string', 'max:255'],
            'tenant_key' => ['prohibited'],
            'facility_key' => ['prohibited'],
            'organization_id' => ['prohibited'],
            'facility_id' => ['prohibited'],
            'vendor' => ['sometimes', 'nullable', 'string', 'max:120'],
            'system_class' => [$required, Rule::in(self::SYSTEM_CLASSES)],
            'environment' => [$required, Rule::in(['sandbox', 'test', 'staging', 'production'])],
            'base_url' => ['sometimes', 'nullable', 'url', new SafeIntegrationUrl(app(IntegrationUrlPolicy::class))],
            'interface_type' => [$required, Rule::in(self::INTERFACE_TYPES)],
            'active_status' => $updating
                ? ['prohibited']
                : ['sometimes', Rule::in(['template', 'inactive', 'testing', 'degraded', 'disabled'])],
            'fhir_version' => ['sometimes', 'nullable', 'string', 'max:40'],
            'us_core_version' => ['sometimes', 'nullable', 'string', 'max:40'],
            'smart_supported' => ['sometimes', 'boolean'],
            'bulk_supported' => ['sometimes', 'boolean'],
            'subscriptions_supported' => ['sometimes', 'boolean'],
            'contract_status' => [$required, Rule::in(['unknown', 'planning', 'review', 'executed', 'expired', 'not_required'])],
            'baa_status' => [$required, Rule::in(['unknown', 'planning', 'review', 'executed', 'expired', 'not_required'])],
            'phi_allowed' => ['sometimes', 'boolean'],
            'go_live_status' => $updating
                ? ['prohibited']
                : ['sometimes', Rule::in(['not_started', 'planning', 'testing', 'ready', 'paused', 'retired'])],
            'owner' => ['sometimes', 'nullable', 'string', 'max:120'],
            'expected_cadence_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
            'expected_configuration_version_id' => $updating ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'change_reason' => $updating ? ['required', 'string', 'min:10', 'max:500'] : ['prohibited'],
            'secret' => ['prohibited'],
            'password' => ['prohibited'],
            'client_secret' => ['prohibited'],
            'private_key' => ['prohibited'],
            'access_token' => ['prohibited'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->route('source') && $this->input('active_status') === 'active') {
                $validator->errors()->add('active_status', 'Production activation requires an approved governed activation request.');
            }
            if (! $this->route('source') && $this->input('go_live_status') === 'live') {
                $validator->errors()->add('go_live_status', 'Production go-live requires an approved governed activation request.');
            }
        });
    }
}
