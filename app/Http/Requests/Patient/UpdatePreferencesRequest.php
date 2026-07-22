<?php

namespace App\Http\Requests\Patient;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'locale' => ['sometimes', 'string', 'max:16', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/'],
            'timezone' => ['sometimes', 'timezone:all'],
            'text_size' => ['sometimes', 'string', 'in:standard,large,extra_large'],
            'reduced_motion' => ['sometimes', 'boolean'],
            'high_contrast' => ['sometimes', 'boolean'],
            'notification_preview' => ['sometimes', 'string', 'in:hidden,generic'],
            'preferred_channel' => ['sometimes', 'string', 'in:push,email,sms,none'],
        ];
    }
}
