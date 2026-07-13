<?php

namespace App\Http\Requests\Integrations;

use App\Rules\SafeIntegrationUrl;
use App\Rules\SecretReference;
use App\Security\Network\IntegrationUrlPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CredentialReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageIntegrations') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $updating = $this->route('credential') !== null;
        $required = $updating ? 'sometimes' : 'required';

        return [
            'credential_key' => [$required, 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'credential_type' => [$required, Rule::in(['oauth2_client', 'smart_backend_services', 'mtls', 'api_key', 'basic_auth', 'jwks'])],
            'secret_ref' => ['sometimes', 'nullable', 'string', 'max:255', new SecretReference],
            'certificate_ref' => ['sometimes', 'nullable', 'string', 'max:255', new SecretReference],
            'jwks_uri' => ['sometimes', 'nullable', 'url', new SafeIntegrationUrl(app(IntegrationUrlPolicy::class))],
            'rotates_at' => ['sometimes', 'nullable', 'date', 'after:today'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:valid_from'],
            'rotation_overlap_ends_at' => ['sometimes', 'nullable', 'date', 'after:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
            'owner' => ['sometimes', 'nullable', 'string', 'max:120'],
            'change_reason' => ['sometimes', 'string', 'min:10', 'max:500'],
            'secret' => ['prohibited'],
            'password' => ['prohibited'],
            'client_secret' => ['prohibited'],
            'private_key' => ['prohibited'],
            'certificate' => ['prohibited'],
            'access_token' => ['prohibited'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $credentialId = $this->route('credential');
            if ($credentialId !== null) {
                $governedFields = [
                    'credential_type', 'secret_ref', 'certificate_ref', 'jwks_uri',
                    'rotates_at', 'valid_from', 'expires_at', 'rotation_overlap_ends_at', 'is_active',
                ];
                if (collect($governedFields)->contains(fn (string $field): bool => $this->exists($field))) {
                    $validator->errors()->add(
                        'credential',
                        'Credential rotation fields require an approved governed rotation request.',
                    );
                }

                $current = DB::table('integration.source_credentials')
                    ->where('source_id', (int) $this->route('source'))
                    ->where('source_credential_id', (int) $credentialId)
                    ->first(['secret_ref', 'certificate_ref', 'jwks_uri']);
                if (! $current) {
                    return;
                }

                $secretRef = $this->exists('secret_ref') ? $this->input('secret_ref') : $current->secret_ref;
                $certificateRef = $this->exists('certificate_ref') ? $this->input('certificate_ref') : $current->certificate_ref;
                $jwksUri = $this->exists('jwks_uri') ? $this->input('jwks_uri') : $current->jwks_uri;
                if (! filled($secretRef) && ! filled($certificateRef) && ! filled($jwksUri)) {
                    $validator->errors()->add('secret_ref', 'A credential must retain at least one secret, certificate, or JWKS reference.');
                }

                return;
            }

            if (! $this->filled('secret_ref') && ! $this->filled('certificate_ref') && ! $this->filled('jwks_uri')) {
                $validator->errors()->add('secret_ref', 'At least one credential, certificate, or JWKS reference is required.');
            }
        });
    }
}
