<?php

namespace App\Http\Requests\PatientCommunication;

use App\Services\Patient\Messaging\StaffPatientCommunicationRoutingPolicy;
use Illuminate\Validation\Rule;

class ReroutePatientCommunicationRequest extends StaffMessageMutationRequest
{
    public function rules(): array
    {
        return [
            'work_item_version' => ['required', 'integer', 'min:1'],
            'thread_version' => ['required', 'integer', 'min:1'],
            'target_pool_uuid' => ['required', 'uuid'],
            'reason_code' => [
                'required',
                'string',
                Rule::in(StaffPatientCommunicationRoutingPolicy::reasonCodes('reroute')),
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    protected function allowedPayloadKeys(): array
    {
        return [
            'work_item_version',
            'thread_version',
            'target_pool_uuid',
            'reason_code',
        ];
    }

    protected function exactIntegerPayloadKeys(): array
    {
        return ['work_item_version', 'thread_version'];
    }

    protected function exactStringPayloadKeys(): array
    {
        return ['target_pool_uuid', 'reason_code'];
    }

    protected function canonicalUuidPayloadKeys(): array
    {
        return ['target_pool_uuid'];
    }
}
