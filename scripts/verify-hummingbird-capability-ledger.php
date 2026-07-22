<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__).'/vendor/autoload.php';

const LEDGER_PATH = 'docs/hummingbird/capability-ledger.v1.yaml';
const NAVIGATION_PATH = 'resources/js/config/navigationConfig.ts';

/** @param list<string> $errors */
function fail(array $errors): never
{
    fwrite(STDERR, "Hummingbird capability ledger verification failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }

    exit(1);
}

/** @return list<string> */
function stringList(mixed $value, string $field, string $id, array &$errors): array
{
    if (! is_array($value) || ! array_is_list($value)) {
        $errors[] = "{$id}.{$field} must be a list.";

        return [];
    }

    $strings = [];
    foreach ($value as $index => $item) {
        if (! is_string($item) || trim($item) === '') {
            $errors[] = "{$id}.{$field}[{$index}] must be a non-empty string.";

            continue;
        }
        $strings[] = $item;
    }

    return $strings;
}

$root = dirname(__DIR__);
$ledgerFile = $root.'/'.LEDGER_PATH;
$navigationFile = $root.'/'.NAVIGATION_PATH;
$errors = [];

try {
    $ledger = Yaml::parseFile($ledgerFile);
} catch (ParseException $exception) {
    fail([LEDGER_PATH.' is not valid YAML: '.$exception->getMessage()]);
}

if (! is_array($ledger)) {
    fail([LEDGER_PATH.' must contain a YAML mapping.']);
}

if (($ledger['version'] ?? null) !== 1) {
    $errors[] = 'Ledger version must be the integer 1.';
}

$capabilities = $ledger['capabilities'] ?? null;
if (! is_array($capabilities) || ! array_is_list($capabilities) || $capabilities === []) {
    fail(['Ledger capabilities must be a non-empty list.']);
}

$allowedDispositions = ['NATIVE', 'GLANCE', 'NOTIFY', 'DEEPLINK', 'DESKTOP_ONLY', 'PATIENT', 'RETIRED'];
$allowedStatuses = ['complete', 'partial', 'planned', 'not_applicable'];
$allowedOffline = ['NO_CACHE', 'ENCRYPTED_READ_CACHE', 'READ_CACHE_AND_OUTBOX'];
$allowedClassification = ['public', 'internal', 'phi_minimized', 'phi'];
$ids = [];
$ownedWebRoutes = [];
$ownedApiOperations = [];
$ownedPatientApiOperations = [];
$roleSets = $ledger['role_sets'] ?? null;

if (! is_array($roleSets) || $roleSets === []) {
    $errors[] = 'Ledger role_sets must be a non-empty mapping.';
    $roleSets = [];
}

foreach ($capabilities as $index => $capability) {
    if (! is_array($capability)) {
        $errors[] = "capabilities[{$index}] must be a mapping.";

        continue;
    }

    $id = $capability['id'] ?? "capabilities[{$index}]";
    if (! is_string($id) || preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $id) !== 1) {
        $errors[] = "capabilities[{$index}].id must be a stable lowercase identifier.";
        $id = "capabilities[{$index}]";
    } elseif (isset($ids[$id])) {
        $errors[] = "Duplicate capability id {$id}.";
    } else {
        $ids[$id] = true;
    }

    foreach (['domain', 'title', 'phase'] as $field) {
        if (! is_string($capability[$field] ?? null) || trim((string) $capability[$field]) === '') {
            $errors[] = "{$id}.{$field} must be a non-empty string.";
        }
    }

    $status = $capability['implementation_status'] ?? null;
    if (! in_array($status, $allowedStatuses, true)) {
        $errors[] = "{$id}.implementation_status must be one of: ".implode(', ', $allowedStatuses).'.';
    }

    $disposition = $capability['target_disposition'] ?? null;
    if (! in_array($disposition, $allowedDispositions, true)) {
        $errors[] = "{$id}.target_disposition must be one of: ".implode(', ', $allowedDispositions).'.';
    }

    if (! in_array($capability['offline_class'] ?? null, $allowedOffline, true)) {
        $errors[] = "{$id}.offline_class must be one of: ".implode(', ', $allowedOffline).'.';
    }

    if (! in_array($capability['data_classification'] ?? null, $allowedClassification, true)) {
        $errors[] = "{$id}.data_classification must be one of: ".implode(', ', $allowedClassification).'.';
    }

    $owners = $capability['owners'] ?? null;
    if (! is_array($owners)) {
        $errors[] = "{$id}.owners must be a mapping.";
    } else {
        foreach (['product', 'engineering'] as $ownerField) {
            if (! is_string($owners[$ownerField] ?? null) || trim((string) $owners[$ownerField]) === '') {
                $errors[] = "{$id}.owners.{$ownerField} must be a non-empty owner or owner group.";
            }
        }
    }

    $webRoutes = stringList($capability['web_routes'] ?? null, 'web_routes', $id, $errors);
    $staffOperations = stringList($capability['staff_bff_operations'] ?? null, 'staff_bff_operations', $id, $errors);
    $patientOperations = stringList($capability['patient_api_operations'] ?? [], 'patient_api_operations', $id, $errors);

    // Patient-facing capabilities (PATIENT disposition or any patient API operation)
    // must default to NO_CACHE until a threat model approves an on-device cache.
    if (($disposition === 'PATIENT' || $patientOperations !== [])
        && ($capability['offline_class'] ?? null) !== 'NO_CACHE'
    ) {
        $errors[] = "{$id} is patient-facing but offline_class is "
            .var_export($capability['offline_class'] ?? null, true)
            .'; patient context must default to NO_CACHE until threat-model approval.';
    }
    $iosRoutes = stringList($capability['ios_routes'] ?? null, 'ios_routes', $id, $errors);
    $androidRoutes = stringList($capability['android_routes'] ?? null, 'android_routes', $id, $errors);
    $userRoles = stringList($capability['user_roles'] ?? null, 'user_roles', $id, $errors);
    $verificationEvidence = stringList($capability['verification_evidence'] ?? null, 'verification_evidence', $id, $errors);
    stringList($capability['notification_events'] ?? null, 'notification_events', $id, $errors);

    if ($userRoles === []) {
        $errors[] = "{$id}.user_roles must identify at least one role or governed principal type.";
    }
    foreach ($userRoles as $role) {
        if (str_starts_with($role, '@') && ! array_key_exists(substr($role, 1), $roleSets)) {
            $errors[] = "{$id}.user_roles references unknown role set {$role}.";
        }
    }

    foreach ($webRoutes as $route) {
        if (! str_starts_with($route, '/')) {
            $errors[] = "{$id}.web_routes contains non-absolute route {$route}.";
        }
        if (isset($ownedWebRoutes[$route])) {
            $errors[] = "Web route {$route} is owned by both {$ownedWebRoutes[$route]} and {$id}.";
        } else {
            $ownedWebRoutes[$route] = $id;
        }
    }

    foreach ($staffOperations as $operation) {
        if (preg_match('/^(GET|POST|PUT|PATCH|DELETE) \/api\//', $operation) !== 1) {
            $errors[] = "{$id}.staff_bff_operations contains invalid operation {$operation}.";
        }
        if (isset($ownedApiOperations[$operation])) {
            $errors[] = "API operation {$operation} is owned by both {$ownedApiOperations[$operation]} and {$id}.";
        } else {
            $ownedApiOperations[$operation] = $id;
        }
    }

    foreach ($patientOperations as $operation) {
        if (preg_match('/^(GET|POST|PUT|PATCH|DELETE) \/api\/patient\/v1(?:\/|$)/', $operation) !== 1) {
            $errors[] = "{$id}.patient_api_operations contains invalid operation {$operation}.";
        }
        if (isset($ownedPatientApiOperations[$operation])) {
            $errors[] = "Patient API operation {$operation} is owned by both {$ownedPatientApiOperations[$operation]} and {$id}.";
        } else {
            $ownedPatientApiOperations[$operation] = $id;
        }
    }

    foreach (array_merge($iosRoutes, $androidRoutes) as $path) {
        if (! is_file($root.'/'.$path)) {
            $errors[] = "{$id} references missing native file {$path}.";
        }
    }

    foreach ($verificationEvidence as $path) {
        if (! is_file($root.'/'.$path)) {
            $errors[] = "{$id} references missing verification evidence {$path}.";
        }
    }

    if ($disposition === 'NATIVE' && (($iosRoutes === []) xor ($androidRoutes === []))) {
        $errors[] = "{$id} is NATIVE but only one native platform has an implementation route.";
    }

    if ($disposition === 'PATIENT' && $status === 'partial') {
        if ($patientOperations === [] || $iosRoutes === [] || $androidRoutes === [] || $verificationEvidence === []) {
            $errors[] = "{$id} is partial PATIENT but lacks patient API ownership, both native platform routes, or verification evidence.";
        }
    }

    if ($status === 'complete' && in_array($disposition, ['NATIVE', 'GLANCE'], true)) {
        if ($staffOperations === [] || $iosRoutes === [] || $androidRoutes === []) {
            $errors[] = "{$id} is complete {$disposition} but lacks a BFF operation or native platform route.";
        }
    }
}

