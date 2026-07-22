<?php

namespace App\Http\Requests\Patient;

use Illuminate\Foundation\Http\FormRequest;

class RegisterPatientNotificationDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'push_token' => is_string($this->input('push_token'))
                ? trim($this->input('push_token'))
                : $this->input('push_token'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', 'in:ios,android'],
            'environment' => ['required', 'string', 'in:sandbox,production'],
            'installation_uuid' => ['required', 'uuid'],
            'push_token' => ['required', 'string', 'min:16', 'max:4096', 'not_regex:/\s/'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:80'],
            'os_version' => ['sometimes', 'nullable', 'string', 'max:80'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:35', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/'],
        ];
    }
}
