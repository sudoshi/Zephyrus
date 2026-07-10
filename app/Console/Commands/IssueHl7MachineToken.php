<?php

namespace App\Console\Commands;

use App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class IssueHl7MachineToken extends Command
{
    protected $signature = 'integrations:issue-hl7-token
        {user : Active integration identity user ID}
        {--name=patient-flow-hl7v2 : Token label}
        {--expires=90 : Token lifetime in days}';

    protected $description = 'Issue a bounded Sanctum machine token for canonical Patient Flow HL7 v2 ADT ingestion.';

    public function handle(IntegrationConfigurationAuditService $audit): int
    {
        $user = User::query()->findOrFail((int) $this->argument('user'));
        if (! $user->is_active || ! in_array($user->role, ['integration', 'integration-machine'], true)) {
            $this->components->error('The token owner must be an active dedicated integration identity.');

            return self::FAILURE;
        }
        $days = (int) $this->option('expires');
        if ($days < 1 || $days > 365) {
            $this->components->error('Token lifetime must be between 1 and 365 days.');

            return self::FAILURE;
        }
        $name = trim((string) $this->option('name'));
        if ($name === '' || strlen($name) > 120) {
            $this->components->error('Token name must be 1-120 characters.');

            return self::FAILURE;
        }

        $expiresAt = now()->addDays($days);
        $token = $user->createToken($name, ['integration:patient-flow:ingest'], $expiresAt);
        $audit->record(null, 'issued', 'machine_credential', (int) $token->accessToken->getKey(), $name, [], [
            'userId' => (int) $user->getKey(),
            'ability' => 'integration:patient-flow:ingest',
            'expiresAtIso' => $expiresAt->toIso8601String(),
        ], (string) Str::uuid());

        $this->components->warn('Store this token immediately in the sending system secret manager; it will not be shown again.');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
