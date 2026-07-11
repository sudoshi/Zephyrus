<?php

namespace App\Http\Requests\Integrations;

use App\Rules\SafeIntegrationUrl;
use App\Security\Network\IntegrationUrlPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IntegrationEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageIntegrations') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $updating = $this->route('endpoint') !== null;
        $required = $updating ? 'sometimes' : 'required';

        return [
            'endpoint_type' => [$required, Rule::in([
                'api_base', 'fhir_base', 'smart_discovery', 'oauth_token', 'webhook',
                'interface_gateway', 'dicomweb', 'bulk_export', 'other',
            ])],
            'url' => [$required, 'url', new SafeIntegrationUrl(app(IntegrationUrlPolicy::class))],
            'auth_type' => ['sometimes', 'nullable', Rule::in(['none', 'oauth2', 'smart_backend', 'mtls', 'api_key_ref', 'basic_ref'])],
            'tls_mode' => ['sometimes', 'nullable', Rule::in(['system_ca', 'pinned_ca', 'mtls'])],
            'is_active' => ['sometimes', 'boolean'],
            'owner' => ['sometimes', 'nullable', 'string', 'max:120'],
            'expected_cadence_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
            'secret' => ['prohibited'],
            'password' => ['prohibited'],
            'client_secret' => ['prohibited'],
            'private_key' => ['prohibited'],
            'access_token' => ['prohibited'],
        ];
    }
}
