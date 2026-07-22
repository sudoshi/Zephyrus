<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ProtectPatientCommunicationResponse;
use App\Http\Requests\PatientCommunication\ClaimPatientCommunicationRequest;
use App\Http\Requests\PatientCommunication\ClosePatientCommunicationRequest;
use App\Http\Requests\PatientCommunication\ReassignPatientCommunicationRequest;
use App\Http\Requests\PatientCommunication\ReleasePatientCommunicationRequest;
use App\Http\Requests\PatientCommunication\ReplyPatientCommunicationRequest;
use App\Http\Requests\PatientCommunication\ReroutePatientCommunicationRequest;
use App\Models\User;
use App\Services\Patient\Messaging\StaffPatientCommunicationFailure;
use App\Services\Patient\Messaging\StaffPatientCommunicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientCommunicationController extends Controller
{
    use RendersMobileEnvelope;

    public function __construct(private readonly StaffPatientCommunicationService $communications) {}

    public function inbox(Request $request): JsonResponse
    {
        return $this->respond(fn (): array => $this->communications->inbox($request, $this->user($request)));
    }

    public function show(Request $request, string $workItemUuid): JsonResponse
    {
        return $this->respond(fn (): array => $this->communications->show(
            $request,
            $this->user($request),
            $workItemUuid,
        ));
    }

    public function routeCandidates(Request $request, string $workItemUuid): JsonResponse
    {
        return $this->respond(fn (): array => $this->communications->routeCandidates(
            $request,
            $this->user($request),
            $workItemUuid,
        ));
    }

    public function claim(ClaimPatientCommunicationRequest $request, string $workItemUuid): JsonResponse
    {
        return $this->respond(fn (): array => $this->communications->claim(
            $request,
            $this->user($request),
            $workItemUuid,
            $request->validated(),
        ));
    }

    public function reply(ReplyPatientCommunicationRequest $request, string $workItemUuid): JsonResponse
    {
        return $this->respond(fn (): array => $this->communications->reply(
            $request,
            $this->user($request),
            $workItemUuid,
            $request->validated(),
        ));
    }

    public function close(ClosePatientCommunicationRequest $request, string $workItemUuid): JsonResponse
    {
        return $this->respond(fn (): array => $this->communications->close(
            $request,
            $this->user($request),
            $workItemUuid,
            $request->validated(),
        ));
    }

    public function release(ReleasePatientCommunicationRequest $request, string $workItemUuid): JsonResponse
    {
        return $this->respond(fn (): array => $this->communications->release(
            $request,
            $this->user($request),
            $workItemUuid,
            $request->validated(),
        ));
    }

    public function reassign(ReassignPatientCommunicationRequest $request, string $workItemUuid): JsonResponse
    {
        return $this->respond(fn (): array => $this->communications->reassign(
            $request,
            $this->user($request),
            $workItemUuid,
            $request->validated(),
        ));
    }

    public function reroute(ReroutePatientCommunicationRequest $request, string $workItemUuid): JsonResponse
    {
        return $this->respond(fn (): array => $this->communications->reroute(
            $request,
            $this->user($request),
            $workItemUuid,
            $request->validated(),
        ));
    }

    /** @param callable(): array<string, mixed> $callback */
    private function respond(callable $callback): JsonResponse
    {
        try {
            $data = $callback();

            $response = $this->envelope($data, meta: [
                'classification' => 'patient_communication_restricted',
                'offline_writes_allowed' => false,
            ], links: ['web' => url('/patient-communications')]);
            ProtectPatientCommunicationResponse::protect($response);

            return $response;
        } catch (StaffPatientCommunicationFailure $failure) {
            $response = response()->json([
                'error' => [
                    'code' => $failure->errorCode,
                    'message' => $failure->getMessage(),
                ],
                'meta' => [
                    'as_of' => now()->toISOString(),
                    'classification' => 'patient_communication_restricted',
                    'offline_writes_allowed' => false,
                ],
            ], $failure->httpStatus);
            ProtectPatientCommunicationResponse::protect($response);

            return $response;
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw StaffPatientCommunicationFailure::notFound();
        }

        return $user;
    }
}
