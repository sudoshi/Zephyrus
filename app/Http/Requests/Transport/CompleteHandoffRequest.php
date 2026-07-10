<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

class CompleteHandoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageTransportDispatch') === true
            || $this->user()?->can('progressTransportOperations') === true;
    }

    public function rules(): array
    {
        return [
            'handoff_to' => 'required|string|max:120',
            'receiver_role' => 'required|string|max:120',
            'acceptance_status' => 'required|in:accepted,accepted_with_risks',
            'accepted_at' => 'nullable|date|before_or_equal:now',
            'handoff_summary' => 'nullable|string|max:1000',
            'documents' => 'nullable|array|max:20',
            'documents.*.type' => 'required_with:documents|string|max:80',
            'documents.*.reference' => 'required_with:documents|string|max:200',
            'outstanding_risks' => 'required_if:acceptance_status,accepted_with_risks|array|min:1|max:20',
            'outstanding_risks.*' => 'string|max:300',
        ];
    }
}