$navigationSource = file_get_contents($navigationFile);
if ($navigationSource === false) {
    fail(array_merge($errors, ['Unable to read '.NAVIGATION_PATH.'.']));
}

preg_match_all(
    '/\b(?:href|dashboardHref|homeHref):\s*[\'\"]([^\'\"]+)[\'\"]/',
    $navigationSource,
    $navigationMatches,
);

$navigationRoutes = array_values(array_unique(array_filter(
    $navigationMatches[1] ?? [],
    static fn (string $route): bool => str_starts_with($route, '/'),
)));
sort($navigationRoutes);

$ledgerRoutes = array_keys($ownedWebRoutes);
sort($ledgerRoutes);

foreach (array_diff($navigationRoutes, $ledgerRoutes) as $route) {
    $errors[] = "Navigation route {$route} has no capability-ledger owner.";
}

foreach (array_diff($ledgerRoutes, $navigationRoutes) as $route) {
    $errors[] = "Capability-ledger web route {$route} is not present in the navigation source of truth.";
}

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$applicationRoutes = [];
foreach (Route::getRoutes() as $route) {
    foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
        $applicationRoutes[$method.' /'.ltrim($route->uri(), '/')] = true;
    }
}

foreach ($ledgerRoutes as $route) {
    if (! isset($applicationRoutes['GET '.$route])) {
        $errors[] = "Capability-ledger web route {$route} is not registered as a GET route.";
    }
}

