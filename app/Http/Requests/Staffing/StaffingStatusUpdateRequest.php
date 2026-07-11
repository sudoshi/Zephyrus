<?php

namespace App\Http\Requests\Staffing;

use Illuminate\Foundation\Http\FormRequest;

class StaffingStatusUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|in:requested,open,sourcing,completed,canceled,escalated,unfilled',
            'note' => 'nullable|string|max:500',
            'payload' => 'nullable|array',
        ];
    }
}
