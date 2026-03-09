<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveViewportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'process_type' => 'required|string|max:50',
            'layout_data' => 'required|array',
            'hospital' => 'required|string|max:100',
            'workflow' => 'required|string|max:50',
            'time_range' => 'required|string|max:50',
        ];
    }
}
