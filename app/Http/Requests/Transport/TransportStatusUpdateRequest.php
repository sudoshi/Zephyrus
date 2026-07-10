<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

class TransportStatusUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageTransportDispatch') === true
            || $this->user()?->can('progressTransportOperations') === true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|in:requested,accepted,queued,assigned,dispatched,arrived_pickup,patient_ready,patient_not_ready,picked_up,en_route,arrived_destination,handoff_started,handoff_complete,completed,canceled,escalated,failed',
            'note' => 'nullable|string|max:500',
            'reason' => 'nullable|string|max:500',
            'payload' => 'nullable|array',
        ];
    }
}
