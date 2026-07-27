<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__).'/vendor/autoload.php';

const PATIENT_CONTRACT = 'docs/hummingbird/api-contract/hummingbird-patient.v1.yaml';
const PATIENT_ROUTE_PREFIX = '/api/patient/v1';

/** @param list<string> $errors */
function failPatientContract(array $errors): never
{
    fwrite(STDERR, "Hummingbird patient contract verification failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }

    exit(1);
}

/** @return array<string, mixed>|null */
function resolveComponentResponse(array $spec, string $reference): ?array
{
    $prefix = '#/components/responses/';
    if (! str_starts_with($reference, $prefix)) {
        return null;
    }

    $name = substr($reference, strlen($prefix));
    $response = $spec['components']['responses'][$name] ?? null;

    return is_array($response) ? $response : null;
}

/** @return list<string> */
function schemaPropertyNames(mixed $node): array
{
    if (! is_array($node)) {
        return [];
    }

    $names = [];
    if (is_array($node['properties'] ?? null)) {
        foreach (array_keys($node['properties']) as $property) {
            if (is_string($property)) {
                $names[] = $property;
            }
        }
    }

    foreach ($node as $value) {
        $names = array_merge($names, schemaPropertyNames($value));
    }

    return array_values(array_unique($names));
}

/** @return list<string> */
function localReferences(mixed $node): array
{
    if (! is_array($node)) {
        return [];
    }

    $references = [];
    if (is_string($node['$ref'] ?? null) && str_starts_with($node['$ref'], '#/')) {
        $references[] = $node['$ref'];
    }

    foreach ($node as $value) {
        $references = array_merge($references, localReferences($value));
    }

    return array_values(array_unique($references));
}

function localReferenceExists(array $spec, string $reference): bool
{
    $node = $spec;
    foreach (explode('/', substr($reference, 2)) as $segment) {
        $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
        if (! is_array($node) || ! array_key_exists($segment, $node)) {
            return false;
        }
        $node = $node[$segment];
    }

    return true;
}

$root = dirname(__DIR__);
$contractFile = $root.'/'.PATIENT_CONTRACT;
$errors = [];

try {
    $spec = Yaml::parseFile($contractFile);
} catch (ParseException $exception) {
    failPatientContract([PATIENT_CONTRACT.' is not valid YAML: '.$exception->getMessage()]);
}

if (! is_array($spec)) {
    failPatientContract([PATIENT_CONTRACT.' must contain a YAML mapping.']);
}

if (($spec['openapi'] ?? null) !== '3.1.0') {
    $errors[] = 'The patient contract must use OpenAPI 3.1.0.';
}

$identifierPolicy = $spec['info']['x-zephyrus-identifier-policy'] ?? [];
if (($identifierPolicy['permitted'] ?? null) !== 'opaque_patient_product_uuids_only'
    || ($identifierPolicy['source_linkage_fields'] ?? null) !== 'prohibited'
    || ($identifierPolicy['embedded_source_meaning'] ?? null) !== 'prohibited') {
    $errors[] = 'The patient contract must prohibit source linkage and permit only opaque product UUIDs.';
}

$serverUrl = $spec['servers'][0]['url'] ?? null;
if (! is_string($serverUrl) || ! str_ends_with($serverUrl, PATIENT_ROUTE_PREFIX)) {
    $errors[] = 'The first server URL must end at '.PATIENT_ROUTE_PREFIX.'.';
}

$requiredExtensions = [
    'x-zephyrus-authorization',
    'x-zephyrus-data-classification',
    'x-zephyrus-idempotency',
    'x-zephyrus-release-policy',
    'x-zephyrus-error-behavior',
    'x-zephyrus-audit',
    'x-zephyrus-projection-semantics',
];
$httpMethods = ['get', 'post', 'put', 'patch', 'delete'];
$specOperations = [];
$operationIds = [];
$paths = $spec['paths'] ?? null;

if (! is_array($paths) || $paths === []) {
    $errors[] = 'The patient contract must define at least one path.';
    $paths = [];
}

foreach (localReferences($spec) as $reference) {
    if (! localReferenceExists($spec, $reference)) {
        $errors[] = "Contract reference {$reference} does not resolve.";
    }
}

foreach ($paths as $relativePath => $pathItem) {
    if (! is_string($relativePath) || ! str_starts_with($relativePath, '/')) {
        $errors[] = 'Every contract path must be relative to the patient server and begin with /.'.
            ' Found '.var_export($relativePath, true).'.';

        continue;
    }

    if (str_starts_with($relativePath, PATIENT_ROUTE_PREFIX)) {
        $errors[] = "Contract path {$relativePath} repeats the server prefix.";
    }

    preg_match_all('/\{([^}]+)\}/', $relativePath, $pathParameters);
    foreach ($pathParameters[1] ?? [] as $parameter) {
        if (! is_string($parameter) || preg_match('/^[a-z][A-Za-z0-9]*Uuid$/', $parameter) !== 1) {
            $errors[] = "Contract path {$relativePath} uses a non-opaque path parameter {$parameter}.";
        }
    }

    if (! is_array($pathItem)) {
        $errors[] = "Contract path {$relativePath} must be a mapping.";

        continue;
    }

    foreach ($httpMethods as $method) {
        if (! array_key_exists($method, $pathItem)) {
            continue;
        }

        $operation = $pathItem[$method];
        $operationKey = strtoupper($method).' '.PATIENT_ROUTE_PREFIX.$relativePath;
        $specOperations[$operationKey] = true;

        if (! is_array($operation)) {
            $errors[] = "{$operationKey} must be an operation mapping.";

            continue;
        }

        $operationId = $operation['operationId'] ?? null;
        if (! is_string($operationId) || trim($operationId) === '') {
            $errors[] = "{$operationKey} is missing a stable operationId.";
        } elseif (isset($operationIds[$operationId])) {
            $errors[] = "operationId {$operationId} is duplicated.";
        } else {
            $operationIds[$operationId] = true;
        }

        foreach ($requiredExtensions as $extension) {
            if (! is_array($operation[$extension] ?? null) || $operation[$extension] === []) {
                $errors[] = "{$operationKey} is missing required operation extension {$extension}.";
            }
        }

        $authorization = $operation['x-zephyrus-authorization'] ?? [];
        if (($authorization['realm'] ?? null) !== 'patient') {
            $errors[] = "{$operationKey} must declare the patient authorization realm.";
        }

        $abilities = array_merge(
            is_array($authorization['required_abilities'] ?? null)
                ? $authorization['required_abilities']
                : [],
            is_array($authorization['any_required_ability'] ?? null)
                ? $authorization['any_required_ability']
                : [],
        );
        foreach ($abilities as $ability) {
            if (! in_array($ability, ['patient:access', 'patient:refresh'], true)) {
                $errors[] = "{$operationKey} declares a non-patient bearer ability.";
            }
        }

        $security = $operation['security'] ?? null;
        if (($abilities === [] && $security !== [])
            || ($abilities !== [] && $security !== [['patientBearer' => []]])) {
            $errors[] = "{$operationKey} security declaration does not match its patient bearer abilities.";
        }

        $releasePolicy = $operation['x-zephyrus-release-policy'] ?? [];
        if (($releasePolicy['product_flag'] ?? null) !== 'HUMMINGBIRD_PATIENT_ENABLED'
            || ($releasePolicy['default'] ?? null) !== 'disabled'
            || ($releasePolicy['disabled_status'] ?? null) !== 404) {
            $errors[] = "{$operationKey} must document fail-closed product and feature-gate behavior.";
        }

        $dataClassification = $operation['x-zephyrus-data-classification'] ?? [];
        foreach (['request', 'response', 'storage'] as $field) {
            if (! is_string($dataClassification[$field] ?? null) || trim($dataClassification[$field]) === '') {
                $errors[] = "{$operationKey} data classification is missing {$field}.";
            }
        }

        $idempotency = $operation['x-zephyrus-idempotency'] ?? [];
        foreach (['semantics', 'idempotency_key', 'retry'] as $field) {
            if (! is_string($idempotency[$field] ?? null) || trim($idempotency[$field]) === '') {
                $errors[] = "{$operationKey} idempotency policy is missing {$field}.";
            }
        }

        $errorBehavior = $operation['x-zephyrus-error-behavior'] ?? [];
        if (($errorBehavior['envelope'] ?? null) !== 'PatientErrorEnvelope'
            || ($errorBehavior['diagnostic_detail_exposed'] ?? null) !== false
            || ! is_array($errorBehavior['expected_statuses'] ?? null)
            || $errorBehavior['expected_statuses'] === []) {
            $errors[] = "{$operationKey} must declare the patient-safe error envelope and expected statuses.";
        }

        $audit = $operation['x-zephyrus-audit'] ?? [];
        if (($audit['required'] ?? null) !== true
            || ! is_array($audit['success_events'] ?? null)
            || ! is_array($audit['denial_events'] ?? null)
            || ($audit['secret_values_recorded'] ?? null) !== false) {
            $errors[] = "{$operationKey} must declare fail-closed audit behavior without recording secrets.";
        }

        $projectionSemantics = $operation['x-zephyrus-projection-semantics'] ?? [];
        foreach (['freshness', 'provenance', 'uncertainty', 'retraction'] as $field) {
            if (! is_string($projectionSemantics[$field] ?? null)
                || trim($projectionSemantics[$field]) === '') {
                $errors[] = "{$operationKey} projection semantics are missing {$field}.";
            }
        }

        $responses = $operation['responses'] ?? null;
        if (! is_array($responses)) {
            $errors[] = "{$operationKey} has no response map.";

            continue;
        }

        $successResponses = array_filter(
            $responses,
            static fn (mixed $response, string|int $status): bool => preg_match('/^2[0-9]{2}$/', (string) $status) === 1,
            ARRAY_FILTER_USE_BOTH,
        );
        if ($successResponses === []) {
            $errors[] = "{$operationKey} must define a success response.";
        }

        foreach ($successResponses as $status => $responseReference) {
            $reference = is_array($responseReference) ? ($responseReference['$ref'] ?? null) : null;
            $resolved = is_string($reference) ? resolveComponentResponse($spec, $reference) : null;
            $schemaReference = $resolved['content']['application/json']['schema']['$ref'] ?? null;
            if (! is_string($schemaReference)
                || ! str_starts_with($schemaReference, '#/components/schemas/')
                || ! str_ends_with($schemaReference, 'SuccessEnvelope')) {
                $errors[] = "{$operationKey} {$status} must reference a reusable success-envelope response.";

                continue;
            }

            $schemaName = substr($schemaReference, strlen('#/components/schemas/'));
            $schema = $spec['components']['schemas'][$schemaName] ?? null;
            $serialized = is_array($schema) ? json_encode($schema, JSON_UNESCAPED_SLASHES) : false;
            if (! is_string($serialized) || ! str_contains($serialized, '#/components/schemas/PatientSuccessEnvelope')) {
                $errors[] = "{$operationKey} {$status} success schema must compose PatientSuccessEnvelope.";
            }
        }

        if (($responses['default']['$ref'] ?? null) !== '#/components/responses/PatientError') {
            $errors[] = "{$operationKey} must reference PatientError as its default failure response.";
        }
    }
}

$metaRequired = $spec['components']['schemas']['PatientMeta']['required'] ?? null;
if (! is_array($metaRequired)) {
    $errors[] = 'PatientMeta must define required metadata fields.';
} else {
    sort($metaRequired);
    $requiredMeta = ['generated_at', 'policy_version', 'request_id', 'source_freshness'];
    if ($metaRequired !== $requiredMeta) {
        $errors[] = 'PatientMeta required fields must be exactly: '.implode(', ', $requiredMeta).'.';
    }
}

$stateVocabularyVersion = $spec['components']['schemas']['PatientMeta']['properties']['state_vocabulary_version'] ?? null;
if (! is_array($stateVocabularyVersion)
    || ($stateVocabularyVersion['type'] ?? null) !== 'string'
    || ($stateVocabularyVersion['minLength'] ?? null) !== 1) {
    $errors[] = 'PatientMeta.state_vocabulary_version must be an additive non-empty string.';
}

$successRequired = $spec['components']['schemas']['PatientSuccessEnvelope']['required'] ?? [];
sort($successRequired);
if ($successRequired !== ['data', 'links', 'meta']) {
    $errors[] = 'PatientSuccessEnvelope must require exactly data, meta, and links.';
}

$errorRequired = $spec['components']['schemas']['PatientErrorEnvelope']['required'] ?? [];
sort($errorRequired);
if ($errorRequired !== ['data', 'error', 'links', 'meta']) {
    $errors[] = 'PatientErrorEnvelope must require exactly data, error, meta, and links.';
}

$contractSource = file_get_contents($contractFile);
if (! is_string($contractSource)) {
    $errors[] = 'Unable to read '.PATIENT_CONTRACT.'.';
} else {
    if (preg_match('#/api/(?:mobile|auth)(?:/|\b)#i', $contractSource) === 1) {
        $errors[] = 'The patient contract contains an endpoint from a different API boundary.';
    }
    if (preg_match('/\bstaff\b/i', $contractSource) === 1) {
        $errors[] = 'The patient contract contains a forbidden staff-realm term.';
    }
}

$forbiddenProperties = [
    'id',
    'patient_id',
    'encounter_id',
    'user_id',
    'staff_id',
    'provider_id',
    'employee_id',
    'account_number',
    'medical_record_number',
    'mrn',
    'source_identifier',
    'source_patient_ref',
    'source_encounter_ref',
    'source_encounter_ref_digest',
    'source_encounter_ref_ciphertext',
    'raw_payload',
    'raw_fhir',
    'fhir_id',
    'patient_ref',
    'encounter_ref',
];
foreach (schemaPropertyNames($spec['components']['schemas'] ?? []) as $property) {
    if (in_array(strtolower($property), $forbiddenProperties, true)) {
        $errors[] = "Patient contract schema exposes forbidden identifier property {$property}.";
    }
}

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$routeOperations = [];
$routeFeatureFlags = [];
$routeAbilities = [];
foreach (Route::getRoutes() as $route) {
    if (! $route instanceof IlluminateRoute
        || ($route->uri() !== 'api/patient/v1'
            && ! str_starts_with($route->uri(), 'api/patient/v1/'))) {
        continue;
    }

    $middleware = $route->gatherMiddleware();
    if (! in_array('patient.response', $middleware, true)
        || ! in_array('patient.enabled', $middleware, true)) {
        $errors[] = 'Registered patient route '.$route->uri().' is missing the patient boundary middleware.';
    }

    $featureMiddleware = array_values(array_filter(
        $middleware,
        static fn (string $name): bool => str_starts_with($name, 'patient.feature:'),
    ));
    if (count($featureMiddleware) !== 1) {
        $errors[] = 'Registered patient route '.$route->uri().' must have exactly one feature gate.';
        $feature = null;
    } else {
        $feature = substr($featureMiddleware[0], strlen('patient.feature:'));
    }

    $abilityMiddleware = array_values(array_filter(
        $middleware,
        static fn (string $name): bool => str_starts_with($name, CheckForAnyAbility::class.':'),
    ));
    $abilities = [];
    if (count($abilityMiddleware) > 1) {
        $errors[] = 'Registered patient route '.$route->uri().' has more than one bearer-ability check.';
    } elseif ($abilityMiddleware !== []) {
        $abilities = explode(',', substr($abilityMiddleware[0], strlen(CheckForAnyAbility::class.':')));
        sort($abilities);
    }

    foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
        $operationKey = $method.' /'.ltrim($route->uri(), '/');
        $routeOperations[$operationKey] = true;
        $routeAbilities[$operationKey] = $abilities;
        if (is_string($feature)) {
            $routeFeatureFlags[$operationKey] = 'HUMMINGBIRD_PATIENT_'.strtoupper($feature).'_ENABLED';
        }
    }
}

