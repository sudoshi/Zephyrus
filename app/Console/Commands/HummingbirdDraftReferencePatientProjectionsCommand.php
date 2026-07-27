<?php

namespace App\Console\Commands;

use App\Services\Patient\Demo\HummingbirdPatientReferenceProjectionDraftProvisioner;
use Illuminate\Console\Command;
use Throwable;

class HummingbirdDraftReferencePatientProjectionsCommand extends Command
{
    protected $signature = 'hummingbird:draft-reference-patient-projections
        {--patient-ref=demo-hummingbird-reference-inpatient : Existing command-owned demo-/sim- operational pseudonym}
        {--encounter-id= : Optional exact prod.encounters encounter_id}
        {--commit : Persist six synthetic draft-only projections; omission is a dry run}
        {--confirm-draft-only= : Required exact acknowledgement when --commit is supplied}
        {--json : Emit machine-readable, content-free JSON}';

    protected $description = 'Preview or create non-visible draft projections for the pending Hummingbird reference patient';

    public function handle(HummingbirdPatientReferenceProjectionDraftProvisioner $provisioner): int
    {
        if ($this->option('commit')
            && ! hash_equals(
                HummingbirdPatientReferenceProjectionDraftProvisioner::CONFIRMATION,
                (string) $this->option('confirm-draft-only'),
            )) {
            $this->error(
                '--commit requires --confirm-draft-only='
                .HummingbirdPatientReferenceProjectionDraftProvisioner::CONFIRMATION,
            );

            return self::INVALID;
        }

        $encounterId = $this->option('encounter-id');
        if ($encounterId !== null && $encounterId !== '') {
            $encounterId = filter_var($encounterId, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($encounterId === false) {
                $this->error('--encounter-id must be a positive integer when supplied.');

                return self::INVALID;
            }
        } else {
            $encounterId = null;
        }

        try {
            $result = $this->option('commit')
                ? $provisioner->provision((string) $this->option('patient-ref'), $encounterId)
                : $provisioner->preview((string) $this->option('patient-ref'), $encounterId);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['committed', $result['committed'] ? 'true' : 'false'],
            ['action', (string) $result['action']],
            ['source.encounter_id', (string) $result['source']['encounter_id']],
            ['principal.status', (string) $result['principal']['status']],
            ['principal.is_active', $result['principal']['is_active'] ? 'true' : 'false'],
            ['access_grant.status', (string) $result['access_grant']['status']],
            ['policy.status', (string) $result['policy']['status']],
            ['projections.count', (string) $result['projections']['count']],
            ['projections.created', (string) $result['projections']['created']],
            ['projections.replayed', (string) $result['projections']['replayed']],
            ['projections.release_state', (string) $result['projections']['release_state']],
            ['projections.patient_visible', $result['projections']['patient_visible'] ? 'true' : 'false'],
            ['projections.content_emitted', $result['projections']['content_emitted'] ? 'true' : 'false'],
            ['projections.identifiers_emitted', $result['projections']['identifiers_emitted'] ? 'true' : 'false'],
        ]);
        $this->newLine();
        $this->info($result['committed']
            ? 'Synthetic reference projections committed as non-visible drafts.'
            : 'Dry run only; no projection, cursor, policy, activation, or release was created.');

        return self::SUCCESS;
    }
}
