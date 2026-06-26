<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportEvent;
use App\Models\Transport\TransportRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegionalTransferService
{
    /** @return array<string,mixed> */
    public function summary(): array
    {
        $this->seedNetwork();
        $this->seedModelVersions();

        $activeTransfers = TransportRequest::active()
            ->where('request_type', 'transfer')
            ->orderByRaw("CASE priority WHEN 'stat' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END")
            ->orderByRaw('needed_at NULLS LAST')
            ->get();

        $recommendations = $activeTransfers
            ->map(fn (TransportRequest $request): array => $this->recommendationFor($request))
            ->values()
            ->all();

        $facilities = $this->facilities()->map(fn (object $facility): array => $this->serializeFacility($facility))->values();
        $routeSimulation = $this->buildRouteSimulation($activeTransfers, $recommendations);

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'counts' => [
                'networkFacilities' => $facilities->count(),
                'internalFacilities' => $facilities->where('isExternal', false)->count(),
                'externalFacilities' => $facilities->where('isExternal', true)->count(),
                'acceptingFacilities' => $facilities->where('acceptsTransfers', true)->count(),
                'availableBeds' => $facilities->sum('availableBeds'),
                'icuAvailableBeds' => $facilities->sum('icuAvailableBeds'),
                'activeTransfers' => $activeTransfers->count(),
                'pendingDecisions' => $this->pendingDecisionCount($activeTransfers),
                'modelVersions' => $this->modelVersions()->count(),
                'routeScenarios' => count($routeSimulation['scenarios']),
                'agentDrafts' => $this->pendingAgentDraftCount($activeTransfers),
            ],
            'facilities' => $facilities->all(),
            'modelVersions' => $this->modelVersions()->values()->all(),
            'comparison' => $this->regionalComparison($facilities, $recommendations),
            'routeSimulation' => $routeSimulation,
            'transferCenterAgent' => $this->agentSummary($recommendations),
            'recommendations' => $recommendations,
        ];
    }

    /** @param array<string,mixed> $payload */
    public function decide(TransportRequest $request, array $payload, ?int $userId): array
    {
        if ($request->request_type !== 'transfer') {
            abort(422, 'Regional transfer decisions only apply to transfer requests.');
        }

        $this->seedNetwork();
        $this->seedModelVersions();

        return DB::transaction(function () use ($request, $payload, $userId): array {
            $recommendation = $this->recommendationFor($request);
            $facilityCode = (string) ($payload['selected_facility_code'] ?? ($recommendation['candidates'][0]['facilityCode'] ?? ''));
            $selected = collect($recommendation['candidates'])->firstWhere('facilityCode', $facilityCode);

            if (! $selected) {
                abort(422, 'Selected facility is not a current candidate.');
            }

            $decisionStatus = (string) ($payload['decision_status'] ?? 'draft');
            $decision = $this->storeDecision(
                request: $request,
                recommendation: $recommendation,
                selected: $selected,
                decisionStatus: $decisionStatus,
                note: $payload['note'] ?? null,
                userId: $userId,
                eventType: 'regional.transfer_decision',
                metadataKey: 'regional_transfer'
            );

            return $decision + ['selectedFacility' => $selected];
        });
    }

    /** @return array<string,mixed> */
    public function runRouteSimulation(?string $modelVersionKey, ?int $userId): array
    {
        $this->seedNetwork();
        $this->seedModelVersions();

        $activeTransfers = TransportRequest::active()
            ->where('request_type', 'transfer')
            ->orderByRaw("CASE priority WHEN 'stat' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END")
            ->orderByRaw('needed_at NULLS LAST')
            ->get();

        $recommendations = $activeTransfers
            ->map(fn (TransportRequest $request): array => $this->recommendationFor($request))
            ->values()
            ->all();

        $simulation = $this->buildRouteSimulation($activeTransfers, $recommendations, $modelVersionKey ?: 'phase8-network-v1');
        $runUuid = (string) Str::uuid();
        $runId = DB::table('regional.route_simulation_runs')->insertGetId([
            'route_simulation_run_uuid' => $runUuid,
            'model_version_key' => $simulation['modelVersionKey'],
            'scenario_payload' => json_encode($simulation['scenarioInputs']),
            'results_payload' => json_encode([
                'generatedAtIso' => $simulation['generatedAtIso'],
                'baseline' => $simulation['baseline'],
                'scenarios' => $simulation['scenarios'],
            ]),
            'created_by_user_id' => $userId,
            'executed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'route_simulation_run_id');

        return [
            'runId' => $runId,
            'runUuid' => $runUuid,
            'modelVersionKey' => $simulation['modelVersionKey'],
            'generatedAtIso' => $simulation['generatedAtIso'],
            'scenarios' => $simulation['scenarios'],
        ];
    }

    /** @return array<string,mixed> */
    public function draftWithAgent(TransportRequest $request, ?int $userId): array
    {
        if ($request->request_type !== 'transfer') {
            abort(422, 'Transfer-center agent drafts only apply to transfer requests.');
        }

        $this->seedNetwork();
        $this->seedModelVersions();

        return DB::transaction(function () use ($request, $userId): array {
            $recommendation = $this->recommendationFor($request);
            $agentDraft = $this->agentRecommendationFor($recommendation);
            $facilityCode = (string) ($agentDraft['selectedFacilityCode'] ?? '');
            $selected = $facilityCode
                ? collect($recommendation['candidates'])->firstWhere('facilityCode', $facilityCode)
                : ($recommendation['candidates'][0] ?? null);

            if (! $selected) {
                abort(422, 'No current facility candidates are available for this transfer.');
            }

            $decision = $this->storeDecision(
                request: $request,
                recommendation: $recommendation,
                selected: $selected,
                decisionStatus: 'draft',
                note: 'Transfer-center agent draft recommendation.',
                userId: $userId,
                eventType: 'regional.transfer_agent_draft',
                metadataKey: 'regional_agent_draft',
                extraRationale: [
                    'agent' => [
                        'key' => 'transfer_center_agent',
                        'mode' => 'rules_only',
                        'llm_enabled' => false,
                        'recommended_decision' => $agentDraft['recommendedDecision'],
                        'confidence' => $agentDraft['confidence'],
                        'guardrails' => $agentDraft['guardrails'],
                    ],
                ]
            );

            return $decision + [
                'recommendedDecision' => $agentDraft['recommendedDecision'],
                'confidence' => $agentDraft['confidence'],
                'selectedFacility' => $selected,
                'evidence' => $agentDraft['evidence'],
                'guardrails' => $agentDraft['guardrails'],
            ];
        });
    }

    /** @return array<string,mixed> */
    public function recommendationFor(TransportRequest $request): array
    {
        $candidates = $this->facilities()
            ->map(fn (object $facility): array => $this->scoreFacility($facility, $request))
            ->sortByDesc('score')
            ->values()
            ->all();

        return [
            'transportRequestId' => $request->transport_request_id,
            'patientRef' => $request->patient_ref,
            'origin' => $request->origin,
            'destination' => $request->destination,
            'priority' => $request->priority,
            'clinicalService' => $request->clinical_service,
            'neededAt' => $request->needed_at?->toISOString(),
            'currentStatus' => $request->status,
            'candidates' => $candidates,
        ];
    }

    /**
     * @param  array<string,mixed>  $recommendation
     * @param  array<string,mixed>  $selected
     * @param  array<string,mixed>  $extraRationale
     * @return array<string,mixed>
     */
    private function storeDecision(
        TransportRequest $request,
        array $recommendation,
        array $selected,
        string $decisionStatus,
        mixed $note,
        ?int $userId,
        string $eventType,
        string $metadataKey,
        array $extraRationale = []
    ): array {
        $facility = DB::table('regional.facilities')->where('facility_code', $selected['facilityCode'])->first();
        $timestamp = now();
        $decisionId = DB::table('regional.transfer_decisions')->insertGetId([
            'decision_uuid' => (string) Str::uuid(),
            'transport_request_id' => $request->transport_request_id,
            'selected_facility_id' => $facility?->regional_facility_id,
            'decision_status' => $decisionStatus,
            'selected_score' => (int) $selected['score'],
            'candidate_payload' => json_encode($recommendation['candidates']),
            'constraint_payload' => json_encode($selected['constraints']),
            'opportunity_cost_payload' => json_encode($selected['opportunityCost']),
            'decision_rationale' => json_encode(array_merge([
                'note' => is_string($note) ? $note : null,
                'selected_label' => $selected['facilityName'],
                'rationale' => $selected['rationale'],
                'request_priority' => $request->priority,
                'clinical_service' => $request->clinical_service,
            ], $extraRationale)),
            'decided_by_user_id' => $userId,
            'decided_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], 'transfer_decision_id');

        $metadata = array_merge($request->metadata ?? [], [
            $metadataKey => [
                'decision_id' => $decisionId,
                'decision_status' => $decisionStatus,
                'selected_facility_code' => $selected['facilityCode'],
                'selected_facility_name' => $selected['facilityName'],
                'selected_score' => $selected['score'],
                'recommended_decision' => data_get($extraRationale, 'agent.recommended_decision'),
                'decided_at' => $timestamp->toIso8601String(),
            ],
        ]);

        $request->update(['metadata' => $metadata, 'updated_by_user_id' => $userId]);

        TransportEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'transport_request_id' => $request->transport_request_id,
            'event_type' => $eventType,
            'from_status' => $request->status,
            'to_status' => $request->status,
            'payload' => [
                'decision_id' => $decisionId,
                'decision_status' => $decisionStatus,
                'selected_facility_code' => $selected['facilityCode'],
                'selected_score' => $selected['score'],
                'recommended_decision' => data_get($extraRationale, 'agent.recommended_decision'),
            ],
            'source' => 'regional_transfer_service',
            'actor_user_id' => $userId,
            'occurred_at' => $timestamp,
        ]);

        return [
            'decisionId' => $decisionId,
            'transportRequestId' => $request->transport_request_id,
            'decisionStatus' => $decisionStatus,
        ];
    }

    private function seedNetwork(): void
    {
        foreach ($this->networkCatalog() as $facility) {
            DB::table('regional.facilities')->updateOrInsert(
                ['facility_code' => $facility['facility_code']],
                [
                    'facility_uuid' => (string) Str::uuid(),
                    'facility_name' => $facility['facility_name'],
                    'organization_key' => $facility['organization_key'],
                    'campus_key' => $facility['campus_key'],
                    'building_key' => $facility['building_key'],
                    'service_area_key' => $facility['service_area_key'],
                    'facility_type' => $facility['facility_type'],
                    'status' => $facility['status'],
                    'is_external' => $facility['is_external'],
                    'staffed_beds' => $facility['staffed_beds'],
                    'available_beds' => $facility['available_beds'],
                    'icu_available_beds' => $facility['icu_available_beds'],
                    'ed_boarders' => $facility['ed_boarders'],
                    'transport_minutes' => $facility['transport_minutes'],
                    'accepts_transfers' => $facility['accepts_transfers'],
                    'capacity_payload' => json_encode($facility['capacity']),
                    'metadata' => json_encode($facility['metadata']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $row = DB::table('regional.facilities')->where('facility_code', $facility['facility_code'])->first();
            foreach ($facility['capabilities'] as $capability) {
                DB::table('regional.facility_capabilities')->updateOrInsert(
                    [
                        'regional_facility_id' => $row->regional_facility_id,
                        'capability_key' => $capability['key'],
                    ],
                    [
                        'capability_type' => $capability['type'],
                        'status' => $capability['status'],
                        'score_weight' => $capability['score_weight'],
                        'metadata' => json_encode($capability['metadata'] ?? []),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    private function seedModelVersions(): void
    {
        foreach ($this->modelVersionCatalog() as $version) {
            DB::table('regional.network_model_versions')->updateOrInsert(
                ['version_key' => $version['version_key']],
                [
                    'model_version_uuid' => (string) Str::uuid(),
                    'label' => $version['label'],
                    'status' => $version['status'],
                    'assumptions_payload' => json_encode($version['assumptions']),
                    'facility_payload' => json_encode($version['facilities']),
                    'approved_by_user_id' => null,
                    'approved_at' => $version['approved_at'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    /** @return Collection<int,object> */
    private function facilities(): Collection
    {
        return DB::table('regional.facilities')
            ->where('status', 'active')
            ->orderBy('facility_name')
            ->get();
    }

    /** @return array<string,mixed> */
    private function serializeFacility(object $facility): array
    {
        return [
            'facilityCode' => $facility->facility_code,
            'facilityName' => $facility->facility_name,
            'organizationKey' => $facility->organization_key,
            'campusKey' => $facility->campus_key,
            'buildingKey' => $facility->building_key,
            'serviceAreaKey' => $facility->service_area_key,
            'facilityType' => $facility->facility_type,
            'status' => $facility->status,
            'isExternal' => (bool) $facility->is_external,
            'staffedBeds' => (int) $facility->staffed_beds,
            'availableBeds' => (int) $facility->available_beds,
            'icuAvailableBeds' => (int) $facility->icu_available_beds,
            'edBoarders' => (int) $facility->ed_boarders,
            'transportMinutes' => (int) $facility->transport_minutes,
            'acceptsTransfers' => (bool) $facility->accepts_transfers,
            'capabilities' => DB::table('regional.facility_capabilities')
                ->where('regional_facility_id', $facility->regional_facility_id)
                ->orderByDesc('score_weight')
                ->pluck('capability_key')
                ->values()
                ->all(),
            'capacity' => $this->decodeJson($facility->capacity_payload, []),
        ];
    }

    /** @return Collection<int,array<string,mixed>> */
    private function modelVersions(): Collection
    {
        return DB::table('regional.network_model_versions')
            ->where('status', 'approved')
            ->orderByDesc('approved_at')
            ->get()
            ->map(fn (object $version): array => [
                'versionKey' => $version->version_key,
                'label' => $version->label,
                'status' => $version->status,
                'approvedAt' => $version->approved_at ? (string) $version->approved_at : null,
                'assumptions' => $this->decodeJson($version->assumptions_payload, []),
                'facilityCount' => count($this->decodeJson($version->facility_payload, [])),
            ]);
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $facilities
     * @param  array<int,array<string,mixed>>  $recommendations
     * @return array<int,array<string,mixed>>
     */
    private function regionalComparison(Collection $facilities, array $recommendations): array
    {
        return $facilities
            ->map(function (array $facility) use ($recommendations): array {
                $candidateScores = collect($recommendations)
                    ->map(fn (array $recommendation): ?array => collect($recommendation['candidates'])->firstWhere('facilityCode', $facility['facilityCode']))
                    ->filter()
                    ->values();
                $topChoiceCount = collect($recommendations)
                    ->filter(fn (array $recommendation): bool => ($recommendation['candidates'][0]['facilityCode'] ?? null) === $facility['facilityCode'])
                    ->count();
                $bedPressure = $facility['staffedBeds'] > 0
                    ? (1 - min(1, $facility['availableBeds'] / max(1, $facility['staffedBeds']))) * 45
                    : 45;
                $routePressure = min(25, intdiv((int) $facility['transportMinutes'], 2));
                $boarderPressure = min(30, ((int) $facility['edBoarders']) * 3);
                $pressureScore = (int) min(100, round($bedPressure + $routePressure + $boarderPressure));

                return [
                    'scopeKey' => $facility['facilityCode'],
                    'scopeLabel' => $facility['facilityName'],
                    'organizationKey' => $facility['organizationKey'],
                    'campusKey' => $facility['campusKey'],
                    'buildingKey' => $facility['buildingKey'],
                    'serviceAreaKey' => $facility['serviceAreaKey'],
                    'isExternal' => $facility['isExternal'],
                    'facilityType' => $facility['facilityType'],
                    'staffedBeds' => $facility['staffedBeds'],
                    'availableBeds' => $facility['availableBeds'],
                    'icuAvailableBeds' => $facility['icuAvailableBeds'],
                    'edBoarders' => $facility['edBoarders'],
                    'transportMinutes' => $facility['transportMinutes'],
                    'acceptsTransfers' => $facility['acceptsTransfers'],
                    'capabilityCoverage' => count($facility['capabilities']),
                    'candidateCount' => $candidateScores->count(),
                    'topChoiceCount' => $topChoiceCount,
                    'averageCandidateScore' => $candidateScores->isNotEmpty() ? (int) round($candidateScores->avg('score')) : null,
                    'pressureScore' => $pressureScore,
                    'status' => $facility['acceptsTransfers'] && $pressureScore < 65 ? 'open' : ($pressureScore < 82 ? 'constrained' : 'saturated'),
                    'modelDeltas' => $this->modelDeltasForFacility((string) $facility['facilityCode']),
                ];
            })
            ->sortByDesc(fn (array $row): int => ($row['topChoiceCount'] * 100) + (100 - $row['pressureScore']))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,TransportRequest>  $activeTransfers
     * @param  array<int,array<string,mixed>>  $recommendations
     * @return array<string,mixed>
     */
    private function buildRouteSimulation(Collection $activeTransfers, array $recommendations, string $modelVersionKey = 'phase8-network-v1'): array
    {
        $scenarioInputs = $this->routeScenarioInputs($modelVersionKey);
        $baseline = [
            'activeTransfers' => $activeTransfers->count(),
            'networkAvailableBeds' => (int) $this->facilities()->sum('available_beds'),
            'networkIcuAvailableBeds' => (int) $this->facilities()->sum('icu_available_beds'),
            'modelVersionKey' => $modelVersionKey,
        ];

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'modelVersionKey' => $modelVersionKey,
            'baseline' => $baseline,
            'scenarioInputs' => $scenarioInputs,
            'scenarios' => collect($scenarioInputs)
                ->map(fn (array $scenario): array => $this->evaluateRouteScenario($scenario, $recommendations))
                ->values()
                ->all(),
        ];
    }

    /** @param array<int,array<string,mixed>> $recommendations */
    private function agentSummary(array $recommendations): array
    {
        $drafts = collect($recommendations)
            ->map(fn (array $recommendation): array => $this->agentRecommendationFor($recommendation))
            ->values();

        return [
            'agentKey' => 'transfer_center_agent',
            'label' => 'Transfer Center Agent',
            'mode' => 'rules_only',
            'llmEnabled' => false,
            'guardrails' => [
                'draft_only',
                'human_approval_required',
                'no_external_writeback',
                'uses_current_regional_model',
            ],
            'draftRecommendations' => $drafts->all(),
        ];
    }

    /** @param array<string,mixed> $recommendation */
    private function agentRecommendationFor(array $recommendation): array
    {
        $top = $recommendation['candidates'][0] ?? null;
        $decision = 'deferred';
        if ($top && (int) $top['score'] >= 75 && count($top['constraints']['missing_capabilities']) === 0) {
            $decision = 'accepted';
        } elseif ($top && (int) $top['score'] >= 50) {
            $decision = 'redirected';
        }

        return [
            'transportRequestId' => $recommendation['transportRequestId'],
            'patientRef' => $recommendation['patientRef'],
            'recommendedDecision' => $decision,
            'selectedFacilityCode' => $top['facilityCode'] ?? null,
            'selectedFacilityName' => $top['facilityName'] ?? null,
            'confidence' => $top ? round(max(0.42, min(0.97, ((int) $top['score']) / 100)), 2) : 0.42,
            'evidence' => $top ? [
                'score' => $top['score'],
                'recommendation' => $top['recommendation'],
                'capacitySignal' => $top['rationale']['capacity_signal'],
                'transportSignal' => $top['rationale']['transport_signal'],
                'missingCapabilities' => $top['constraints']['missing_capabilities'],
                'opportunityCost' => $top['opportunityCost'],
            ] : [],
            'guardrails' => [
                'draft_only',
                'human_approval_required',
                'no_external_writeback',
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function routeScenarioInputs(string $modelVersionKey): array
    {
        return [
            [
                'scenarioKey' => 'baseline_best_destination',
                'label' => 'Best scored destination',
                'modelVersionKey' => $modelVersionKey,
                'acceptThreshold' => 75,
                'transportMinutesDelta' => 0,
                'scoreDelta' => 0,
                'icuReserve' => 0,
                'externalPreference' => false,
            ],
            [
                'scenarioKey' => 'campus_load_balance',
                'label' => 'Campus load balance',
                'modelVersionKey' => $modelVersionKey,
                'acceptThreshold' => 70,
                'transportMinutesDelta' => 4,
                'scoreDelta' => 6,
                'icuReserve' => 1,
                'externalPreference' => false,
            ],
            [
                'scenarioKey' => 'critical_care_surge',
                'label' => 'Critical care surge',
                'modelVersionKey' => 'critical-care-surge-v1',
                'acceptThreshold' => 82,
                'transportMinutesDelta' => 6,
                'scoreDelta' => -8,
                'icuReserve' => 2,
                'externalPreference' => true,
            ],
            [
                'scenarioKey' => 'transport_staffed_up',
                'label' => 'Transport staffed up',
                'modelVersionKey' => $modelVersionKey,
                'acceptThreshold' => 72,
                'transportMinutesDelta' => -8,
                'scoreDelta' => 4,
                'icuReserve' => 0,
                'externalPreference' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $scenario
     * @param  array<int,array<string,mixed>>  $recommendations
     * @return array<string,mixed>
     */
    private function evaluateRouteScenario(array $scenario, array $recommendations): array
    {
        $selections = collect($recommendations)
            ->map(function (array $recommendation) use ($scenario): ?array {
                $candidate = collect($recommendation['candidates'])
                    ->map(function (array $candidate) use ($scenario): array {
                        $score = (int) $candidate['score'] + (int) $scenario['scoreDelta'];
                        if ($scenario['externalPreference'] && str_contains((string) $candidate['facilityType'], 'external')) {
                            $score += 8;
                        }
                        $candidate['adjustedScore'] = max(0, min(100, $score));
                        $candidate['adjustedTransportMinutes'] = max(0, ((int) $candidate['transportMinutes']) + (int) $scenario['transportMinutesDelta']);

                        return $candidate;
                    })
                    ->sortByDesc('adjustedScore')
                    ->first();

                if (! $candidate) {
                    return null;
                }

                return [
                    'transportRequestId' => $recommendation['transportRequestId'],
                    'facilityCode' => $candidate['facilityCode'],
                    'facilityName' => $candidate['facilityName'],
                    'adjustedScore' => $candidate['adjustedScore'],
                    'transportMinutes' => $candidate['adjustedTransportMinutes'],
                    'accepted' => $candidate['adjustedScore'] >= (int) $scenario['acceptThreshold'],
                    'icuRequired' => in_array('icu', $candidate['rationale']['required_capabilities'], true),
                ];
            })
            ->filter()
            ->values();

        $accepted = $selections->where('accepted', true);
        $networkAvailableBeds = max(0, ((int) $this->facilities()->sum('available_beds')) - $accepted->count());
        $icuConsumed = $accepted->where('icuRequired', true)->count();
        $networkIcuBeds = max(0, ((int) $this->facilities()->sum('icu_available_beds')) - $icuConsumed - (int) $scenario['icuReserve']);
        $totalTransportMinutes = (int) $accepted->sum('transportMinutes');
        $routeRiskScore = (int) min(100, max(0,
            (count($recommendations) - $accepted->count()) * 18
            + max(0, 6 - $networkIcuBeds) * 4
            + intdiv($totalTransportMinutes, 12)
        ));

        return [
            'scenarioKey' => $scenario['scenarioKey'],
            'label' => $scenario['label'],
            'modelVersionKey' => $scenario['modelVersionKey'],
            'acceptedTransfers' => $accepted->count(),
            'deferredTransfers' => max(0, count($recommendations) - $accepted->count()),
            'netAvailableBeds' => $networkAvailableBeds,
            'netIcuAvailableBeds' => $networkIcuBeds,
            'totalTransportMinutes' => $totalTransportMinutes,
            'averageScore' => $selections->isNotEmpty() ? (int) round($selections->avg('adjustedScore')) : 0,
            'routeRiskScore' => $routeRiskScore,
            'selections' => $selections->all(),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function modelDeltasForFacility(string $facilityCode): array
    {
        return $this->modelVersions()
            ->mapWithKeys(function (array $version) use ($facilityCode): array {
                $assumptions = $version['assumptions'];
                $delta = data_get($assumptions, "capacity_adjustments.{$facilityCode}", []);

                return [$version['versionKey'] => [
                    'availableBedsDelta' => (int) ($delta['available_beds'] ?? 0),
                    'icuBedsDelta' => (int) ($delta['icu_available_beds'] ?? 0),
                    'transportMinutesDelta' => (int) ($delta['transport_minutes'] ?? 0),
                ]];
            })
            ->all();
    }

    /** @return array<string,mixed> */
    private function scoreFacility(object $facility, TransportRequest $request): array
    {
        $capabilities = DB::table('regional.facility_capabilities')
            ->where('regional_facility_id', $facility->regional_facility_id)
            ->get();
        $capabilityKeys = $capabilities->pluck('capability_key')->values()->all();
        $required = $this->requiredCapabilities($request);
        $matched = array_values(array_intersect($required, $capabilityKeys));
        $missing = array_values(array_diff($required, $capabilityKeys));
        $availableBeds = (int) $facility->available_beds;
        $icuBeds = (int) $facility->icu_available_beds;
        $transportMinutes = (int) $facility->transport_minutes;
        $accepts = (bool) $facility->accepts_transfers;

        $score = 35;
        $score += min(24, $availableBeds * 3);
        $score += min(18, $icuBeds * 6);
        $score += count($matched) * 12;
        $score -= count($missing) * 16;
        $score -= min(18, intdiv($transportMinutes, 5));
        $score -= ((int) $facility->ed_boarders) * 2;

        if (! $accepts) {
            $score -= 35;
        }
        if ($request->priority === 'stat' && $transportMinutes <= 25) {
            $score += 10;
        }
        if ($request->destination && str_contains(Str::lower($request->destination), Str::lower(str_replace('_', ' ', $facility->facility_code)))) {
            $score += 8;
        }

        $score = max(0, min(100, $score));

        return [
            'facilityCode' => $facility->facility_code,
            'facilityName' => $facility->facility_name,
            'facilityType' => $facility->facility_type,
            'score' => $score,
            'recommendation' => $score >= 75 ? 'accept' : ($score >= 50 ? 'conditional' : 'defer'),
            'availableBeds' => $availableBeds,
            'icuAvailableBeds' => $icuBeds,
            'transportMinutes' => $transportMinutes,
            'capabilities' => $capabilityKeys,
            'constraints' => [
                'accepts_transfers' => $accepts,
                'missing_capabilities' => $missing,
                'ed_boarders' => (int) $facility->ed_boarders,
                'transport_minutes' => $transportMinutes,
            ],
            'opportunityCost' => [
                'available_beds_after_acceptance' => max(0, $availableBeds - 1),
                'icu_beds_after_acceptance' => in_array('icu', $required, true) ? max(0, $icuBeds - 1) : $icuBeds,
                'ed_boarder_pressure' => (int) $facility->ed_boarders,
            ],
            'rationale' => [
                'matched_capabilities' => $matched,
                'required_capabilities' => $required,
                'capacity_signal' => "{$availableBeds} beds / {$icuBeds} ICU",
                'transport_signal' => "{$transportMinutes} min",
            ],
        ];
    }

    /** @return string[] */
    private function requiredCapabilities(TransportRequest $request): array
    {
        $service = Str::lower((string) $request->clinical_service);
        $mode = Str::lower((string) $request->transport_mode);
        $flags = collect($request->risk_flags ?? [])->map(fn ($flag) => Str::lower((string) $flag))->all();
        $required = ['adult_transfer'];

        if (str_contains($service, 'critical') || str_contains($mode, 'critical') || in_array('monitor', $flags, true)) {
            $required[] = 'icu';
            $required[] = 'critical_care_transport';
        }
        if (str_contains($service, 'card')) {
            $required[] = 'cardiology';
        }
        if (str_contains($service, 'neuro')) {
            $required[] = 'neurosurgery';
        }
        if (str_contains($service, 'trauma')) {
            $required[] = 'trauma';
        }

        return array_values(array_unique($required));
    }

    /** @param Collection<int,TransportRequest> $activeTransfers */
    private function pendingDecisionCount(Collection $activeTransfers): int
    {
        if ($activeTransfers->isEmpty()) {
            return 0;
        }

        $decidedIds = DB::table('regional.transfer_decisions')
            ->whereIn('transport_request_id', $activeTransfers->pluck('transport_request_id'))
            ->whereIn('decision_status', ['accepted', 'redirected', 'deferred'])
            ->pluck('transport_request_id')
            ->unique();

        return $activeTransfers->pluck('transport_request_id')->diff($decidedIds)->count();
    }

    /** @param Collection<int,TransportRequest> $activeTransfers */
    private function pendingAgentDraftCount(Collection $activeTransfers): int
    {
        if ($activeTransfers->isEmpty()) {
            return 0;
        }

        return DB::table('regional.transfer_decisions')
            ->whereIn('transport_request_id', $activeTransfers->pluck('transport_request_id'))
            ->where('decision_status', 'draft')
            ->count();
    }

    /** @return array<int,array<string,mixed>> */
    private function networkCatalog(): array
    {
        return [
            [
                'facility_code' => 'zephyrus_main',
                'facility_name' => 'Zephyrus Academic Medical Center',
                'organization_key' => 'zephyrus-network',
                'campus_key' => 'main',
                'building_key' => 'main_tower',
                'service_area_key' => 'tertiary_transfer_center',
                'facility_type' => 'academic_tertiary',
                'status' => 'active',
                'is_external' => false,
                'staffed_beds' => 500,
                'available_beds' => 18,
                'icu_available_beds' => 4,
                'ed_boarders' => 9,
                'transport_minutes' => 0,
                'accepts_transfers' => true,
                'capacity' => ['transfer_center' => true, 'pacu_pressure' => 'moderate'],
                'metadata' => ['source' => 'regional_fixture'],
                'capabilities' => [
                    ['key' => 'adult_transfer', 'type' => 'transfer', 'status' => 'available', 'score_weight' => 10],
                    ['key' => 'icu', 'type' => 'clinical', 'status' => 'available', 'score_weight' => 15],
                    ['key' => 'critical_care_transport', 'type' => 'transport', 'status' => 'available', 'score_weight' => 12],
                    ['key' => 'cardiology', 'type' => 'clinical', 'status' => 'available', 'score_weight' => 10],
                    ['key' => 'neurosurgery', 'type' => 'clinical', 'status' => 'available', 'score_weight' => 10],
                    ['key' => 'trauma', 'type' => 'clinical', 'status' => 'available', 'score_weight' => 10],
                ],
            ],
            [
                'facility_code' => 'north_community',
                'facility_name' => 'North Community Hospital',
                'organization_key' => 'zephyrus-network',
                'campus_key' => 'north',
                'building_key' => 'north_main',
                'service_area_key' => 'community_inpatient',
                'facility_type' => 'community_hospital',
                'status' => 'active',
                'is_external' => false,
                'staffed_beds' => 180,
                'available_beds' => 22,
                'icu_available_beds' => 1,
                'ed_boarders' => 4,
                'transport_minutes' => 18,
                'accepts_transfers' => true,
                'capacity' => ['transfer_center' => false, 'med_surg_capacity' => 'open'],
                'metadata' => ['source' => 'regional_fixture'],
                'capabilities' => [
                    ['key' => 'adult_transfer', 'type' => 'transfer', 'status' => 'available', 'score_weight' => 10],
                    ['key' => 'icu', 'type' => 'clinical', 'status' => 'limited', 'score_weight' => 6],
                    ['key' => 'cardiology', 'type' => 'clinical', 'status' => 'available', 'score_weight' => 8],
                ],
            ],
            [
                'facility_code' => 'west_rehab',
                'facility_name' => 'West Rehab and Stepdown',
                'organization_key' => 'zephyrus-network',
                'campus_key' => 'west',
                'building_key' => 'west_rehab_center',
                'service_area_key' => 'post_acute_stepdown',
                'facility_type' => 'post_acute_stepdown',
                'status' => 'active',
                'is_external' => false,
                'staffed_beds' => 96,
                'available_beds' => 16,
                'icu_available_beds' => 0,
                'ed_boarders' => 0,
                'transport_minutes' => 32,
                'accepts_transfers' => true,
                'capacity' => ['post_acute' => true, 'therapy_slots' => 8],
                'metadata' => ['source' => 'regional_fixture'],
                'capabilities' => [
                    ['key' => 'adult_transfer', 'type' => 'transfer', 'status' => 'available', 'score_weight' => 10],
                    ['key' => 'rehab', 'type' => 'post_acute', 'status' => 'available', 'score_weight' => 8],
                ],
            ],
            [
                'facility_code' => 'east_surgical',
                'facility_name' => 'East Surgical Specialty Hospital',
                'organization_key' => 'zephyrus-network',
                'campus_key' => 'east',
                'building_key' => 'east_surgical_pavilion',
                'service_area_key' => 'surgical_specialty',
                'facility_type' => 'surgical_specialty',
                'status' => 'active',
                'is_external' => false,
                'staffed_beds' => 140,
                'available_beds' => 7,
                'icu_available_beds' => 2,
                'ed_boarders' => 1,
                'transport_minutes' => 27,
                'accepts_transfers' => true,
                'capacity' => ['or_capacity' => 'limited', 'stepdown_capacity' => 'open'],
                'metadata' => ['source' => 'regional_fixture'],
                'capabilities' => [
                    ['key' => 'adult_transfer', 'type' => 'transfer', 'status' => 'available', 'score_weight' => 10],
                    ['key' => 'icu', 'type' => 'clinical', 'status' => 'available', 'score_weight' => 10],
                    ['key' => 'trauma', 'type' => 'clinical', 'status' => 'conditional', 'score_weight' => 6],
                ],
            ],
            [
                'facility_code' => 'south_partner',
                'facility_name' => 'South Partner Medical Center',
                'organization_key' => 'regional-partner',
                'campus_key' => 'south',
                'building_key' => 'south_tower',
                'service_area_key' => 'partner_transfer',
                'facility_type' => 'external_partner',
                'status' => 'active',
                'is_external' => true,
                'staffed_beds' => 220,
                'available_beds' => 13,
                'icu_available_beds' => 2,
                'ed_boarders' => 6,
                'transport_minutes' => 42,
                'accepts_transfers' => true,
                'capacity' => ['external_transfer_agreement' => true, 'payer_constraints' => 'verify_before_acceptance'],
                'metadata' => ['source' => 'regional_fixture', 'scope' => 'external_partner'],
                'capabilities' => [
                    ['key' => 'adult_transfer', 'type' => 'transfer', 'status' => 'available', 'score_weight' => 10],
                    ['key' => 'icu', 'type' => 'clinical', 'status' => 'available', 'score_weight' => 8],
                    ['key' => 'cardiology', 'type' => 'clinical', 'status' => 'available', 'score_weight' => 8],
                    ['key' => 'critical_care_transport', 'type' => 'transport', 'status' => 'conditional', 'score_weight' => 6],
                ],
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function modelVersionCatalog(): array
    {
        $facilityCodes = collect($this->networkCatalog())->pluck('facility_code')->values()->all();

        return [
            [
                'version_key' => 'phase8-network-v1',
                'label' => 'Approved regional network v1',
                'status' => 'approved',
                'approved_at' => now()->subHours(6),
                'assumptions' => [
                    'source' => 'seeded_demo_network',
                    'transfer_policy' => 'prefer highest safety-adjusted score',
                    'capacity_adjustments' => [],
                ],
                'facilities' => $facilityCodes,
            ],
            [
                'version_key' => 'critical-care-surge-v1',
                'label' => 'Critical care surge diversion model',
                'status' => 'approved',
                'approved_at' => now()->subHours(4),
                'assumptions' => [
                    'source' => 'phase8_surge_fixture',
                    'transfer_policy' => 'preserve tertiary ICU and redirect eligible transfers',
                    'capacity_adjustments' => [
                        'zephyrus_main' => ['available_beds' => -8, 'icu_available_beds' => -2, 'transport_minutes' => 0],
                        'north_community' => ['available_beds' => 2, 'icu_available_beds' => 0, 'transport_minutes' => 4],
                        'east_surgical' => ['available_beds' => 1, 'icu_available_beds' => 0, 'transport_minutes' => 3],
                        'south_partner' => ['available_beds' => 4, 'icu_available_beds' => 1, 'transport_minutes' => 5],
                    ],
                ],
                'facilities' => $facilityCodes,
            ],
            [
                'version_key' => 'transport-staffed-up-v1',
                'label' => 'Transport staffed-up route model',
                'status' => 'approved',
                'approved_at' => now()->subHours(2),
                'assumptions' => [
                    'source' => 'phase8_transport_fixture',
                    'transfer_policy' => 'reduce route delay with added regional transport capacity',
                    'capacity_adjustments' => [
                        'north_community' => ['available_beds' => 0, 'icu_available_beds' => 0, 'transport_minutes' => -6],
                        'west_rehab' => ['available_beds' => 3, 'icu_available_beds' => 0, 'transport_minutes' => -8],
                        'east_surgical' => ['available_beds' => 0, 'icu_available_beds' => 0, 'transport_minutes' => -5],
                        'south_partner' => ['available_beds' => 2, 'icu_available_beds' => 0, 'transport_minutes' => -10],
                    ],
                ],
                'facilities' => $facilityCodes,
            ],
        ];
    }

    /** @return array<mixed> */
    private function decodeJson(mixed $value, array $fallback): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $fallback;
    }
}