$registered = array_keys($routeOperations);
$documented = array_keys($specOperations);
sort($registered);
sort($documented);

foreach (array_diff($registered, $documented) as $operation) {
    $errors[] = "Registered patient operation {$operation} is missing from the contract.";
}
foreach (array_diff($documented, $registered) as $operation) {
    $errors[] = "Contract operation {$operation} is not registered by Laravel.";
}

foreach ($paths as $relativePath => $pathItem) {
    if (! is_string($relativePath) || ! is_array($pathItem)) {
        continue;
    }
    foreach ($httpMethods as $method) {
        if (! is_array($pathItem[$method] ?? null)) {
            continue;
        }
        $operationKey = strtoupper($method).' '.PATIENT_ROUTE_PREFIX.$relativePath;
        $documentedFlag = $pathItem[$method]['x-zephyrus-release-policy']['feature_flag'] ?? null;
        if (isset($routeFeatureFlags[$operationKey]) && $documentedFlag !== $routeFeatureFlags[$operationKey]) {
            $errors[] = "{$operationKey} documents feature flag {$documentedFlag}; Laravel uses {$routeFeatureFlags[$operationKey]}.";
        }

        $authorization = $pathItem[$method]['x-zephyrus-authorization'] ?? [];
        $documentedAbilities = array_merge(
            is_array($authorization['required_abilities'] ?? null)
                ? $authorization['required_abilities']
                : [],
            is_array($authorization['any_required_ability'] ?? null)
                ? $authorization['any_required_ability']
                : [],
        );
        sort($documentedAbilities);
        if (isset($routeAbilities[$operationKey]) && $documentedAbilities !== $routeAbilities[$operationKey]) {
            $errors[] = "{$operationKey} documented bearer abilities do not match Laravel middleware.";
        }

        // Paging guard (plan §9.5): the patient API must not expose offset/page
        // pagination. Any future paging must be an opaque, scoped, short-lived
        // cursor, marked `x-zephyrus-cursor: opaque_scoped_short_lived`.
        $parameters = array_merge(
            is_array($pathItem['parameters'] ?? null) ? $pathItem['parameters'] : [],
            is_array($pathItem[$method]['parameters'] ?? null) ? $pathItem[$method]['parameters'] : [],
        );
        foreach ($parameters as $parameter) {
            if (! is_array($parameter) || ($parameter['in'] ?? null) !== 'query') {
                continue;
            }
            $paramName = strtolower((string) ($parameter['name'] ?? ''));
            if (in_array($paramName, ['page', 'offset', 'per_page', 'limit'], true)) {
                $errors[] = "{$operationKey} declares offset/page paging parameter '{$paramName}'; patient paging must be an opaque, scoped, short-lived cursor, not offset/page.";
            } elseif ($paramName === 'cursor'
                && ($parameter['x-zephyrus-cursor'] ?? null) !== 'opaque_scoped_short_lived'
            ) {
                $errors[] = "{$operationKey} cursor parameter must be marked x-zephyrus-cursor: opaque_scoped_short_lived.";
            }
        }
    }
}

if ($errors !== []) {
    failPatientContract($errors);
}

fwrite(
    STDOUT,
    sprintf(
        "Hummingbird patient contract verified: %d Laravel operations match OpenAPI with governed envelopes and controls.\n",
        count($registered),
    ),
);
