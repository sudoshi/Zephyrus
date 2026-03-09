<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangeWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'workflow' => 'required|in:superuser,rtdc,perioperative,emergency,improvement',
            'redirect' => 'nullable|string',
        ];
    }
}
