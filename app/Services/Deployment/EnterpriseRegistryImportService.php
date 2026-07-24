<?php

namespace App\Services\Deployment;

use App\Authorization\GovernedAction;
use App\Models\Governance\GovernedChangeRequest;
use App\Services\Deployment\Concerns\UpsertsPgRows;
use App\Services\Governance\GovernedChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ENT-REG — governed enterprise registry import.
 *
 * Accepts a registry payload (organizations, markets, facilities, service lines,
 * locations/spaces, capabilities, transfer relationships), computes an additive,
 * non-destructive PREVIEW (what would be created, updated, conflicted, or left
 * unchanged, plus a readiness score), and applies a governed COMMIT atomically
 * under the dual-control GovernedChangeService with step-up + audit + an
 * append-only enterprise change-history row per changed entity.
 *
 * A conflict is a same-external-identifier collision that points at a different
 * existing natural key, or a payload record whose external identifiers already
 * belong to a different entity. Conflicts must be explicitly resolved by the
 * operator ('adopt' — take the payload attributes onto the existing entity — or
 * 'skip' — leave the existing entity untouched) before commit; an unresolved
 * conflict fails the commit closed. Existing enterprise data is never deleted;
 * every apply is an upsert with effective dating and change history.
 *
 * Plan: docs/plans/ADMIN-INTEROPERABILITY-CONTROL-PLANE-PLAN-2026-07-12.md (ENT-REG)
 */
final class EnterpriseRegistryImportService
{
    use UpsertsPgRows;

    public const IMPORT_SUBJECT = 'enterprise_registry';

    /** Per-entity detail rows in a preview are capped so the payload stays bounded. */
    private const DETAIL_CAP = 200;

    public function __construct(
        private readonly GovernedChangeService $governance,
        private readonly EnterpriseRegistryNormalizer $normalizer,
    ) {}

    /**
     * Compute a non-persisting preview of an inbound registry payload.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $conflictResolutions  conflictKey => 'adopt'|'skip'
     * @return array<string, mixed>
     */
    public function preview(array $payload, array $conflictResolutions = []): array
    {
        $normalized = $this->normalizer->normalize($payload);
        // Preview classification only needs to know an org reference *will* resolve,
        // not its concrete id, so payload-declared orgs count as resolvable here.
        $organizationIds = $this->organizationIdResolution($normalized, includePayloadDeclared: true);
        $entities = [];
        $summary = ['create' => 0, 'update' => 0, 'conflict' => 0, 'no_change' => 0, 'blocked' => 0];
        $conflicts = [];

        foreach ($normalized as $entityType => $records) {
            $current = $this->currentState($entityType);
            $externalIndex = $this->externalIdentifierIndex($current);
            $rows = [];

            foreach ($records as $record) {
                $plan = $this->planRecord($entityType, $record, $current, $externalIndex, $conflictResolutions, $organizationIds);
                $summary[$plan['change_kind']]++;
                if ($plan['change_kind'] === 'conflict') {
                    $conflicts[] = [
                        'conflictKey' => $plan['conflict_key'],
                        'entityType' => $entityType,
                        'naturalKey' => $plan['natural_key'],
                        'reason' => $plan['conflict_reason'],
                        'collidingNaturalKey' => $plan['colliding_natural_key'],
                        'resolution' => $conflictResolutions[$plan['conflict_key']] ?? null,
                    ];
                }
                if (count($rows) < self::DETAIL_CAP) {
                    $rows[] = [
                        'naturalKey' => $plan['natural_key'],
                        'displayName' => $plan['display_name'],
                        'changeKind' => $plan['change_kind'],
                        'changedFields' => $plan['changed_fields'],
                        'conflictKey' => $plan['conflict_key'],
                        'conflictReason' => $plan['conflict_reason'],
                        'blockedReason' => $plan['blocked_reason'],
                    ];
                }
            }

            $entities[$entityType] = [
                'total' => count($records),
                'rows' => $rows,
            ];
        }

        $readiness = $this->readinessScore($summary, $conflicts);

        return [
            'summary' => $summary,
            'entities' => $entities,
            'conflicts' => $conflicts,
            'unresolvedConflictCount' => collect($conflicts)
                ->filter(fn (array $conflict): bool => $conflict['resolution'] === null)
                ->count(),
            'readiness' => $readiness,
            'payloadSha256' => $this->hashPlan($normalized, $conflictResolutions),
        ];
    }

