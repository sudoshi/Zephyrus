<?php

namespace App\Http\Requests\Patient;

use App\Nightingale\Demo\NightingaleDemoCohort;
use Closure;
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
            // `email` remains the compatibility wire name. Exact Nightingale
            // demo aliases are admitted only for the code-owned synthetic
            // cohort; no general patient username realm is created here.
            'email' => [
                'required',
                'string',
                'lowercase',
                'max:254',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)
                        || (filter_var($value, FILTER_VALIDATE_EMAIL) === false
                            && ! NightingaleDemoCohort::isLoginAlias($value))) {
                        $fail("The {$attribute} field must be a valid email address or demo login.");
                    }
                },
            ],
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
