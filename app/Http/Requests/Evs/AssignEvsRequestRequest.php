<?php

namespace App\Http\Requests\Evs;

use Illuminate\Foundation\Http\FormRequest;

class AssignEvsRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assigned_team' => 'nullable|required_without:assigned_user_ref|string|max:120',
            'assigned_user_ref' => 'nullable|required_without:assigned_team|string|max:120',
            'note' => 'nullable|string|max:500',
        ];
    }
}
