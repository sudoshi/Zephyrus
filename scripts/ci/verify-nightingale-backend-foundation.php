#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Nightingale\EncounterAccess\NightingaleEncounterAccessPreconditionGate;
use App\Nightingale\EncounterAccess\NightingalePreconditionDisposition;
use App\Nightingale\Identity\NightingaleIdentityBoundary;
use App\Nightingale\Identity\NightingaleIdentityState;
use App\Nightingale\Identity\UnconfiguredNightingaleIdentityBoundary;
use App\Nightingale\Inpatient\NightingaleInpatientContextSource;
use App\Nightingale\Inpatient\NightingaleInpatientSourceState;
use App\Nightingale\Inpatient\UnconfiguredNightingaleInpatientContextSource;

$arguments = array_slice($argv, 1);
$selfTest = in_array('--self-test', $arguments, true);
$unknownOptions = array_values(array_filter(
    $arguments,
    fn (string $argument): bool => str_starts_with($argument, '--') && $argument !== '--self-test',
));
$positional = array_values(array_filter(
    $arguments,
    fn (string $argument): bool => ! str_starts_with($argument, '--'),
));

$fail = static function (string $message): never {
    fwrite(STDERR, "Nightingale backend foundation violation: {$message}\n");
    exit(1);
};

if ($unknownOptions !== []) {
    $fail('unknown option(s): '.implode(', ', $unknownOptions));
}
if (count($positional) > 1) {
    $fail('expected at most one repository-root argument');
}

$repositoryRoot = realpath($positional[0] ?? '.') ?: null;
if ($repositoryRoot === null) {
    $fail('repository root does not exist');
}

$requiredPhpFiles = [
    'app/Nightingale/Identity/NightingaleIdentityState.php',
    'app/Nightingale/Identity/NightingaleIdentityBoundary.php',
    'app/Nightingale/Identity/UnconfiguredNightingaleIdentityBoundary.php',
    'app/Nightingale/Inpatient/NightingaleInpatientSourceState.php',
    'app/Nightingale/Inpatient/NightingaleInpatientContextSource.php',
    'app/Nightingale/Inpatient/UnconfiguredNightingaleInpatientContextSource.php',
    'app/Nightingale/EncounterAccess/NightingalePreconditionDisposition.php',
    'app/Nightingale/EncounterAccess/NightingaleEncounterAccessPreconditionGate.php',
];
foreach ($requiredPhpFiles as $relativePath) {
    $path = "{$repositoryRoot}/{$relativePath}";
    if (! is_file($path)) {
        $fail("missing {$relativePath}");
    }
    require_once $path;
}