$requiredMobileOperations = [];
foreach (array_keys($applicationRoutes) as $operation) {
    if (str_contains($operation, ' /api/mobile/v1/')) {
        $requiredMobileOperations[] = $operation;
    }
}
foreach ([
    'POST /api/auth/token',
    'POST /api/auth/token/refresh',
    'POST /api/auth/token/revoke',
    'POST /api/auth/change-password',
] as $authOperation) {
    if (isset($applicationRoutes[$authOperation])) {
        $requiredMobileOperations[] = $authOperation;
    }
}
$requiredMobileOperations = array_values(array_unique($requiredMobileOperations));
sort($requiredMobileOperations);

$ledgerApiOperations = array_keys($ownedApiOperations);
sort($ledgerApiOperations);

foreach (array_diff($requiredMobileOperations, $ledgerApiOperations) as $operation) {
    $errors[] = "Mobile API operation {$operation} has no capability-ledger owner.";
}

foreach (array_diff($ledgerApiOperations, $requiredMobileOperations) as $operation) {
    $errors[] = "Capability-ledger operation {$operation} is not a registered staff mobile/auth route.";
}

$requiredPatientOperations = [];
foreach (array_keys($applicationRoutes) as $operation) {
    if (str_contains($operation, ' /api/patient/v1/')
        || str_ends_with($operation, ' /api/patient/v1')) {
        $requiredPatientOperations[] = $operation;
    }
}
$requiredPatientOperations = array_values(array_unique($requiredPatientOperations));
sort($requiredPatientOperations);

$ledgerPatientApiOperations = array_keys($ownedPatientApiOperations);
sort($ledgerPatientApiOperations);

foreach (array_diff($requiredPatientOperations, $ledgerPatientApiOperations) as $operation) {
    $errors[] = "Patient API operation {$operation} has no capability-ledger owner.";
}

foreach (array_diff($ledgerPatientApiOperations, $requiredPatientOperations) as $operation) {
    $errors[] = "Capability-ledger patient operation {$operation} is not a registered patient route.";
}

// --- Deprecation-record gate (plan §6.1) -------------------------------------
// Every capability id that ever existed is recorded in the append-only registry
// lock. An id may only leave the active ledger if it has a deprecation record,
// so a silent delete or remap fails CI.
$capabilityIdPattern = '/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/';
$activeIds = array_values(array_filter(
    array_keys($ids),
    static fn (string $id): bool => preg_match($capabilityIdPattern, $id) === 1,
));

$lockPath = $root.'/docs/hummingbird/capability-registry.lock';
$registryIds = [];
$lockAvailable = is_file($lockPath);
if (! $lockAvailable) {
    $errors[] = 'Missing docs/hummingbird/capability-registry.lock (append-only registry of every capability id).';
} else {
    foreach (file($lockPath, FILE_IGNORE_NEW_LINES) as $lineNo => $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (preg_match($capabilityIdPattern, $trimmed) !== 1) {
            $errors[] = 'capability-registry.lock line '.($lineNo + 1)." is not a valid capability id: {$trimmed}.";

            continue;
        }
        if (isset($registryIds[$trimmed])) {
            $errors[] = "capability-registry.lock lists {$trimmed} more than once.";
        }
        $registryIds[$trimmed] = true;
    }
}