    /**
     * Create the governed change request for an import commit. The payload hash
     * binds the exact normalized plan plus the operator's conflict resolutions,
     * so the committed apply must reproduce the reviewed preview byte-for-byte.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $conflictResolutions
     */
    public function requestCommit(
        Request $request,
        array $payload,
        array $conflictResolutions,
        string $reason,
    ): GovernedChangeRequest {
        $preview = $this->preview($payload, $conflictResolutions);
        if ($preview['unresolvedConflictCount'] > 0) {
            throw ValidationException::withMessages([
                'conflicts' => ['Every enterprise import conflict must be resolved before commit.'],
            ]);
        }
        if ($preview['summary']['create'] === 0 && $preview['summary']['update'] === 0) {
            throw ValidationException::withMessages([
                'payload' => ['The enterprise import would create or update nothing.'],
            ]);
        }

        return $this->governance->requestChange(
            $request,
            GovernedAction::ApplyEnterpriseRegistryImport,
            self::IMPORT_SUBJECT,
            self::IMPORT_SUBJECT,
            $reason,
            (string) $preview['payloadSha256'],
            null,
            [
                'create' => $preview['summary']['create'],
                'update' => $preview['summary']['update'],
                'no_change' => $preview['summary']['no_change'],
                'readiness_score' => $preview['readiness']['score'],
            ],
        );
    }

    /**
     * Apply an approved import. Re-derives the plan from the same payload and
     * resolutions, asserts the hash still matches the approved change, then
     * upserts every create/update entity with effective dating, source-of-truth,
     * external identifiers, ownership, and one append-only change-history row.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $conflictResolutions
     * @return array<string, mixed>
     */
    public function applyApproved(
        Request $request,
        string $changeRequestUuid,
        array $payload,
        array $conflictResolutions,
    ): array {
        $normalized = $this->normalizer->normalize($payload);
        $payloadHash = $this->hashPlan($normalized, $conflictResolutions);

        return $this->governance->executeApproved(
            $request,
            $changeRequestUuid,
            GovernedAction::ApplyEnterpriseRegistryImport,
            self::IMPORT_SUBJECT,
            self::IMPORT_SUBJECT,
            $payloadHash,
            function (GovernedChangeRequest $change) use ($request, $normalized, $conflictResolutions): array {
                return DB::transaction(function () use ($request, $change, $normalized, $conflictResolutions): array {
                    $applied = ['create' => 0, 'update' => 0, 'no_change' => 0, 'skipped_conflicts' => 0, 'blocked' => 0];
                    $actorUserId = $request->user()?->getKey();

                    // Organizations first so market/facility FK references resolve to
                    // rows created earlier in the same transaction.
                    $ordered = $this->orderByDependency($normalized);
                    foreach ($ordered as $entityType) {
                        $records = $normalized[$entityType];
                        foreach ($records as $record) {
                            $current = $this->currentState($entityType);
                            $externalIndex = $this->externalIdentifierIndex($current);
                            $organizationIds = $this->organizationIdResolution($normalized);
                            $plan = $this->planRecord($entityType, $record, $current, $externalIndex, $conflictResolutions, $organizationIds);
                            if ($plan['change_kind'] === 'conflict') {
                                // An unresolved conflict cannot reach here because
                                // requestCommit blocks the commit until every conflict resolves.
                                throw new \RuntimeException('Unresolved enterprise import conflict at apply time.');
                            }
                            if ($plan['change_kind'] === 'skipped') {
                                $applied['skipped_conflicts']++;

                                continue;
                            }
                            if ($plan['change_kind'] === 'blocked') {
                                $applied['blocked']++;

                                continue;
                            }
                            if ($plan['change_kind'] === 'no_change') {
                                $applied['no_change']++;

                                continue;
                            }

                            $this->applyRecord($entityType, $plan, (string) $change->getKey(), $actorUserId);
                            $applied[$plan['change_kind']]++;
                        }
                    }

                    return $applied;
                });
            },
        );
    }

