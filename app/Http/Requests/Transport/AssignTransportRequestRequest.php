<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

class AssignTransportRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assigned_team' => 'nullable|required_without:assigned_vendor|string|max:120',
            'assigned_vendor' => 'nullable|required_without:assigned_team|string|max:120',
            'note' => 'nullable|string|max:500',
        ];
    }
}
