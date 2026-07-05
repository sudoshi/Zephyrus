<?php

namespace App\Services\Staffing;

use App\Services\Deployment\ServiceLineNormalizer;
use App\Services\Staffing\Support\RawStaffRecord;
use App\Services\Staffing\Support\ResolvedAssignment;

/**
 * Phase 7 resolution engine (§5). Layered precedence, first layer wins, each layer
 * stamps confidence + evidence:
 *
 *   1. Explicit override   per-person manual pin                    confidence 1.00
 *   2. Deterministic rule  staff_mapping_rules (priority asc)       confidence = rule.confidence (~0.9)
 *   3. Heuristic fallback  title/specialty normalization            confidence 0.50–0.72
 *   4. Unresolved          no match -> [] (bucketed 'unmatched')
 *
 * Pure and deterministic given (RawStaffRecord, rules snapshot, overrides,
 * regulated-role set). Every proposed service line is canonicalized and validated
 * against the registry, so the resolver can never emit an FK-invalid code — an
 * unknown code falls through to the next layer / unmatched.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§5)
 */
class ServiceLineRoleResolver
{
    /**
     * Heuristic patterns (specific -> generic). Each: needle => [role_code, service_line_code, confidence].
     * The service_line is validated before use; a bad guess falls through.
     *
     * @var array<string, array{0:string,1:string,2:float}>
     */
    private const HEURISTICS = [
        // Physicians (specialty-driven)
        'hospitalist' => ['hospitalist', 'hospital_medicine', 0.72],
        'intensivist' => ['intensivist', 'critical_care', 0.72],
        'critical care' => ['intensivist', 'critical_care', 0.70],
        'trauma' => ['trauma_surgeon', 'trauma_acute_care_surgery', 0.60],
        'transplant' => ['transplant_surgeon', 'transplant', 0.60],
        'neurosurg' => ['neurosurgeon', 'neurosciences', 0.62],
        'neonatolog' => ['neonatologist', 'neonatology', 0.62],
        'nicu' => ['neonatologist', 'neonatology', 0.60],
        'maternal' => ['maternal_fetal_medicine', 'womens_health', 0.62],
        'anesthesiolog' => ['anesthesiologist', 'perioperative', 0.65],
        'cardiolog' => ['cardiologist', 'cardiovascular', 0.65],
        'emergency medicine' => ['emergency_physician', 'emergency', 0.72],
        'emergency physician' => ['emergency_physician', 'emergency', 0.72],
        // Advanced practice
        'nurse practitioner' => ['nurse_practitioner', 'hospital_medicine', 0.60],
        'crnp' => ['nurse_practitioner', 'hospital_medicine', 0.60],
        'physician assistant' => ['physician_assistant', 'hospital_medicine', 0.60],
        'pa-c' => ['physician_assistant', 'hospital_medicine', 0.60],
        // Nursing (specific -> generic)
        'charge nurse' => ['charge_nurse', 'adult_med_surg', 0.55],
        'nurse manager' => ['nurse_manager', 'adult_med_surg', 0.58],
        'house supervisor' => ['house_supervisor', 'hospital_medicine', 0.58],
        'nursing supervisor' => ['house_supervisor', 'hospital_medicine', 0.56],
        'clinical coordinator' => ['clinical_coordinator', 'adult_med_surg', 0.52],
        'staff nurse' => ['staff_nurse', 'adult_med_surg', 0.52],
        'registered nurse' => ['staff_nurse', 'adult_med_surg', 0.50],
        // Allied health
        'respiratory therap' => ['respiratory_therapist', 'pulmonary_respiratory', 0.65],
        'pharmacist' => ['pharmacist', 'pharmacy_medication', 0.70],
        'physical therap' => ['physical_therapist', 'rehabilitation', 0.65],
        'occupational therap' => ['occupational_therapist', 'rehabilitation', 0.65],
        'dietitian' => ['dietitian', 'hospital_medicine', 0.55],
        // Ancillary / support
        'case manager' => ['case_manager', 'hospital_medicine', 0.60],
        'social work' => ['social_worker', 'hospital_medicine', 0.58],
        'care coordinator' => ['care_coordinator', 'hospital_medicine', 0.55],
        'patient transport' => ['transport_tech', 'logistics_support', 0.65],
        'transporter' => ['transport_tech', 'logistics_support', 0.62],
        'environmental service' => ['environmental_services', 'logistics_support', 0.60],
        'housekeep' => ['environmental_services', 'logistics_support', 0.58],
        'unit clerk' => ['unit_clerk', 'hospital_medicine', 0.52],
        'bed manager' => ['bed_manager', 'logistics_support', 0.60],
        'patient flow' => ['bed_manager', 'logistics_support', 0.58],
    ];

    public function __construct(private readonly ServiceLineNormalizer $normalizer) {}

    /**
     * Resolve a record to zero-or-more memberships.
     *
     * @param  iterable<object|array<string,mixed>>  $rules  staff_mapping_rules snapshot (priority asc)
     * @param  array<string, list<array<string,mixed>>>  $overrides  staff_key => pinned assignments
     * @param  array<string, bool>  $regulatedRoles  role_code => is_regulated
     * @return list<ResolvedAssignment>
     */
    public function resolve(RawStaffRecord $record, iterable $rules, array $overrides = [], array $regulatedRoles = []): array
    {
        $resolved = $this->fromOverrides($record, $overrides)
            ?: $this->fromRules($record, $rules)
            ?: $this->fromHeuristics($record);

        return $this->stampRegulated($resolved, $regulatedRoles);
    }