    /**
     * Classify one normalized record against current state and resolutions.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, object>  $current  natural key => current DB row
     * @param  array<string, string>  $externalIndex  "ns:value" => natural key currently owning it
     * @param  array<string, string>  $conflictResolutions
     * @return array<string, mixed>
     */
    private function planRecord(
        string $entityType,
        array $record,
        array $current,
        array $externalIndex,
        array $conflictResolutions,
        array $organizationIds,
    ): array {
        $naturalKey = (string) $record['natural_key'];
        $existing = $current[$naturalKey] ?? null;
        $conflictKey = $entityType.':'.$naturalKey;

        // Resolve natural-key references (organization_key, idn_role) into the
        // concrete attribute set the upsert writes. Missing/invalid references
        // are tolerated for updates but block a create.
        $record = $this->resolveReferences($entityType, $record, $organizationIds);

        // Same external identifier already owned by a DIFFERENT natural key is a conflict.
        $collidingNaturalKey = null;
        foreach ($record['external_identifiers'] as $namespace => $value) {
            $indexKey = $namespace.':'.$value;
            $owner = $externalIndex[$indexKey] ?? null;
            if ($owner !== null && $owner !== $naturalKey) {
                $collidingNaturalKey = $owner;
                break;
            }
        }

        if ($collidingNaturalKey !== null) {
            $resolution = $conflictResolutions[$conflictKey] ?? null;
            if ($resolution === null) {
                return $this->planResult($record, 'conflict', [], $conflictKey,
                    'external_identifier_collision', $collidingNaturalKey, null);
            }
            if ($resolution === 'skip') {
                return $this->planResult($record, 'skipped', [], $conflictKey, null, $collidingNaturalKey, null);
            }
            // 'adopt' falls through to a normal create/update against this natural key.
        }

        if ($existing === null) {
            $missing = $this->missingCreateRequirements($entityType, $record);
            if ($missing !== []) {
                return $this->planResult($record, 'blocked', [], $conflictKey, null, null,
                    'missing_required:'.implode(',', $missing));
            }

            return $this->planResult($record, 'create', array_keys($record['attributes']), $conflictKey, null, null, null);
        }

        $changedFields = $this->changedFields($entityType, $record, $existing);
        if ($changedFields === []) {
            return $this->planResult($record, 'no_change', [], $conflictKey, null, null, null);
        }

        return $this->planResult($record, 'update', $changedFields, $conflictKey, null, null, null);
    }