$deprecationPath = $root.'/docs/hummingbird/capability-deprecations.v1.yaml';
$deprecatedIds = [];
if (! is_file($deprecationPath)) {
    $errors[] = 'Missing docs/hummingbird/capability-deprecations.v1.yaml.';
} else {
    try {
        $deprecationDoc = Yaml::parseFile($deprecationPath);
    } catch (ParseException $exception) {
        $deprecationDoc = null;
        $errors[] = 'capability-deprecations.v1.yaml is not valid YAML: '.$exception->getMessage();
    }

    if (is_array($deprecationDoc)) {
        if (($deprecationDoc['version'] ?? null) !== 1) {
            $errors[] = 'capability-deprecations.v1.yaml version must be the integer 1.';
        }
        $records = $deprecationDoc['deprecations'] ?? null;
        if (! is_array($records) || ! array_is_list($records)) {
            $errors[] = 'capability-deprecations.v1.yaml deprecations must be a list.';
        } else {
            foreach ($records as $recIndex => $record) {
                $label = "deprecations[{$recIndex}]";
                if (! is_array($record)) {
                    $errors[] = "{$label} must be a mapping.";

                    continue;
                }
                $depId = $record['id'] ?? null;
                if (! is_string($depId) || preg_match($capabilityIdPattern, $depId) !== 1) {
                    $errors[] = "{$label}.id must be a stable lowercase capability id.";

                    continue;
                }
                if (isset($deprecatedIds[$depId])) {
                    $errors[] = "Duplicate deprecation record for {$depId}.";
                }
                $deprecatedIds[$depId] = $record;
                if (! is_string($record['retired_on'] ?? null)
                    || preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($record['retired_on'] ?? '')) !== 1
                ) {
                    $errors[] = "{$depId}.retired_on must be a YYYY-MM-DD date.";
                }
                foreach (['reason', 'migration_note'] as $textField) {
                    if (! is_string($record[$textField] ?? null) || trim((string) $record[$textField]) === '') {
                        $errors[] = "{$depId}.{$textField} must be a non-empty string.";
                    }
                }
                if (array_key_exists('replaced_by', $record) && $record['replaced_by'] !== null) {
                    $replacedBy = $record['replaced_by'];
                    if (! is_string($replacedBy) || preg_match($capabilityIdPattern, $replacedBy) !== 1) {
                        $errors[] = "{$depId}.replaced_by must be null or a stable lowercase capability id.";
                    }
                }
            }
        }
    }
}

// An id cannot be both active and retired.
foreach (array_intersect($activeIds, array_keys($deprecatedIds)) as $conflict) {
    $errors[] = "Capability {$conflict} is both active in the ledger and marked deprecated.";
}

if ($lockAvailable) {
    // Active and deprecated ids must be registered in the lock.
    foreach ($activeIds as $activeId) {
        if (! isset($registryIds[$activeId])) {
            $errors[] = "Active capability {$activeId} is missing from capability-registry.lock; append it when introducing a capability.";
        }
    }
    foreach (array_keys($deprecatedIds) as $depId) {
        if (! isset($registryIds[$depId])) {
            $errors[] = "Deprecated capability {$depId} is missing from capability-registry.lock.";
        }
    }
    // Every registered id is either active or carries a deprecation record.
    $activeLookup = array_fill_keys($activeIds, true);
    foreach (array_keys($registryIds) as $registeredId) {
        if (! isset($activeLookup[$registeredId]) && ! isset($deprecatedIds[$registeredId])) {
            $errors[] = "Capability {$registeredId} was removed from the ledger without a deprecation record; add one to capability-deprecations.v1.yaml before deleting or remapping a capability id.";
        }
    }
}

// A successor id must be a known capability (active or registered).
foreach ($deprecatedIds as $depId => $record) {
    $replacedBy = $record['replaced_by'] ?? null;
    if (is_string($replacedBy)
        && ! in_array($replacedBy, $activeIds, true)
        && ! isset($registryIds[$replacedBy])
    ) {
        $errors[] = "{$depId}.replaced_by references unknown capability {$replacedBy}.";
    }
}

if ($errors !== []) {
    fail($errors);
}

fwrite(
    STDOUT,
    sprintf(
        "Hummingbird capability ledger verified: %d capabilities own %d navigation routes, %d staff mobile/auth operations, and %d patient operations; registry lock tracks %d ids with %d deprecation records.\n",
        count($capabilities),
        count($navigationRoutes),
        count($requiredMobileOperations),
        count($requiredPatientOperations),
        count($registryIds),
        count($deprecatedIds),
    ),
);
