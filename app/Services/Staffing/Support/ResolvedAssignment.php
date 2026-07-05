<?php

namespace App\Services\Staffing\Support;

/**
 * Phase 7: one resolved membership produced by ServiceLineRoleResolver — a
 * service line + role (+ optional unit hint / program), with the confidence,
 * resolution_source, and evidence that justify it. Multi-membership is normal;
 * a person can yield several ResolvedAssignments.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§5)
 */
final class ResolvedAssignment
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public readonly string $serviceLineCode,
        public readonly string $roleCode,
        public readonly float $confidence,
        public readonly string $resolutionSource,
        public readonly array $evidence = [],
        public readonly ?string $unitHint = null,
        public readonly ?string $programCode = null,
        public readonly bool $primary = false,
        public readonly bool $regulated = false,
    ) {}

    /**
     * Rebuild from a toArray() payload (the wizard persists proposals in the run's
     * staged jsonb and reconstructs them on commit). Tolerant of partial data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            serviceLineCode: (string) ($data['service_line_code'] ?? ''),
            roleCode: (string) ($data['role_code'] ?? ''),
            confidence: (float) ($data['confidence'] ?? 0.0),
            resolutionSource: (string) ($data['resolution_source'] ?? 'imported'),
            evidence: is_array($data['evidence'] ?? null) ? $data['evidence'] : [],
            unitHint: $data['unit_hint'] ?? null,
            programCode: $data['program_code'] ?? null,
            primary: (bool) ($data['primary'] ?? false),
            regulated: (bool) ($data['regulated'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            serviceLineCode: $overrides['serviceLineCode'] ?? $this->serviceLineCode,
            roleCode: $overrides['roleCode'] ?? $this->roleCode,
            confidence: $overrides['confidence'] ?? $this->confidence,
            resolutionSource: $overrides['resolutionSource'] ?? $this->resolutionSource,
            evidence: $overrides['evidence'] ?? $this->evidence,
            unitHint: $overrides['unitHint'] ?? $this->unitHint,
            programCode: $overrides['programCode'] ?? $this->programCode,
            primary: $overrides['primary'] ?? $this->primary,
            regulated: $overrides['regulated'] ?? $this->regulated,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'service_line_code' => $this->serviceLineCode,
            'role_code' => $this->roleCode,
            'confidence' => $this->confidence,
            'resolution_source' => $this->resolutionSource,
            'evidence' => $this->evidence,
            'unit_hint' => $this->unitHint,
            'program_code' => $this->programCode,
            'primary' => $this->primary,
            'regulated' => $this->regulated,
        ];
    }
}
