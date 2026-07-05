<?php

namespace App\Http\Controllers\Api\Deployment\Staffing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deployment\Staffing\RecordStaffReviewRequest;
use App\Http\Requests\Deployment\Staffing\StartStaffImportRequest;
use App\Models\Org\StaffAssignment;
use App\Models\Org\StaffImportRun;
use App\Models\Org\StaffingSource;
use App\Models\Org\StaffMappingReview;
use App\Models\Org\StaffMember;
use App\Services\Staffing\StaffImportOrchestrator;
use App\Services\Staffing\StaffImportStore;
use App\Services\Staffing\StaffingConnectorFactory;
use App\Services\Staffing\Support\ImportResult;
use App\Services\Staffing\Support\PullWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase F4 (§8): the wizard's import lifecycle — dry-run stage+resolve, read the
 * staged buckets, re-resolve after a rule promotion, record per-member review
 * decisions, and commit. Gated by manageDeploymentConfig.
 *
 * Assignments are written ONLY by StaffImportOrchestrator::commit and accounts are
 * touched ONLY (additively) by StaffProvisioningService — this controller orchestrates
 * those, it never writes prod.users itself.
 */
class StaffImportController extends Controller
{
    public function __construct(
        private readonly StaffingConnectorFactory $factory,
        private readonly StaffImportOrchestrator $orchestrator,
        private readonly StaffImportStore $store,
    ) {}

    public function store(StartStaffImportRequest $request): JsonResponse
    {
        $source = $this->resolveSource($request->input('source_id'), $request->input('source_key'));
        if ($source === null) {
            return response()->json(['message' => 'Unknown staffing source.'], 404);
        }

        $facilityKey = (string) $request->input('facility_key');

        try {
            $connector = $this->factory->make($source, array_filter([
                'csv' => $request->input('csv'),
                'bundle' => $request->input('bundle'),
                'mapping' => $request->input('mapping'),
            ], fn ($v): bool => $v !== null));

            $probe = $connector->testConnection();
            if (! $probe->ok) {
                return response()->json(['message' => "Connection failed: {$probe->message}"], 422);
            }

            $result = $this->orchestrator->run($source, $connector, $facilityKey, PullWindow::full(), [
                'dry_run' => true,
                'initiated_by' => $request->user()?->id,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $staged = $this->store->persist($result, $facilityKey);

        return response()->json([
            'data' => ['run' => $this->presentRun($result->run->refresh()), 'staged' => $staged],
        ], 201);
    }

    public function show(StaffImportRun $run): JsonResponse
    {
        return response()->json([
            'data' => ['run' => $this->presentRun($run), 'staged' => $this->store->payload($run)],
        ]);
    }

    public function resolve(StaffImportRun $run): JsonResponse
    {
        if ($run->status === 'committed') {
            return response()->json(['message' => 'This import has already been committed.'], 409);
        }

        $source = $run->source;
        if ($source === null) {
            return response()->json(['message' => 'The source for this import no longer exists.'], 404);
        }

        $facilityKey = $this->store->facilityKey($run) ?? (string) config('hospital.default_facility', 'SUMMIT_REGIONAL');
        $records = $this->store->records($run);

        $result = $this->orchestrator->reresolve($run, $source, $records, $facilityKey);
        $staged = $this->store->refresh($run, $result, $facilityKey);

        return response()->json([
            'data' => ['run' => $this->presentRun($run->refresh()), 'staged' => $staged],
        ]);
    }

    public function review(RecordStaffReviewRequest $request, StaffImportRun $run, int $staffMember): JsonResponse
    {
        if ($run->status === 'committed') {
            return response()->json(['message' => 'This import has already been committed.'], 409);
        }

        $decision = $request->safe()->only(['action', 'assignments', 'note']);

        if ($error = $this->store->validateDecision($decision)) {
            return response()->json(['message' => $error], 422);
        }

        $item = $this->store->setDecision($run, $staffMember, $decision);
        if ($item === null) {
            return response()->json(['message' => 'That staff member is not part of this import.'], 404);
        }

        return response()->json(['data' => ['item' => $item]]);
    }

    public function commit(StaffImportRun $run): JsonResponse
    {
        if ($run->status === 'committed') {
            return response()->json(['message' => 'This import has already been committed.'], 409);
        }

        $source = $run->source;
        if ($source === null) {
            return response()->json(['message' => 'The source for this import no longer exists.'], 404);
        }

        $facilityKey = $this->store->facilityKey($run) ?? (string) config('hospital.default_facility', 'SUMMIT_REGIONAL');
        ['result' => $result, 'deactivate' => $deactivateIds] = $this->store->reconstructForCommit($run);

        $reviewerId = request()->user()?->id;
        $deactivated = $this->deactivate($run, $deactivateIds, $reviewerId);

        $summary = $this->orchestrator->commit($result, $facilityKey, [
            'buckets' => ImportResult::BUCKETS,
            'reviewer_id' => $reviewerId,
        ]);

        $source->update(['last_synced_at' => now()]);

        return response()->json([
            'data' => [
                'summary' => array_merge($summary, ['deactivated' => $deactivated]),
                'run' => $this->presentRun($run->refresh()),
            ],
        ]);
    }

    /**
     * Soft-deactivate the active assignments (and the member) for each 'deactivate'
     * decision, and log an audit review. Never a hard delete.
     *
     * @param  list<int>  $staffMemberIds
     */
    private function deactivate(StaffImportRun $run, array $staffMemberIds, ?int $reviewerId): int
    {
        if ($staffMemberIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($run, $staffMemberIds, $reviewerId): int {
            $count = StaffAssignment::query()
                ->whereIn('staff_member_id', $staffMemberIds)
                ->where('is_active', true)
                ->update(['is_active' => false, 'effective_end' => now()->toDateString()]);

            StaffMember::query()->whereIn('staff_member_id', $staffMemberIds)->update(['is_active' => false]);

            foreach ($staffMemberIds as $id) {
                StaffMappingReview::create([
                    'staff_import_run_id' => $run->staff_import_run_id,
                    'staff_member_id' => $id,
                    'proposed' => [],
                    'final' => [],
                    'action' => 'deactivate',
                    'reviewer_id' => $reviewerId,
                ]);
            }

            return $count;
        });
    }

    private function resolveSource(mixed $id, mixed $key): ?StaffingSource
    {
        if ($id !== null && $id !== '') {
            return StaffingSource::find((int) $id);
        }

        if (is_string($key) && $key !== '') {
            return StaffingSource::where('source_key', $key)->first();
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function presentRun(StaffImportRun $run): array
    {
        return [
            'staff_import_run_id' => (int) $run->staff_import_run_id,
            'staffing_source_id' => (int) $run->staffing_source_id,
            'source_key' => $run->source?->source_key,
            'status' => $run->status,
            'dry_run' => (bool) $run->dry_run,
            'counts' => (object) (is_array($run->counts) ? $run->counts : []),
            'facility_key' => $this->store->facilityKey($run),
            'started_at' => optional($run->started_at)->toISOString(),
            'completed_at' => optional($run->completed_at)->toISOString(),
        ];
    }
}
