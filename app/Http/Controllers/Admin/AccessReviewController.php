<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\Capability;
use App\Http\Controllers\Controller;
use App\Models\Governance\AccessReviewCampaign;
use App\Models\Governance\AccessReviewItem;
use App\Models\User;
use App\Services\Authorization\RoleCapabilityService;
use App\Services\Governance\AccessReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AccessReviewController extends Controller
{
    public function __construct(
        private readonly AccessReviewService $reviews,
        private readonly RoleCapabilityService $authorization,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAccessReviews');

        $campaigns = AccessReviewCampaign::query()
            ->with(['primaryReviewer:id,name,username', 'alternateReviewer:id,name,username'])
            ->withCount('items')
            ->withCount([
                'items as decided_count' => fn (Builder $query) => $query->whereHas('decision'),
                'items as revoke_count' => fn (Builder $query) => $query->whereHas(
                    'decision', fn (Builder $decision) => $decision->where('decision', 'revoke'),
                ),
            ])
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'completed' THEN 1 ELSE 2 END")
            ->orderByDesc('opened_at')
            ->get();

        $requestedUuid = $request->string('campaign')->trim()->toString();
        $selected = $requestedUuid !== ''
            ? $campaigns->firstWhere('campaign_uuid', $requestedUuid)
            : $campaigns->first();

        if ($requestedUuid !== '' && $selected === null) {
            abort(404);
        }

        $selectedDetail = null;
        if ($selected !== null) {
            $selected->load([
                'items' => fn ($query) => $query->orderBy('item_uuid'),
                'items.subject:id,name,username,role,is_active,is_protected',
                'items.reviewer:id,name,username',
                'items.decision.decidedBy:id,name,username',
                'items.decision.remediation',
            ]);
            $selectedDetail = $this->campaignDetail($selected, $request->user());
        }

        return Inertia::render('Admin/AccessReviews', [
            'campaigns' => $campaigns->map(fn (AccessReviewCampaign $campaign): array => $this->campaignSummary($campaign))->all(),
            'selectedCampaign' => $selectedDetail,
            'reviewers' => User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'username', 'role'])
                ->filter(fn (User $user): bool => $this->authorization->allows($user, Capability::ManageAccessReviews))
                ->values()
                ->all(),
            'canManage' => Gate::allows('manageAccessReviews'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manageAccessReviews');
        $validator = validator($request->all(), [
            'title' => ['required', 'string', 'min:6', 'max:160'],
            'review_period_start' => ['required', 'date_format:Y-m-d'],
            'review_period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:review_period_start'],
            'due_at' => ['required', 'date', 'after:today'],
            'primary_reviewer_user_id' => ['required', 'integer', Rule::exists(User::class, 'id')],
            'alternate_reviewer_user_id' => [
                'required', 'integer', 'different:primary_reviewer_user_id', Rule::exists(User::class, 'id'),
            ],
        ]);
        $validator->after(function ($validator): void {
            if ($validator->errors()->hasAny(['review_period_start', 'review_period_end'])) {
                return;
            }
            $start = CarbonImmutable::createFromFormat('Y-m-d', (string) $validator->validated()['review_period_start']);
            $end = CarbonImmutable::createFromFormat('Y-m-d', (string) $validator->validated()['review_period_end']);
            if ($start->day !== 1 || ! in_array($start->month, [1, 4, 7, 10], true)
                || ! $end->isSameDay($start->addMonths(3)->subDay())) {
                $validator->errors()->add('review_period_end', 'The review period must be one complete calendar quarter.');
            }
        });
        $validated = $validator->validate();

        $campaign = $this->reviews->createCampaign(
            $request->user(),
            User::query()->findOrFail($validated['primary_reviewer_user_id']),
            User::query()->findOrFail($validated['alternate_reviewer_user_id']),
            $validated,
            $request,
        );

        return response()->json([
            'campaign_uuid' => $campaign->campaign_uuid,
            'item_count' => $campaign->items->count(),
        ], 201);
    }

    public function decide(
        Request $request,
        AccessReviewCampaign $campaign,
        AccessReviewItem $item,
    ): JsonResponse {
        Gate::authorize('manageAccessReviews');
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['retain', 'revoke'])],
            'reason_code' => ['required', Rule::in([
                'business_need_confirmed',
                'approved_policy_exception',
                'role_or_responsibility_changed',
                'employment_status_changed',
                'duplicate_or_excess_access',
                'policy_noncompliance',
            ])],
            'rationale' => ['required', 'string', 'min:12', 'max:1000'],
        ]);

        $retainReasons = ['business_need_confirmed', 'approved_policy_exception'];
        if (($validated['decision'] === 'retain') !== in_array($validated['reason_code'], $retainReasons, true)) {
            throw ValidationException::withMessages([
                'reason_code' => ['The reason code does not apply to the selected decision.'],
            ]);
        }

        $decision = $this->reviews->decide(
            $campaign,
            $item,
            $request->user(),
            $validated['decision'],
            $validated['reason_code'],
            trim($validated['rationale']),
            $request,
        );

        return response()->json([
            'decision_uuid' => $decision->decision_uuid,
            'decision' => $decision->decision,
            'remediated' => $decision->remediation !== null,
        ], 201);
    }

    public function complete(Request $request, AccessReviewCampaign $campaign): JsonResponse
    {
        Gate::authorize('manageAccessReviews');
        $completed = $this->reviews->complete($campaign, $request->user(), $request);

        return response()->json([
            'campaign_uuid' => $completed->campaign_uuid,
            'status' => $completed->status,
            'evidence_sha256' => $completed->evidence_sha256,
        ]);
    }

    public function cancel(Request $request, AccessReviewCampaign $campaign): JsonResponse
    {
        Gate::authorize('manageAccessReviews');
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:12', 'max:500'],
        ]);
        $cancelled = $this->reviews->cancel($campaign, $request->user(), trim($validated['reason']), $request);

        return response()->json([
            'campaign_uuid' => $cancelled->campaign_uuid,
            'status' => $cancelled->status,
        ]);
    }

    public function evidenceJson(Request $request, AccessReviewCampaign $campaign): HttpResponse
    {
        Gate::authorize('viewAccessReviews');
        $evidence = $this->reviews->jsonEvidence($campaign);
        $this->reviews->recordExport($campaign, $request->user(), 'json', $evidence['sha256'], $request);

        return response($evidence['content'], 200, $this->evidenceHeaders($campaign, 'json', 'application/json', $evidence['sha256']));
    }

    public function evidenceCsv(Request $request, AccessReviewCampaign $campaign): HttpResponse
    {
        Gate::authorize('viewAccessReviews');
        $evidence = $this->reviews->csvEvidence($campaign);
        $this->reviews->recordExport($campaign, $request->user(), 'csv', $evidence['sha256'], $request);

        return response($evidence['content'], 200, $this->evidenceHeaders($campaign, 'csv', 'text/csv; charset=UTF-8', $evidence['sha256']));
    }

    /** @return array<string, string> */
    private function evidenceHeaders(AccessReviewCampaign $campaign, string $extension, string $contentType, string $sha256): array
    {
        $base64Digest = base64_encode(hex2bin($sha256));

        return [
            'Content-Type' => $contentType,
            'Content-Disposition' => sprintf('attachment; filename="access-review-%s.%s"', $campaign->campaign_uuid, $extension),
            'Cache-Control' => 'private, no-store',
            'Content-Digest' => 'sha-256=:'.$base64Digest.':',
            'Digest' => 'sha-256='.$base64Digest,
            'ETag' => '"'.$sha256.'"',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    /** @return array<string, mixed> */
    private function campaignSummary(AccessReviewCampaign $campaign): array
    {
        return [
            'campaignUuid' => $campaign->campaign_uuid,
            'title' => $campaign->title,
            'status' => $campaign->status,
            'dueAt' => $campaign->due_at?->toIso8601String(),
            'openedAt' => $campaign->opened_at?->toIso8601String(),
            'completedAt' => $campaign->completed_at?->toIso8601String(),
            'cancelledAt' => $campaign->cancelled_at?->toIso8601String(),
            'cancellationReason' => $campaign->cancellation_reason,
            'itemCount' => (int) $campaign->items_count,
            'decidedCount' => (int) $campaign->decided_count,
            'revokeCount' => (int) $campaign->revoke_count,
            'primaryReviewer' => $campaign->primaryReviewer?->only(['id', 'name', 'username']),
            'alternateReviewer' => $campaign->alternateReviewer?->only(['id', 'name', 'username']),
            'snapshotSha256' => $campaign->snapshot_sha256,
            'evidenceSha256' => $campaign->evidence_sha256,
        ];
    }

    /** @return array<string, mixed> */
    private function campaignDetail(AccessReviewCampaign $campaign, User $viewer): array
    {
        return [
            ...$this->campaignSummary($campaign),
            'reviewPeriodStart' => $campaign->review_period_start?->toDateString(),
            'reviewPeriodEnd' => $campaign->review_period_end?->toDateString(),
            'snapshotAt' => $campaign->snapshot_at?->toIso8601String(),
            'items' => $campaign->items->map(function (AccessReviewItem $item) use ($campaign, $viewer): array {
                return [
                    'itemUuid' => $item->item_uuid,
                    'subject' => $item->subject?->only(['id', 'name', 'username', 'role', 'is_active', 'is_protected']),
                    'reviewer' => $item->reviewer?->only(['id', 'name', 'username']),
                    'snapshot' => $item->entitlement_snapshot,
                    'snapshotSha256' => $item->snapshot_sha256,
                    'riskFlags' => $item->risk_flags,
                    'decision' => $item->decision ? [
                        'decisionUuid' => $item->decision->decision_uuid,
                        'value' => $item->decision->decision,
                        'reasonCode' => $item->decision->reason_code,
                        'rationale' => $item->decision->rationale,
                        'decidedAt' => $item->decision->decided_at?->toIso8601String(),
                        'decidedBy' => $item->decision->decidedBy?->only(['id', 'name', 'username']),
                        'remediated' => $item->decision->remediation !== null,
                    ] : null,
                    'canDecide' => $campaign->status === 'open'
                        && $item->decision === null
                        && (int) $item->reviewer_user_id === (int) $viewer->getKey(),
                ];
            })->all(),
        ];
    }
}
