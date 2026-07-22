<?php

namespace App\Services\Patient\Projection;

use App\Models\Patient\PatientContentAction;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientEncounterProjection;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientReleasePolicyVersion;
use App\Services\Patient\PatientAccessAuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PatientProjectionDisclosureService
{
    private const CORRECTION_NOTICE = 'Your care team updated this information. Please use the details shown here.';

    /** @var array<string, string> */
    public const REQUIRED_SCOPES = [
        'today' => 'today:read',
        'pathway' => 'pathway:read',
        'pathway_events' => 'pathway:read',
        'discharge_readiness' => 'pathway:read',
        'rounds_summary' => 'pathway:read',
        'care_team' => 'care_team:read',
    ];

    public function __construct(private readonly PatientAccessAuditRecorder $audit) {}

    /**
     * Resolve and audit one governed disclosure. Every authorization or
     * release failure intentionally collapses to null so callers cannot use
     * response differences as an encounter, relationship, or content oracle.
     *
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}|null
     */
    public function disclose(
        Request $request,
        PatientPrincipal $principal,
        string $encounterUuid,
        string $projectionKind,
    ): ?array {
        return DB::transaction(fn (): ?array => $this->discloseWithinTransaction(
            $request,
            $principal,
            $encounterUuid,
            $projectionKind,
        ));
    }

    /** @return array{data: array<string, mixed>, meta: array<string, mixed>}|null */
    private function discloseWithinTransaction(
        Request $request,
        PatientPrincipal $principal,
        string $encounterUuid,
        string $projectionKind,
    ): ?array {
        $requiredScope = self::REQUIRED_SCOPES[$projectionKind] ?? null;
        if ($requiredScope === null) {
            $this->recordDenial($request, $principal, null, $projectionKind);

            return null;
        }

        $grant = PatientEncounterAccessGrant::query()
            ->where('principal_id', $principal->getKey())
            ->where('encounter_uuid', $encounterUuid)
            ->sharedLock()
            ->first();

        if ($grant === null
            || ! Gate::forUser($principal)->allows('view', $grant)
            || ! $grant->permits($requiredScope)) {
            $this->recordDenial($request, $principal, $grant, $projectionKind);

            return null;
        }

        $policy = PatientReleasePolicyVersion::query()
            ->effective()
            ->where('version', (string) config('hummingbird-patient.policy_version'))
            ->first();

        if ($policy === null) {
            $this->recordDenial($request, $principal, $grant, $projectionKind);

            return null;
        }

        $projection = PatientEncounterProjection::query()
            ->with(['accessGrant', 'releasePolicyVersion'])
            ->where('access_grant_id', $grant->getKey())
            ->where('projection_kind', $projectionKind)
            ->where('required_scope', $requiredScope)
            ->where('release_policy_version_id', $policy->getKey())
            ->where('release_state', 'released')
            ->where('released_at', '<=', now())
            ->orderByDesc('released_at')
            ->orderByDesc('projection_sequence')
            ->lockForUpdate()
            ->first();

        if ($projection === null || ! Gate::forUser($principal)->allows('view', $projection)) {
            $this->recordDenial($request, $principal, $grant, $projectionKind);

            return null;
        }

        // Successful disclosure fails closed when immutable evidence cannot be
        // recorded; the payload is built only after that audit commit succeeds.
        $this->audit->record(
            $request,
            'patient.projection.disclosed',
            'access',
            'view_'.$projectionKind,
            'allowed',
            $principal,
            grant: $grant,
            resourceType: 'patient_'.$projectionKind.'_projection',
            resourceUuid: (string) $projection->projection_uuid,
            metadata: [
                'projection_sequence' => (int) $projection->projection_sequence,
            ],
        );

        return [
            'data' => $this->patientSafeData($grant, $projection),
            'meta' => [
                'source_freshness' => [
                    'status' => (string) $projection->freshness_class,
                    'observed_at' => $projection->source_observed_at?->toISOString(),
                ],
                'policy_version' => (string) $policy->version,
                'version' => (int) $projection->projection_sequence,
                'as_of' => $projection->released_at?->toISOString(),
                'stale' => $projection->freshness_class === 'stale',
            ],
        ];
    }

    private function recordDenial(
        Request $request,
        PatientPrincipal $principal,
        ?PatientEncounterAccessGrant $grant,
        string $projectionKind,
    ): void {
        $this->audit->bestEffort(
            $request,
            'patient.projection.disclosure_denied',
            'access',
            'view_'.($projectionKind === 'care_team' ? 'care_team' : $projectionKind),
            'denied',
            $principal,
            grant: $grant,
            reasonCode: 'projection_not_available',
            resourceType: 'patient_projection',
            metadata: [
                'projection_kind' => in_array($projectionKind, array_keys(self::REQUIRED_SCOPES), true)
                    ? $projectionKind
                    : 'unsupported',
            ],
        );
    }

    /** @return array<string, mixed> */
    private function patientSafeData(
        PatientEncounterAccessGrant $grant,
        PatientEncounterProjection $projection,
    ): array {
        $provenance = collect((array) $projection->provenance)
            ->only(['projection_method', 'source_class', 'input_classes', 'review_state', 'producer_version'])
            ->all();

        $data = [
            'projection_uuid' => (string) $projection->projection_uuid,
            'encounter_uuid' => (string) $grant->encounter_uuid,
            'kind' => (string) $projection->projection_kind,
            'content' => (object) ((array) $projection->content),
            'uncertainty' => (object) ((array) $projection->uncertainty),
            'provenance' => (object) $provenance,
            'observed_at' => $projection->source_observed_at?->toISOString(),
            'generated_at' => $projection->generated_at?->toISOString(),
            'released_at' => $projection->released_at?->toISOString(),
        ];

        if ($this->isEffectiveCorrectionReplacement($projection)) {
            // This deliberately contains no source projection handle, reason,
            // actor, or correction timestamp. A patient can understand that the
            // displayed release supersedes earlier information without using the
            // response as an oracle for withdrawn or staff-only records.
            $data['revision_notice'] = [
                'kind' => 'correction',
                'message' => self::CORRECTION_NOTICE,
            ];
        }

        return $data;
    }

    private function isEffectiveCorrectionReplacement(PatientEncounterProjection $projection): bool
    {
        if ($projection->supersedes_projection_id === null) {
            return false;
        }

        return PatientContentAction::query()
            ->where('target_projection_id', $projection->supersedes_projection_id)
            ->where('replacement_projection_id', $projection->getKey())
            ->where('release_policy_version_id', $projection->release_policy_version_id)
            ->where('action_type', 'correction')
            ->where('effective_at', '<=', now())
            ->whereHas('targetProjection', function ($query) use ($projection): void {
                $query
                    ->where('access_grant_id', $projection->access_grant_id)
                    ->where('projection_kind', $projection->projection_kind)
                    ->where('release_policy_version_id', $projection->release_policy_version_id);
            })
            ->exists();
    }
}
