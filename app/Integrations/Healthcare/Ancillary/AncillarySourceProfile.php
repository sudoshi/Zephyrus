<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;

final readonly class AncillarySourceProfile
{
    /**
     * @param  list<string>  $messageFamilies
     * @param  list<string>  $departments
     * @param  array<string, string>  $milestoneMap
     */
    public function __construct(
        public string $sourceKey,
        public string $systemClass,
        public array $messageFamilies,
        public array $departments,
        public array $milestoneMap,
        public ?string $dispenseChannel = null,
    ) {}

    public static function from(SourceMessage $message): self
    {
        $configuration = $message->metadata['ancillary_ingest'] ?? [];

        return new self(
            sourceKey: (string) ($message->metadata['source_key'] ?? 'unknown'),
            systemClass: strtolower((string) ($message->metadata['system_class'] ?? 'unknown')),
            messageFamilies: array_values(array_unique(array_map(
                fn (mixed $family): string => strtoupper(trim((string) $family)),
                is_array($configuration['message_families'] ?? null) ? $configuration['message_families'] : [],
            ))),
            departments: array_values(array_unique(array_map(
                fn (mixed $department): string => strtolower(trim((string) $department)),
                is_array($configuration['departments'] ?? null) ? $configuration['departments'] : [],
            ))),
            milestoneMap: array_map(
                fn (mixed $code): string => strtoupper(trim((string) $code)),
                array_change_key_case(
                    is_array($configuration['milestone_map'] ?? null) ? $configuration['milestone_map'] : [],
                    CASE_UPPER,
                ),
            ),
            dispenseChannel: is_string($configuration['dispense_channel'] ?? null)
                ? strtolower(trim($configuration['dispense_channel'])) ?: null
                : null,
        );
    }

    public function assertFamily(string $family): void
    {
        if (! in_array(strtoupper($family), $this->messageFamilies, true)) {
            throw new AncillaryIngestException(
                'source_message_mismatch',
                'The governed source is not bound to this ancillary message family.',
                context: ['source_key' => $this->sourceKey, 'message_family' => strtoupper($family)],
            );
        }
    }

    public function milestoneFor(string $family): string
    {
        $family = strtoupper($family);
        $this->assertFamily($family);
        $code = $this->milestoneMap[$family] ?? $this->defaultMilestone($family);
        if (! is_string($code) || ! in_array($code, AncillaryEventVocabulary::codes(), true)) {
            throw new AncillaryIngestException(
                'source_mapping_missing',
                'The governed source has no valid ancillary milestone mapping for this family.',
                context: ['source_key' => $this->sourceKey, 'message_family' => $family],
            );
        }

        $department = AncillaryEventVocabulary::departmentFor($code);
        if ($this->departments !== [] && ! in_array($department, $this->departments, true)) {
            throw new AncillaryIngestException(
                'source_message_mismatch',
                'The governed source milestone mapping is outside its approved department scope.',
                context: ['source_key' => $this->sourceKey, 'message_family' => $family],
            );
        }

        return $code;
    }

    private function defaultMilestone(string $family): ?string
    {
        return match ($family) {
            'ORM', 'OMI' => match ($this->systemClass) {
                'radiology', 'ris', 'pacs' => 'RAD_ORDERED',
                'lis', 'lab_middleware' => 'LAB_ORDERED',
                default => null,
            },
            'OML' => 'LAB_ORDERED',
            'ORU' => match ($this->systemClass) {
                'radiology', 'radiology_reporting', 'ris', 'pacs' => 'RAD_FINAL',
                'lis', 'lab_middleware' => 'LAB_RESULTED',
                'ap_lis_blood_bank' => 'AP_SIGNED_OUT',
                default => null,
            },
            'SIU' => 'RAD_SCHEDULED',
            'RDE' => 'RX_ORDERED',
            'RDS' => 'RX_DISPENSED',
            default => null,
        };
    }
}
