<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

class CompleteHandoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'handoff_to' => 'required|string|max:120',
            'handoff_summary' => 'nullable|string|max:1000',
            'documents' => 'nullable|array',
            'outstanding_risks' => 'nullable|array',
        ];
    }
}