    /**
     * @param  array<string, list<array<string,mixed>>>  $overrides
     * @return list<ResolvedAssignment>
     */
    private function fromOverrides(RawStaffRecord $record, array $overrides): array
    {
        $pins = $overrides[$record->staffKey()] ?? [];
        $out = [];

        foreach (array_values($pins) as $i => $pin) {
            $serviceLine = $this->validServiceLine($pin['service_line_code'] ?? null);
            if ($serviceLine === null || empty($pin['role_code'])) {
                continue;
            }

            $out[] = new ResolvedAssignment(
                serviceLineCode: $serviceLine,
                roleCode: (string) $pin['role_code'],
                confidence: 1.00,
                resolutionSource: 'override',
                evidence: ['source' => 'override', 'source_field' => 'manual_pin'],
                unitHint: $pin['unit_hint'] ?? null,
                programCode: $pin['program_code'] ?? null,
                primary: $i === 0,
            );
        }

        return $out;
    }

    /**
     * @param  iterable<object|array<string,mixed>>  $rules
     * @return list<ResolvedAssignment>
     */
    private function fromRules(RawStaffRecord $record, iterable $rules): array
    {
        $out = [];

        foreach ($rules as $rule) {
            $field = (string) $this->ruleVal($rule, 'match_field');
            $value = $record->matchField($field);
            if ($value === null || ! $this->matches($value, $rule)) {
                continue;
            }

            $serviceLine = $this->validServiceLine($this->ruleVal($rule, 'target_service_line_code'));
            $roleCode = $this->ruleVal($rule, 'target_role_code');
            if ($serviceLine === null || empty($roleCode)) {
                continue;
            }

            $out[] = new ResolvedAssignment(
                serviceLineCode: $serviceLine,
                roleCode: (string) $roleCode,
                confidence: (float) ($this->ruleVal($rule, 'confidence') ?? 0.90),
                resolutionSource: 'rule',
                evidence: [
                    'source' => 'rule',
                    'rule_id' => $this->ruleVal($rule, 'staff_mapping_rule_id'),
                    'source_field' => $field,
                    'matched_value' => $value,
                ],
                unitHint: $this->ruleVal($rule, 'target_unit_hint'),
                primary: $out === [],
            );
        }

        return $out;
    }

    /**
     * @return list<ResolvedAssignment>
     */
    private function fromHeuristics(RawStaffRecord $record): array
    {
        $haystack = strtolower(trim(
            ($record->jobTitle ?? '').' '.($record->specialty ?? '').' '.($record->department ?? '')
        ));

        if ($haystack === '') {
            return [];
        }

        foreach (self::HEURISTICS as $needle => [$roleCode, $serviceLine, $confidence]) {
            if (! str_contains($haystack, $needle)) {
                continue;
            }

            $serviceLine = $this->validServiceLine($serviceLine);
            if ($serviceLine === null) {
                continue;
            }

            return [new ResolvedAssignment(
                serviceLineCode: $serviceLine,
                roleCode: $roleCode,
                confidence: $confidence,
                resolutionSource: 'heuristic',
                evidence: [
                    'source' => 'heuristic',
                    'matched_needle' => $needle,
                    'source_field' => $record->jobTitle !== null ? 'job_title' : 'specialty',
                    'matched_value' => $record->jobTitle ?? $record->specialty,
                ],
                unitHint: $record->homeUnit,
                primary: true,
            )];
        }

        return [];
    }

    /**
     * @param  list<ResolvedAssignment>  $resolved
     * @param  array<string, bool>  $regulatedRoles
     * @return list<ResolvedAssignment>
     */
    private function stampRegulated(array $resolved, array $regulatedRoles): array
    {
        return array_map(
            fn (ResolvedAssignment $a): ResolvedAssignment => ($regulatedRoles[$a->roleCode] ?? false)
                ? $a->with(['regulated' => true])
                : $a,
            $resolved
        );
    }

    private function validServiceLine(mixed $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $canonical = $this->normalizer->canonical((string) $code);

        return $this->normalizer->isKnown($canonical) ? $canonical : null;
    }

    private function matches(string $value, object|array $rule): bool
    {
        $operator = (string) ($this->ruleVal($rule, 'match_operator') ?: 'equals');
        $target = (string) $this->ruleVal($rule, 'match_value');
        $v = strtolower(trim($value));
        $t = strtolower(trim($target));

        return match ($operator) {
            'prefix' => $t !== '' && str_starts_with($v, $t),
            'contains' => $t !== '' && str_contains($v, $t),
            'regex' => @preg_match('/'.$target.'/i', $value) === 1,
            default => $v === $t,
        };
    }

    private function ruleVal(object|array $rule, string $key): mixed
    {
        if (is_array($rule)) {
            return $rule[$key] ?? null;
        }

        return $rule->{$key} ?? null;
    }
}
