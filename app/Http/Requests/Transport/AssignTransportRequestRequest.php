<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

class AssignTransportRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageTransportDispatch') === true;
    }

    public function rules(): array
    {
        return [
            'resource_key' => 'nullable|required_without_all:assigned_team,assigned_vendor|prohibits:assigned_team,assigned_vendor|string|max:160',
            'assigned_team' => 'nullable|required_without_all:resource_key,assigned_vendor|prohibits:resource_key,assigned_vendor|string|max:120',
            'assigned_vendor' => 'nullable|required_without_all:resource_key,assigned_team|prohibits:resource_key,assigned_team|string|max:120',
            'capacity_units' => 'sometimes|integer|min:1|max:20',
            'note' => 'nullable|string|max:500',
        ];
    }
}
