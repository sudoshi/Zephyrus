<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

class CancelTransportRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageTransportDispatch') === true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
