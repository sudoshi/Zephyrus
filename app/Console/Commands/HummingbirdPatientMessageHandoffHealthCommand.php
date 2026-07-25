<?php

namespace App\Console\Commands;

use App\Services\Patient\Messaging\PatientMessageHandoffHealthReporter;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class HummingbirdPatientMessageHandoffHealthCommand extends Command
{
    protected $signature = 'hummingbird:patient-message-handoff-health
        {--json : Emit only a machine-readable, content-free aggregate report}';

    protected $description = 'Report aggregate Hummingbird Patient staff-handoff health without disclosing patient or message data';

    public function handle(PatientMessageHandoffHealthReporter $reporter): int
    {
        try {
            $report = $reporter->report();
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('patient_message_handoff_health_unavailable');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->output->getOutput()->writeln(
                (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                OutputInterface::OUTPUT_RAW,
            );
        } else {
            $this->table(
                ['Activation', 'Status', 'Schema', 'Fresh heartbeat', 'Operator action'],
                [[
                    $report['activation_state'],
                    $report['status'],
                    $report['schema_ready'] ? 'ready' : 'missing',
                    $report['consumer']['heartbeat_fresh'] ? 'yes' : 'no',
                    $report['requires_operator_action'] ? 'required' : 'not required',
                ]],
            );
            $this->table(
                ['Ready', 'In flight', 'Expired lease', 'Retry due', 'Retry scheduled', 'Terminal', 'Unknown'],
                [[
                    $report['outbox']['pending_ready'],
                    $report['outbox']['in_flight'],
                    $report['outbox']['expired_claim_lease'],
                    $report['outbox']['retry_due'],
                    $report['outbox']['retry_scheduled'],
                    $report['outbox']['terminal_failure'],
                    $report['outbox']['unknown_state'],
                ]],
            );
        }

        return $report['status'] === 'critical' ? self::FAILURE : self::SUCCESS;
    }
}
