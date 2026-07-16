<?php

namespace App\Console\Commands;

use App\Integrations\Healthcare\Services\OperationalIntegrationConfigurator;
use Illuminate\Console\Command;

class ConfigureHl7AdtSource extends Command
{
    protected $signature = 'integrations:configure-hl7-adt-source
        {source-key : Stable lowercase source key supplied in X-Integration-Source}
        {--name= : Operator-facing source name}
        {--vendor=Epic : EHR or interface-engine vendor}
        {--facility= : Canonical active facility key}
        {--environment=sandbox : sandbox, testing, or production}
        {--contract-status=unknown : Contract governance status}
        {--baa-status=unknown : BAA governance status}
        {--go-live-status=not_started : Deployment lifecycle status}
        {--phi-allowed : Confirm this source is approved to carry PHI}
        {--activate : Activate machine ingress after all production governance checks pass}';

    protected $description = 'Configure a governed HL7 v2 ADT source for the canonical Patient Flow machine ingress.';

    public function handle(OperationalIntegrationConfigurator $configurator): int
    {
        $sourceKey = (string) $this->argument('source-key');
        $result = $configurator->configureHl7Source([
            'source_key' => $sourceKey,
            'source_name' => $this->option('name') ?: $sourceKey,
            'vendor' => (string) $this->option('vendor'),
            'facility_key' => (string) $this->option('facility'),
            'environment' => (string) $this->option('environment'),
            'contract_status' => (string) $this->option('contract-status'),
            'baa_status' => (string) $this->option('baa-status'),
            'go_live_status' => (string) $this->option('go-live-status'),
            'phi_allowed' => (bool) $this->option('phi-allowed'),
            'activate' => (bool) $this->option('activate'),
        ]);

        $this->components->info("HL7 source {$result['sourceKey']} is {$result['activeStatus']}.");
        if ($result['activeStatus'] !== 'active') {
            $this->components->warn('Ingress remains disabled until production governance and machine-token activation are complete.');
        }

        return self::SUCCESS;
    }
}
