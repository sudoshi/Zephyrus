<?php

namespace App\Http\Requests\Patient;

use Illuminate\Foundation\Http\FormRequest;

class TokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
            'device' => ['sometimes', 'array'],
            'device.uuid' => ['sometimes', 'uuid'],
            'device.platform' => ['sometimes', 'string', 'in:ios,android,web'],
            'device.name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'device.app_version' => ['sometimes', 'nullable', 'string', 'max:40'],
            'device.os_version' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }
}
