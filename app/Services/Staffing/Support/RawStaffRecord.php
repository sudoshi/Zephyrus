<?php

namespace App\Services\Staffing\Support;

/**
 * Phase 7: a normalized staff record streamed by a StaffingConnector — the only
 * staffing-specific output a connector produces. The untouched source row is kept
 * in `raw` for evidence/audit. Match fields (cost_center/department/specialty/
 * job_code/job_title/home_unit) feed the ServiceLineRoleResolver rule engine.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§4.1)
 */
final class RawStaffRecord
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $sourceSystem,
        public readonly string $externalId,
        public readonly ?string $displayName = null,
        public readonly ?string $email = null,
        public readonly ?string $npi = null,
        public readonly ?string $licenseNo = null,
        public readonly ?string $employeeType = null,
        public readonly ?string $employmentStatus = null,
        public readonly ?string $jobCode = null,
        public readonly ?string $jobTitle = null,
        public readonly ?string $specialty = null,
        public readonly ?string $department = null,
        public readonly ?string $costCenter = null,
        public readonly ?string $homeUnit = null,
        public readonly ?float $fte = null,
        public readonly ?string $termDate = null,
        public readonly array $raw = [],
    ) {}

    /**
     * Build from a canonical-keyed associative array (connectors normalize into this
     * shape after applying their field mapping). Unknown keys are ignored.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $sourceSystem, array $data): self
    {
        $str = static function (mixed $v): ?string {
            if ($v === null) {
                return null;
            }
            $v = trim((string) $v);

            return $v === '' ? null : $v;
        };

        return new self(
            sourceSystem: $sourceSystem,
            externalId: (string) ($data['external_id'] ?? ''),
            displayName: $str($data['display_name'] ?? null),
            email: $str($data['email'] ?? null) !== null ? strtolower($str($data['email'])) : null,
            npi: $str($data['npi'] ?? null),
            licenseNo: $str($data['license_no'] ?? null),
            employeeType: $str($data['employee_type'] ?? null),
            employmentStatus: $str($data['employment_status'] ?? null),
            jobCode: $str($data['job_code'] ?? null),
            jobTitle: $str($data['job_title'] ?? null),
            specialty: $str($data['specialty'] ?? null),
            department: $str($data['department'] ?? null),
            costCenter: $str($data['cost_center'] ?? null),
            homeUnit: $str($data['home_unit'] ?? null),
            fte: isset($data['fte']) && $data['fte'] !== '' && $data['fte'] !== null ? (float) $data['fte'] : null,
            termDate: $str($data['term_date'] ?? null),
            raw: is_array($data['raw'] ?? null) ? $data['raw'] : $data,
        );
    }

    /**
     * Serialize back to a canonical-keyed array (the inverse of fromArray, preserving
     * `raw`). Lets the wizard persist a staged record and rebuild it on a later request
     * via RawStaffRecord::fromArray($sourceSystem, $this->toArray()).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'display_name' => $this->displayName,
            'email' => $this->email,
            'npi' => $this->npi,
            'license_no' => $this->licenseNo,
            'employee_type' => $this->employeeType,
            'employment_status' => $this->employmentStatus,
            'job_code' => $this->jobCode,
            'job_title' => $this->jobTitle,
            'specialty' => $this->specialty,
            'department' => $this->department,
            'cost_center' => $this->costCenter,
            'home_unit' => $this->homeUnit,
            'fte' => $this->fte,
            'term_date' => $this->termDate,
            'raw' => $this->raw,
        ];
    }

    /**
     * Stable dedupe key: source_system + external_id.
     */
    public function staffKey(): string
    {
        return $this->sourceSystem.':'.$this->externalId;
    }

    /**
     * Read a resolver match field by its canonical name. Null when absent.
     */
    public function matchField(string $field): ?string
    {
        return match ($field) {
            'cost_center' => $this->costCenter,
            'department' => $this->department,
            'specialty' => $this->specialty,
            'job_code' => $this->jobCode,
            'job_title' => $this->jobTitle,
            'home_unit' => $this->homeUnit,
            default => null,
        };
    }

    public function isTerminated(): bool
    {
        return $this->employmentStatus !== null
            && in_array(strtolower($this->employmentStatus), ['terminated', 'inactive', 'separated'], true);
    }
}
