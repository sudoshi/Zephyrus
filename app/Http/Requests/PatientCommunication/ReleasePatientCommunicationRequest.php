<?php

namespace App\Http\Requests\PatientCommunication;

use App\Services\Patient\Messaging\StaffPatientCommunicationRoutingPolicy;
use Illuminate\Validation\Rule;

class ReleasePatientCommunicationRequest extends StaffMessageMutationRequest
{
    public function rules(): array
    {
        return [
            'work_item_version' => ['required', 'integer', 'min:1'],
            'thread_version' => ['required', 'integer', 'min:1'],
            'reason_code' => [
                'required',
                'string',
                Rule::in(StaffPatientCommunicationRoutingPolicy::reasonCodes('release')),
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    protected function allowedPayloadKeys(): array
    {
        return ['work_item_version', 'thread_version', 'reason_code'];
    }

    protected function exactIntegerPayloadKeys(): array
    {
        return ['work_item_version', 'thread_version'];
    }

    protected function exactStringPayloadKeys(): array
    {
        return ['reason_code'];
    }
}
