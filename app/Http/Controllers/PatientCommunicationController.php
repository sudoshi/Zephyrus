<?php

namespace App\Http\Controllers;

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
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class PatientCommunicationController extends Controller
{
    public function __construct(private readonly StaffPatientCommunicationService $communications) {}

    public function index(Request $request): Response
    {
        try {
            $inbox = $this->communications->inbox($request, $this->user($request));
        } catch (StaffPatientCommunicationFailure $failure) {
            abort($failure->httpStatus, $failure->getMessage());
        }

        return ProtectPatientCommunicationResponse::protect(Inertia::render('PatientCommunications/Index', [
            // The inbox projection is intentionally content-free. Message bodies
            // are fetched only after the user explicitly opens an authorized row.
            'initialInbox' => $inbox,
            'endpoints' => [
                'inbox' => route('patient-communications.inbox'),
                'thread' => route('patient-communications.threads.show', ['workItemUuid' => '__WORK_ITEM_UUID__']),
                'claim' => route('patient-communications.threads.claim', ['workItemUuid' => '__WORK_ITEM_UUID__']),
                'reply' => route('patient-communications.threads.reply', ['workItemUuid' => '__WORK_ITEM_UUID__']),
                'close' => route('patient-communications.threads.close', ['workItemUuid' => '__WORK_ITEM_UUID__']),
                'routeCandidates' => route('patient-communications.threads.route-candidates', ['workItemUuid' => '__WORK_ITEM_UUID__']),
                'release' => route('patient-communications.threads.release', ['workItemUuid' => '__WORK_ITEM_UUID__']),
                'reassign' => route('patient-communications.threads.reassign', ['workItemUuid' => '__WORK_ITEM_UUID__']),
                'reroute' => route('patient-communications.threads.reroute', ['workItemUuid' => '__WORK_ITEM_UUID__']),
            ],
        ])->toResponse($request));
    }

    public function inbox(Request $request): JsonResponse
    {
        return $this->respond(fn (): array => $this->communications->inbox(
            $request,
            $this->user($request),
        ));
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
            return $this->protectedJson([
                'data' => $callback(),
                'meta' => $this->meta(),
            ]);
        } catch (StaffPatientCommunicationFailure $failure) {
            return $this->protectedJson([
                'error' => [
                    'code' => $failure->errorCode,
                    'message' => $failure->getMessage(),
                ],
                'meta' => $this->meta(),
            ], $failure->httpStatus);
        }
    }

    /** @param array<string, mixed> $payload */
    private function protectedJson(array $payload, int $status = 200): JsonResponse
    {
        $response = response()->json($payload, $status);
        ProtectPatientCommunicationResponse::protect($response);

        return $response;
    }

    /** @return array<string, mixed> */
    private function meta(): array
    {
        return [
            'as_of' => now()->toISOString(),
            'classification' => 'patient_communication_restricted',
            'offline_writes_allowed' => false,
        ];
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
