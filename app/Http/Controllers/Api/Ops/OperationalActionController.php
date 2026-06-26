<?php

namespace App\Http\Controllers\Api\Ops;

use App\Http\Controllers\Controller;
use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Services\Ops\OperationalActionLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class OperationalActionController extends Controller
{
    public function __construct(private readonly OperationalActionLifecycleService $lifecycle) {}

    public function inbox(): JsonResponse
    {
        return response()->json([
            'data' => $this->lifecycle->inbox(),
        ]);
    }

    public function decideApproval(Approval $approval, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $action = $this->lifecycle->decideApproval(
                $approval,
                $validated['decision'],
                $validated['reason'] ?? null,
                $request->user()?->id,
            );
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->lifecycle->serializeAction($action)]);
    }

    public function assign(OperationalAction $action, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'owner_name' => ['required', 'string', 'max:160'],
            'assigned_to_user_id' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ]);

        try {
            $action = $this->lifecycle->assign($action, $validated, $request->user()?->id);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->lifecycle->serializeAction($action)]);
    }

    public function start(OperationalAction $action, Request $request): JsonResponse
    {
        try {
            $action = $this->lifecycle->start($action, $request->user()?->id);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->lifecycle->serializeAction($action)]);
    }

    public function complete(OperationalAction $action, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'completion_payload' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $completionPayload = $validated['completion_payload'] ?? [];
        if (isset($validated['note'])) {
            $completionPayload['note'] = $validated['note'];
        }

        try {
            $action = $this->lifecycle->complete($action, $completionPayload, $request->user()?->id);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->lifecycle->serializeAction($action)]);
    }

    public function override(OperationalAction $action, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $action = $this->lifecycle->override($action, $validated['reason'], $request->user()?->id);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->lifecycle->serializeAction($action)]);
    }

    public function expire(OperationalAction $action, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $action = $this->lifecycle->expire($action, $validated['reason'] ?? null, $request->user()?->id);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->lifecycle->serializeAction($action)]);
    }
}
