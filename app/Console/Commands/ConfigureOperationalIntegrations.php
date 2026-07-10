<?php

namespace App\Console\Commands;

use App\Integrations\Healthcare\Services\OperationalIntegrationConfigurator;
use Illuminate\Console\Command;

class ConfigureOperationalIntegrations extends Command
{
    protected $signature = 'integrations:configure-operational-sources
        {--epic-client-id= : Registered Epic non-production backend client ID}
        {--epic-private-key-ref= : Approved private-key reference; never the key material}
        {--epic-key-id= : Optional public key identifier used in the client assertion}
        {--activate-epic : Activate scheduled sandbox polling after credentials are configured}';

    protected $description = 'Idempotently configure the governed Epic FHIR sandbox and Patient Flow HL7 ADT boundary.';

    public function handle(OperationalIntegrationConfigurator $configurator): int
    {
        $epic = $configurator->configureEpicSandbox(
            clientId: $this->option('epic-client-id'),
            privateKeyRef: $this->option('epic-private-key-ref'),
            keyId: $this->option('epic-key-id'),
            activate: (bool) $this->option('activate-epic'),
        );
        $hl7 = $configurator->configureHl7Boundary();

        $this->components->info('Epic FHIR source: '.$epic['status'].'; SMART credential: '.$epic['credentialStatus'].'.');
        $this->components->info('HL7 v2 boundary: '.$hl7['status'].' at '.$hl7['route'].'.');

        if ($epic['credentialStatus'] !== 'configured') {
            $this->components->warn('Epic data polling remains activation-gated until a registered client ID and private-key reference are supplied.');
        }

        return self::SUCCESS;
    }
}
