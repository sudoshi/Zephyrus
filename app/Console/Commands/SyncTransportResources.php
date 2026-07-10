<?php

namespace App\Console\Commands;

use App\Services\Transport\TransportLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SyncTransportResources extends Command
{
    protected $signature = 'transport:sync-resources {--dry-run : Validate configured resources without persisting them}';

    protected $description = 'Materialize the configured transport team and vendor capacity catalog';

    public function handle(TransportLifecycleService $lifecycle): int
    {
        $resources = array_values((array) config('transport.resources', []));
        $mobileCapabilities = array_values((array) config('transport.mobile_transporter_capabilities', []));
        $validator = Validator::make([
            'resources' => $resources,
            'mobile_capabilities' => $mobileCapabilities,
        ], [
            'resources' => ['required', 'array', 'min:1'],
            'resources.*.key' => ['required', 'string', 'max:160', 'distinct'],
            'resources.*.name' => ['required', 'string', 'max:160'],
            'resources.*.type' => ['required', Rule::in(['team', 'vendor'])],
            'resources.*.capacity' => ['required', 'integer', 'min:1'],
            'resources.*.capabilities' => ['required', 'array', 'min:1'],
            'resources.*.capabilities.*' => ['required', 'string', 'max:80'],
            'mobile_capabilities' => ['required', 'array', 'min:1'],
            'mobile_capabilities.*' => ['required', 'string', 'max:80', 'distinct'],
        ]);
        $validator->after(function ($validator) use ($resources): void {
            foreach ($resources as $index => $resource) {
                $capabilities = array_values((array) ($resource['capabilities'] ?? []));
                if (count($capabilities) !== count(array_unique($capabilities))) {
                    $validator->errors()->add("resources.{$index}.capabilities", 'A resource capability may be listed only once.');
                }
            }
        });
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->components->info(count($resources).' configured transport resources validated.');

            return self::SUCCESS;
        }

        $count = $lifecycle->syncConfiguredResources();
        $this->components->info("{$count} transport resources synchronized.");

        return self::SUCCESS;
    }
}