$configPath = "{$repositoryRoot}/config/nightingale.php";
if (! is_file($configPath)) {
    $fail('missing config/nightingale.php');
}
$configRaw = file_get_contents($configPath);
$config = require $configPath;
$foundation = json_decode(
    file_get_contents("{$repositoryRoot}/docs/nightingale/api-contract/nightingale-foundation.v0.json"),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$candidate = json_decode(
    file_get_contents("{$repositoryRoot}/docs/nightingale/api-contract/candidates/encounter-access/v0/candidate.json"),
    true,
    flags: JSON_THROW_ON_ERROR,
);

$inspect = static function (array $subject, string $raw, array $foundationDocument, array $candidateDocument): array {
    $violations = [];
    $assert = static function (bool $condition, string $message) use (&$violations): void {
        if (! $condition) {
            $violations[] = $message;
        }
    };

    $sameKeys = static function (array $actual, array $expected): bool {
        $actualKeys = array_keys($actual);
        sort($actualKeys);
        sort($expected);

        return $actualKeys === $expected;
    };

    $assert(
        $sameKeys($subject, [
            'status',
            'route_namespace',
            'routes_registered',
            'network_clients_permitted',
            'identity',
            'inpatient_context',
            'patient_disclosure_enabled',
            'patient_mutation_enabled',
            'production_enabled',
        ]),
        'top-level configuration fields changed',
    );
    $assert(
        $sameKeys($subject['identity'] ?? [], [
            'enabled',
            'provider',
            'legacy_patient_realm_reuse_permitted',
        ]),
        'identity configuration fields changed',
    );
    $assert(
        $sameKeys($subject['inpatient_context'] ?? [], [
            'enabled',
            'candidate_path',
            'operation_id',
            'source_adapter',
            'production_query_permitted',
        ]),
        'inpatient-context configuration fields changed',
    );
    $assert(($subject['status'] ?? null) === 'foundation', 'status must remain foundation');
    $assert(($subject['route_namespace'] ?? null) === '/api/nightingale/v1', 'route namespace changed');
    $assert(($subject['routes_registered'] ?? null) === false, 'routes_registered must remain false');
    $assert(($subject['network_clients_permitted'] ?? null) === false, 'network clients were permitted');
    $assert(($subject['identity']['enabled'] ?? null) === false, 'identity was enabled');
    $assert(
        array_key_exists('provider', $subject['identity'] ?? [])
            && $subject['identity']['provider'] === null,
        'identity provider was configured',
    );
    $assert(
        ($subject['identity']['legacy_patient_realm_reuse_permitted'] ?? null) === false,
        'legacy patient realm reuse was permitted',
    );
    $assert(($subject['inpatient_context']['enabled'] ?? null) === false, 'inpatient context was enabled');
    $assert(
        ($subject['inpatient_context']['candidate_path'] ?? null) === '/inpatient-contexts',
        'candidate path changed',
    );
    $assert(
        ($subject['inpatient_context']['operation_id'] ?? null) === 'listNightingaleInpatientContexts',
        'candidate operation id changed',
    );
    $assert(
        array_key_exists('source_adapter', $subject['inpatient_context'] ?? [])
            && $subject['inpatient_context']['source_adapter'] === null,
        'inpatient source adapter was configured',
    );
    $assert(
        ($subject['inpatient_context']['production_query_permitted'] ?? null) === false,
        'production source query was permitted',
    );
    foreach (['patient_disclosure_enabled', 'patient_mutation_enabled', 'production_enabled'] as $field) {
        $assert(($subject[$field] ?? null) === false, "{$field} must remain false");
    }
    foreach (['env(', 'getenv(', '$_ENV', '$_SERVER'] as $activationHook) {
        $assert(
            ! str_contains($raw, $activationHook),
            "configuration activation hook is prohibited in the foundation: {$activationHook}",
        );
    }

    $contract = $foundationDocument['x-nightingale-contract'] ?? [];
    $activation = $foundationDocument['x-nightingale-activation'] ?? [];
    $operation = $candidateDocument['operation'] ?? [];
    $assert(
        ($contract['route_namespace'] ?? null) === $subject['route_namespace'],
        'foundation route namespace does not match config',
    );
    $assert(($contract['route_namespace_reserved'] ?? null) === true, 'foundation namespace is not reserved');
    $assert(($activation['routes_registered'] ?? null) === false, 'foundation registered routes');
    $assert(($foundationDocument['paths'] ?? null) === [], 'foundation paths must remain empty');
    $assert(($operation['path'] ?? null) === $subject['inpatient_context']['candidate_path'], 'candidate path mismatch');
    $assert(
        ($operation['operation_id'] ?? null) === $subject['inpatient_context']['operation_id'],
        'candidate operation id mismatch',
    );
    $assert(($operation['route_namespace_reserved'] ?? null) === true, 'candidate namespace is not reserved');
    foreach ([
        'openapi_inclusion',
        'route_registration_permitted',
        'client_generation_permitted',
        'network_client_permitted',
    ] as $field) {
        $assert(($operation[$field] ?? null) === false, "candidate {$field} must remain false");
    }

    return $violations;
};

$violations = $inspect($config, $configRaw, $foundation, $candidate);
if ($violations !== []) {
    $fail(implode('; ', $violations));
}

$identity = new UnconfiguredNightingaleIdentityBoundary;
$source = new UnconfiguredNightingaleInpatientContextSource;
$gate = new NightingaleEncounterAccessPreconditionGate;
if ($identity->state() !== NightingaleIdentityState::Unavailable) {
    $fail('unconfigured identity boundary did not return unavailable');
}
if ($source->state() !== NightingaleInpatientSourceState::Unavailable) {
    $fail('unconfigured inpatient source did not return unavailable');
}
if ($gate->evaluate($identity, $source) !== NightingalePreconditionDisposition::Withhold) {
    $fail('unconfigured precondition gate did not withhold');
}

foreach (NightingaleIdentityState::cases() as $identityState) {
    foreach (NightingaleInpatientSourceState::cases() as $sourceState) {
        $identityDouble = new class($identityState) implements NightingaleIdentityBoundary
        {
            public function __construct(private readonly NightingaleIdentityState $value) {}

            public function state(): NightingaleIdentityState
            {
                return $this->value;
            }
        };
        $sourceDouble = new class($sourceState) implements NightingaleInpatientContextSource
        {
            public function __construct(private readonly NightingaleInpatientSourceState $value) {}

            public function state(): NightingaleInpatientSourceState
            {
                return $this->value;
            }
        };
        $expected = $identityState === NightingaleIdentityState::VerifiedSelf
            && $sourceState === NightingaleInpatientSourceState::ConfirmedCurrent
            ? NightingalePreconditionDisposition::ContinueToGovernedEvaluation
            : NightingalePreconditionDisposition::Withhold;
        if ($gate->evaluate($identityDouble, $sourceDouble) !== $expected) {
            $fail("precondition truth table failed for {$identityState->value}:{$sourceState->value}");
        }
    }
}

$routeProvider = file_get_contents("{$repositoryRoot}/app/Providers/RouteServiceProvider.php");
if (preg_match('/Route::[^;]*(?:nightingale|Nightingale)/s', $routeProvider) === 1) {
    $fail('RouteServiceProvider contains a Nightingale route registration');
}
if (is_file("{$repositoryRoot}/routes/nightingale.php")) {
    $fail('routes/nightingale.php must not exist in the foundation');
}
$runtimeRegistrationRoots = [
    "{$repositoryRoot}/app/Providers",
    "{$repositoryRoot}/routes",
    "{$repositoryRoot}/bootstrap",
];
$runtimeTokens = [
    '/api/nightingale',
    'NightingaleIdentityBoundary',
    'NightingaleInpatientContextSource',
];
foreach ($runtimeRegistrationRoots as $root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        foreach ($runtimeTokens as $token) {
            if (str_contains($contents, $token)) {
                $fail("runtime registration token {$token} found in ".$file->getPathname());
            }
        }
    }
}

