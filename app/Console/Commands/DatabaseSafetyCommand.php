<?php

namespace App\Console\Commands;

use App\Database\ProductionDatabaseReadOnlyGuard;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Output\OutputInterface;

class DatabaseSafetyCommand extends Command
{
    protected $signature = 'zephyrus:database-safety {--json : Emit a machine-readable safety report}';

    protected $description = 'Verify that non-production access to the production PostgreSQL database is read-only.';

    public function handle(DatabaseManager $databases, ProductionDatabaseReadOnlyGuard $guard): int
    {
        $connection = $databases->connection();
        $driver = $connection->getDriverName();
        $environment = app()->environment();
        $protectionRequired = $guard->shouldProtect($connection->getConfig(), $environment);

        $defaultReadOnly = null;
        $transactionReadOnly = null;

        if ($driver === 'pgsql') {
            $defaultReadOnly = $connection->scalar("SELECT current_setting('default_transaction_read_only')");
            $transactionReadOnly = $connection->scalar("SELECT current_setting('transaction_read_only')");
        }

        $safe = ! $protectionRequired
            || ($defaultReadOnly === 'on' && $transactionReadOnly === 'on');

        $report = [
            'environment' => $environment,
            'driver' => $driver,
            'productionProtectionRequired' => $protectionRequired,
            'defaultTransactionReadOnly' => $defaultReadOnly,
            'transactionReadOnly' => $transactionReadOnly,
            'safe' => $safe,
        ];

        if ($this->option('json')) {
            $this->output->getOutput()->writeln(
                (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                OutputInterface::OUTPUT_RAW,
            );
        } else {
            $this->table(
                ['Environment', 'Driver', 'Production guard', 'Session default', 'Transaction', 'Safe'],
                [[
                    $environment,
                    $driver,
                    $protectionRequired ? 'required' : 'not required',
                    $defaultReadOnly ?? 'n/a',
                    $transactionReadOnly ?? 'n/a',
                    $safe ? 'yes' : 'no',
                ]],
            );
        }

        if (! $safe) {
            $this->error('The production database connection is not read-only.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
