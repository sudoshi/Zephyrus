<?php

namespace App\Console\Commands;

use App\Services\Patient\Demo\HummingbirdPatientReferenceIdentityProvisioner;
use Illuminate\Console\Command;
use Throwable;

class HummingbirdProvisionReferencePatientIdentityCommand extends Command
{
    protected $signature = 'hummingbird:provision-reference-patient-identity
        {--patient-ref=demo-hummingbird-reference-inpatient : Existing lowercase demo-/sim- operational pseudonym}
        {--encounter-id= : Optional exact prod.encounters encounter_id}
        {--commit : Persist the identity, grant, and one challenge per patient platform}
        {--show-secrets : With --commit only, emit one-time enrollment material}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Dry-run or idempotently bind the command-owned synthetic inpatient to Hummingbird Patient enrollment';

    public function handle(HummingbirdPatientReferenceIdentityProvisioner $provisioner): int
    {
        if ($this->option('show-secrets') && ! $this->option('commit')) {
            $this->error('--show-secrets requires --commit because enrollment material is generated only once.');

            return self::INVALID;
        }

        $encounterId = $this->option('encounter-id');
        if ($encounterId !== null && $encounterId !== '') {
            $encounterId = filter_var($encounterId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
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

        $showSecrets = (bool) $this->option('show-secrets');
        $output = $this->outputResult($result, $showSecrets);
        if ($showSecrets) {
            $output['security_warning'] = 'One-time enrollment material: deliver through an approved secure channel and do not retain command output.';
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], $this->tableRows($output));
        $this->newLine();
        if ($showSecrets) {
            $this->warn($output['security_warning']);
        } elseif ($result['committed']) {
            $this->info('Committed with enrollment secrets redacted. A later --commit --show-secrets run will securely replace the command-owned issued challenges.');
        } else {
            $this->info('Dry run only; pass --commit to persist. Enrollment material has not been generated.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function outputResult(array $result, bool $showSecrets): array
    {
        foreach (['ios', 'android'] as $platform) {
            if (! isset($result['enrollment'][$platform])) {
                continue;
            }

            $result['enrollment'][$platform]['challenge_token'] = $showSecrets
                ? $result['enrollment'][$platform]['challenge_token']
                : '[REDACTED]';
            $result['enrollment'][$platform]['verification_code'] = $showSecrets
                ? $result['enrollment'][$platform]['verification_code']
                : '[REDACTED]';
        }
        $result['secrets_emitted'] = $showSecrets;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<int, array{string, string}>
     */
    private function tableRows(array $output): array
    {
        $rows = [
            ['committed', $output['committed'] ? 'true' : 'false'],
            ['source.encounter_id', (string) $output['source']['encounter_id']],
            ['principal.uuid', (string) ($output['principal']['uuid'] ?? 'planned')],
            ['principal.status', (string) $output['principal']['status']],
            ['identity_link.uuid', (string) ($output['identity_link']['uuid'] ?? 'planned')],
            ['access_grant.uuid', (string) ($output['access_grant']['uuid'] ?? 'planned')],
            ['access_grant.encounter_uuid', (string) ($output['access_grant']['encounter_uuid'] ?? 'planned')],
        ];

        foreach (['ios', 'android'] as $platform) {
            $enrollment = $output['enrollment'][$platform];
            $rows[] = ["{$platform}.challenge_uuid", (string) ($enrollment['challenge_uuid'] ?? 'planned')];
            $rows[] = ["{$platform}.expires_at", (string) ($enrollment['expires_at'] ?? 'planned')];
            $rows[] = ["{$platform}.challenge_token", (string) $enrollment['challenge_token']];
            $rows[] = ["{$platform}.verification_code", (string) $enrollment['verification_code']];
        }

        return $rows;
    }
}