if ($selfTest) {
    $cases = [
        'route activation' => [
            'expected' => 'routes_registered must remain false',
            'mutate' => static function (array &$copy): void {
                $copy['routes_registered'] = true;
            },
        ],
        'identity activation' => [
            'expected' => 'identity was enabled',
            'mutate' => static function (array &$copy): void {
                $copy['identity']['enabled'] = true;
            },
        ],
        'legacy realm reuse' => [
            'expected' => 'legacy patient realm reuse was permitted',
            'mutate' => static function (array &$copy): void {
                $copy['identity']['legacy_patient_realm_reuse_permitted'] = true;
            },
        ],
        'production source query' => [
            'expected' => 'production source query was permitted',
            'mutate' => static function (array &$copy): void {
                $copy['inpatient_context']['production_query_permitted'] = true;
            },
        ],
        'route drift' => [
            'expected' => 'route namespace changed',
            'mutate' => static function (array &$copy): void {
                $copy['route_namespace'] = '/api/patient/v1';
            },
        ],
    ];

    foreach ($cases as $name => $case) {
        $copy = $config;
        $case['mutate']($copy);
        $caseViolations = $inspect($copy, $configRaw, $foundation, $candidate);
        if (! in_array($case['expected'], $caseViolations, true)) {
            $fail("negative self-test \"{$name}\" did not produce expected rejection: {$case['expected']}");
        }
    }
}

fwrite(
    STDOUT,
    'Nightingale unregistered/default-deny backend foundation verified'
    .($selfTest ? ' with negative self-tests' : '')."\n",
);