    /**
     * Fold resolved FK references into the attribute set.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, int>  $organizationIds  organization_key => organization_id
     * @return array<string, mixed>
     */
    private function resolveReferences(string $entityType, array $record, array $organizationIds): array
    {
        $references = $record['references'] ?? [];
        if (isset($references['organization_key'])) {
            $orgId = $organizationIds[$references['organization_key']] ?? null;
            if ($orgId !== null) {
                $record['attributes']['organization_id'] = $orgId;
            }
        }
        if (isset($references['idn_role'])) {
            $record['attributes']['idn_role'] = $references['idn_role'];
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function missingCreateRequirements(string $entityType, array $record): array
    {
        $required = $this->normalizer->entitySpec($entityType)['create_requirements'] ?? [];
        $missing = [];
        foreach ($required as $column) {
            if (! array_key_exists($column, $record['attributes']) || $record['attributes'][$column] === null) {
                $missing[] = $column;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $changedFields
     * @return array<string, mixed>
     */
    private function planResult(
        array $record,
        string $changeKind,
        array $changedFields,
        string $conflictKey,
        ?string $conflictReason,
        ?string $collidingNaturalKey,
        ?string $blockedReason,
    ): array {
        return [
            'natural_key' => (string) $record['natural_key'],
            'display_name' => (string) $record['display_name'],
            'change_kind' => $changeKind,
            'changed_fields' => $changedFields,
            'conflict_key' => $conflictKey,
            'conflict_reason' => $conflictReason,
            'colliding_natural_key' => $collidingNaturalKey,
            'blocked_reason' => $blockedReason,
            'record' => $record,
        ];
    }

    /**
     * Resolve organization_key => organization_id, considering both existing rows
     * and organizations created earlier in this same import (which will exist by
     * the time markets/facilities are applied, given orderByDependency).
     *
     * @param  array<string, list<array<string, mixed>>>  $normalized
     * @return array<string, int>
     */
    private function organizationIdResolution(array $normalized, bool $includePayloadDeclared = false): array
    {
        $resolution = DB::table('hosp_org.organizations')
            ->pluck('organization_id', 'organization_key')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($includePayloadDeclared) {
            // In preview, orgs are not yet committed; a create referencing a
            // payload-declared org still counts as resolvable (sentinel id -1 is
            // never persisted — apply re-resolves the real id after orgs commit).
            foreach ($normalized['organizations'] ?? [] as $record) {
                $key = (string) $record['natural_key'];
                $resolution[$key] ??= -1;
            }
        }

        return $resolution;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $normalized
     * @return list<string>
     */
    private function orderByDependency(array $normalized): array
    {
        $order = ['organizations', 'markets', 'facilities', 'service_lines', 'locations'];

        return array_values(array_filter($order, static fn (string $type): bool => isset($normalized[$type])));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function changedFields(string $entityType, array $record, object $existing): array
    {
        $changed = [];
        foreach ($record['attributes'] as $column => $value) {
            $currentValue = $existing->{$column} ?? null;
            if ($this->normalizeCompare($currentValue) !== $this->normalizeCompare($value)) {
                $changed[] = $column;
            }
        }

        // Ownership + external identifiers are governance metadata, tracked distinctly.
        if ($record['owner_name'] !== null
            && $this->normalizeCompare($existing->owner_name ?? null) !== $this->normalizeCompare($record['owner_name'])) {
            $changed[] = 'owner_name';
        }
        if ($record['steward_name'] !== null
            && $this->normalizeCompare($existing->steward_name ?? null) !== $this->normalizeCompare($record['steward_name'])) {
            $changed[] = 'steward_name';
        }
        $existingExternal = $this->decodeJson($existing->external_identifiers ?? '{}');
        $mergedExternal = [...$existingExternal, ...$record['external_identifiers']];
        if ($mergedExternal !== $existingExternal) {
            $changed[] = 'external_identifiers';
        }

        return array_values(array_unique($changed));
    }

    /**
     * Apply one create/update record: upsert the entity with governance columns
     * and append a change-history row.
     *
     * @param  array<string, mixed>  $plan
     */
    private function applyRecord(string $entityType, array $plan, string $changeRequestUuid, ?int $actorUserId): void
    {
        $spec = $this->normalizer->entitySpec($entityType);
        $record = $plan['record'];
        $current = $this->currentState($entityType)[$plan['natural_key']] ?? null;

        $existingExternal = $current !== null ? $this->decodeJson($current->external_identifiers ?? '{}') : [];
        $mergedExternal = [...$existingExternal, ...$record['external_identifiers']];

        // Effective dating: honor an explicit payload valid_from; otherwise anchor a
        // create at now() and preserve an existing row's valid_from on update.
        $validFrom = $record['valid_from']
            ?? ($current->valid_from ?? null)
            ?? now()->toDateTimeString();

        $row = [
            ...$this->normalizer->naturalKeyBinding($entityType, $record),
            ...$record['attributes'],
            'source_of_truth' => (string) $record['source_of_truth'],
            'external_identifiers' => $mergedExternal,
            'valid_from' => $validFrom,
            'valid_until' => $record['valid_until'] ?? ($current->valid_until ?? null),
        ];
        if ($record['owner_name'] !== null) {
            $row['owner_name'] = $record['owner_name'];
        }
        if ($record['steward_name'] !== null) {
            $row['steward_name'] = $record['steward_name'];
        }

        $this->upsertRow(
            $spec['table'],
            $row,
            $spec['conflict_keys'],
            $spec['array_columns'],
            [...$spec['json_columns'], 'external_identifiers'],
        );

        DB::table('hosp_org.enterprise_change_history')->insert([
            'change_history_uuid' => (string) Str::uuid7(),
            'entity_type' => $spec['change_history_type'],
            'entity_natural_key' => $plan['natural_key'],
            'entity_id' => null,
            'change_kind' => $plan['change_kind'],
            'source_of_truth' => (string) $record['source_of_truth'],
            'governed_change_request_uuid' => $changeRequestUuid,
            'changed_fields' => json_encode(array_values($plan['changed_fields']), JSON_THROW_ON_ERROR),
            'before_state' => json_encode((object) $this->beforeState($current, $plan['changed_fields']), JSON_THROW_ON_ERROR),
            'after_state' => json_encode((object) $this->afterState($record, $plan['changed_fields']), JSON_THROW_ON_ERROR),
            'effective_from' => now(),
            'recorded_by_user_id' => $actorUserId,
            'recorded_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $changedFields
     * @return array<string, mixed>
     */
    private function beforeState(?object $current, array $changedFields): array
    {
        if ($current === null) {
            return [];
        }
        $state = [];
        foreach ($changedFields as $field) {
            $state[$field] = $this->stringifyForHistory($current->{$field} ?? null);
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $changedFields
     * @return array<string, mixed>
     */
    private function afterState(array $record, array $changedFields): array
    {
        $state = [];
        foreach ($changedFields as $field) {
            $value = $record['attributes'][$field]
                ?? ($field === 'owner_name' ? $record['owner_name'] : null)
                ?? ($field === 'steward_name' ? $record['steward_name'] : null)
                ?? ($field === 'external_identifiers' ? $record['external_identifiers'] : null);
            $state[$field] = $this->stringifyForHistory($value);
        }

        return $state;
    }

    private function stringifyForHistory(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        return (string) $value;
    }

    /**
     * @return array<string, object> natural key => current DB row
     */
    private function currentState(string $entityType): array
    {
        $spec = $this->normalizer->entitySpec($entityType);

        return DB::table($spec['table'])
            ->get()
            ->keyBy($spec['natural_key_column'])
            ->map(fn (object $row): object => $row)
            ->all();
    }

    /**
     * @param  array<string, object>  $current
     * @return array<string, string> "ns:value" => natural key
     */
    private function externalIdentifierIndex(array $current): array
    {
        $index = [];
        foreach ($current as $naturalKey => $row) {
            foreach ($this->decodeJson($row->external_identifiers ?? '{}') as $namespace => $value) {
                $index[$namespace.':'.$value] = (string) $naturalKey;
            }
        }

        return $index;
    }

    /**
     * @param  array<string, string>  $summary
     * @param  list<array<string, mixed>>  $conflicts
     * @return array<string, mixed>
     */
    private function readinessScore(array $summary, array $conflicts): array
    {
        $unresolved = collect($conflicts)->filter(fn (array $c): bool => $c['resolution'] === null)->count();
        $applied = $summary['create'] + $summary['update'];
        $blocked = $summary['blocked'] ?? 0;
        // Resolvable records = clean applies + no-change + resolved conflicts.
        // Unresolved conflicts and blocked (un-creatable) records subtract from
        // readiness; a commit is only allowed at 100 (no unresolved conflict).
        $clean = $applied + $summary['no_change'];
        $total = $clean + count($conflicts) + $blocked;
        $resolvable = max(1, $total);
        $score = (int) floor((($total - $unresolved - $blocked) / $resolvable) * 100);

        return [
            'score' => ($unresolved > 0 || $blocked > 0) ? min($score, 99) : 100,
            'committable' => $unresolved === 0 && $applied > 0,
            'appliedCount' => $applied,
            'blockedCount' => $blocked,
            'unresolvedConflictCount' => $unresolved,
        ];
    }

    /**
     * Deterministic hash of the normalized plan plus resolutions.
     *
     * @param  array<string, list<array<string, mixed>>>  $normalized
     * @param  array<string, string>  $conflictResolutions
     */
    private function hashPlan(array $normalized, array $conflictResolutions): string
    {
        ksort($conflictResolutions);

        return $this->governance->hashPayload([
            'normalized' => $normalized,
            'resolutions' => $conflictResolutions,
        ]);
    }

    private function normalizeCompare(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return json_encode($value) ?: '';
        }

        return trim((string) ($value ?? ''));
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
