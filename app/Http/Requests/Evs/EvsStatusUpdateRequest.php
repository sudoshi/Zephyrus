<?php

namespace App\Http\Requests\Evs;

use Illuminate\Foundation\Http\FormRequest;

class EvsStatusUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|in:requested,queued,assigned,in_progress,completed,canceled,escalated,failed',
            'note' => 'nullable|string|max:500',
            'payload' => 'nullable|array',
        ];
    }
}
