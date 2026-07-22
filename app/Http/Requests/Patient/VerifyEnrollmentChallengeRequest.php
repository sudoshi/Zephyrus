<?php

namespace App\Http\Requests\Patient;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class VerifyEnrollmentChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'challenge_uuid' => ['required', 'uuid'],
            'challenge_token' => ['required', 'string', 'min:32', 'max:512'],
            'verification_code' => ['required', 'string', 'min:6', 'max:32'],
            'display_name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:254'],
            'password' => [
                'required',
                'confirmed',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
            'device' => ['sometimes', 'array'],
            'device.uuid' => ['sometimes', 'uuid'],
            'device.platform' => ['sometimes', 'string', 'in:ios,android,web'],
            'device.name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'device.app_version' => ['sometimes', 'nullable', 'string', 'max:40'],
            'device.os_version' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }
}
