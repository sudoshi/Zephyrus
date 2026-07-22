<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__).'/vendor/autoload.php';

const HUMMINGBIRD_STAFF_CONTRACT = 'docs/hummingbird/api-contract/hummingbird-bff.v1.yaml';
const HUMMINGBIRD_STAFF_PREFIX = '/api/mobile/v1';
const HUMMINGBIRD_COMMUNICATION_PREFIX = '/patient-communications';

/** @param list<string> $errors */
function failHummingbirdStaffContract(array $errors): never
{
    fwrite(STDERR, "Hummingbird staff contract verification failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }

    exit(1);
}

/** @return list<string> */
function hummingbirdStaffLocalReferences(mixed $node): array
{
    if (! is_array($node)) {
        return [];
    }

    $references = [];
    if (is_string($node['$ref'] ?? null) && str_starts_with($node['$ref'], '#/')) {
        $references[] = $node['$ref'];
    }

    foreach ($node as $value) {
        $references = array_merge($references, hummingbirdStaffLocalReferences($value));
    }

    return array_values(array_unique($references));
}

function hummingbirdStaffReferenceExists(array $spec, string $reference): bool
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

/** @return list<string> */
function hummingbirdStaffRequiredFields(array $spec, string $schemaName): array
{
    $required = $spec['components']['schemas'][$schemaName]['required'] ?? [];
    if (! is_array($required) || ! array_is_list($required)) {
        return [];
    }

    $required = array_values(array_filter($required, 'is_string'));
    sort($required);

    return $required;
}

/** @return list<string> */
function hummingbirdStaffParameterReferences(array $operation): array
{
    $references = [];
    foreach ($operation['parameters'] ?? [] as $parameter) {
        if (is_array($parameter) && is_string($parameter['$ref'] ?? null)) {
            $references[] = $parameter['$ref'];
        }
    }

    return $references;
}

$root = dirname(__DIR__);
$contractFile = $root.'/'.HUMMINGBIRD_STAFF_CONTRACT;
$errors = [];

try {
    $spec = Yaml::parseFile($contractFile);
} catch (ParseException $exception) {
    failHummingbirdStaffContract([
        HUMMINGBIRD_STAFF_CONTRACT.' is not valid YAML: '.$exception->getMessage(),
    ]);
}

if (! is_array($spec)) {
    failHummingbirdStaffContract([HUMMINGBIRD_STAFF_CONTRACT.' must contain a YAML mapping.']);
}

if (($spec['openapi'] ?? null) !== '3.1.0') {
    $errors[] = 'The staff BFF contract must use OpenAPI 3.1.0.';
}

$serverUrl = $spec['servers'][0]['url'] ?? null;
if (! is_string($serverUrl) || ! str_ends_with($serverUrl, HUMMINGBIRD_STAFF_PREFIX)) {
    $errors[] = 'The first server URL must terminate at '.HUMMINGBIRD_STAFF_PREFIX.'.';
}

foreach (hummingbirdStaffLocalReferences($spec) as $reference) {
    if (! hummingbirdStaffReferenceExists($spec, $reference)) {
        $errors[] = "Contract reference {$reference} does not resolve.";
    }
}

$forYouOperation = $spec['paths']['/for-you']['get'] ?? null;
if (! is_array($forYouOperation)) {
    $errors[] = 'GET /for-you must be documented.';
} else {
    if (($forYouOperation['x-data-classification'] ?? null) !== 'phi_minimized') {
        $errors[] = 'GET /for-you must be classified as phi_minimized.';
    }
    if (($forYouOperation['x-offline-class'] ?? null) !== 'NO_CACHE') {
        $errors[] = 'GET /for-you must prohibit offline caching.';
    }
    if (($forYouOperation['x-idempotency'] ?? null) !== 'NOT_APPLICABLE') {
        $errors[] = 'GET /for-you must be declared non-mutating.';
    }
    if (($forYouOperation['responses']['200']['content']['application/json']['schema']['$ref'] ?? null)
        !== '#/components/schemas/ForYouEnvelope') {
        $errors[] = 'GET /for-you must use ForYouEnvelope for its success response.';
    }
    if (($forYouOperation['responses']['503']['$ref'] ?? null)
        !== '#/components/responses/ServiceUnavailable') {
        $errors[] = 'GET /for-you must document safe projection-unavailable behavior.';
    }
}

$forYouDomains = $spec['components']['schemas']['ForYouItem']['properties']['domain']['enum'] ?? null;
if (! is_array($forYouDomains)
    || ! in_array('communications', $forYouDomains, true)
    || in_array('patient_communications', $forYouDomains, true)) {
    $errors[] = 'ForYouItem.domain must use the canonical communications value.';
}

$forYouMetaReference = $spec['components']['schemas']['ForYouEnvelope']['allOf'][1]['properties']['meta']['$ref'] ?? null;
if ($forYouMetaReference !== '#/components/schemas/ForYouRestrictedMeta') {
    $errors[] = 'ForYouEnvelope must use the restricted no-cache metadata schema.';
}
$forYouRestrictedMeta = $spec['components']['schemas']['ForYouRestrictedMeta']['allOf'][1] ?? null;
if (! is_array($forYouRestrictedMeta)
    || ($forYouRestrictedMeta['properties']['classification']['const'] ?? null) !== 'phi_minimized_restricted'
    || ($forYouRestrictedMeta['properties']['offline_cache_allowed']['const'] ?? null) !== false) {
    $errors[] = 'ForYouRestrictedMeta must prohibit offline caching and declare restricted PHI-minimized data.';
}

$expectedOperations = [
    'GET '.HUMMINGBIRD_COMMUNICATION_PREFIX.'/inbox' => [
        'capability' => 'ViewPatientCommunications',
        'gate' => 'viewPatientCommunications',
        'idempotency' => 'NOT_APPLICABLE',
        'success_schema' => 'PatientCommunicationInboxEnvelope',
        'request_schema' => null,
        'expected_statuses' => ['200', '401', '403', '404', '503'],
    ],
    'GET '.HUMMINGBIRD_COMMUNICATION_PREFIX.'/threads/{workItemUuid}' => [
        'capability' => 'ViewPatientCommunications',
        'gate' => 'viewPatientCommunications',
        'idempotency' => 'NOT_APPLICABLE',
        'success_schema' => 'PatientCommunicationThreadEnvelope',
        'request_schema' => null,
        'expected_statuses' => ['200', '401', '403', '404', '503'],
    ],
    'GET '.HUMMINGBIRD_COMMUNICATION_PREFIX.'/threads/{workItemUuid}/route-candidates' => [
        'capability' => 'RespondPatientCommunications',
        'gate' => 'respondPatientCommunications',
        'idempotency' => 'NOT_APPLICABLE',
        'success_schema' => 'PatientCommunicationRouteCandidatesEnvelope',
        'request_schema' => null,
        'expected_statuses' => ['200', '401', '403', '404', '409', '503'],
    ],
    'POST '.HUMMINGBIRD_COMMUNICATION_PREFIX.'/threads/{workItemUuid}/claim' => [
        'capability' => 'RespondPatientCommunications',
        'gate' => 'respondPatientCommunications',
        'idempotency' => 'REQUIRED_UUID_HEADER',
        'success_schema' => 'PatientCommunicationMutationEnvelope',
        'request_schema' => 'ClaimPatientCommunication',
        'expected_statuses' => ['200', '401', '403', '404', '409', '422', '503'],
    ],
    'POST '.HUMMINGBIRD_COMMUNICATION_PREFIX.'/threads/{workItemUuid}/reply' => [
        'capability' => 'RespondPatientCommunications',
        'gate' => 'respondPatientCommunications',
        'idempotency' => 'REQUIRED_UUID_HEADER_AND_CLIENT_MESSAGE_UUID',
        'success_schema' => 'PatientCommunicationMutationEnvelope',
        'request_schema' => 'ReplyPatientCommunication',
        'expected_statuses' => ['200', '401', '403', '404', '409', '422', '503'],
    ],
    'POST '.HUMMINGBIRD_COMMUNICATION_PREFIX.'/threads/{workItemUuid}/close' => [
        'capability' => 'RespondPatientCommunications',
        'gate' => 'respondPatientCommunications',
        'idempotency' => 'REQUIRED_UUID_HEADER',
        'success_schema' => 'PatientCommunicationMutationEnvelope',
        'request_schema' => 'ClosePatientCommunication',
        'expected_statuses' => ['200', '401', '403', '404', '409', '422', '503'],
    ],
    'POST '.HUMMINGBIRD_COMMUNICATION_PREFIX.'/threads/{workItemUuid}/release' => [
        'capability' => 'RespondPatientCommunications',
        'gate' => 'respondPatientCommunications',
        'idempotency' => 'REQUIRED_UUID_HEADER',
        'success_schema' => 'PatientCommunicationMutationEnvelope',
        'request_schema' => 'ReleasePatientCommunication',
        'expected_statuses' => ['200', '401', '403', '404', '409', '422', '503'],
    ],
    'POST '.HUMMINGBIRD_COMMUNICATION_PREFIX.'/threads/{workItemUuid}/reassign' => [
        'capability' => 'RespondPatientCommunications',
        'gate' => 'respondPatientCommunications',
        'idempotency' => 'REQUIRED_UUID_HEADER',
        'success_schema' => 'PatientCommunicationMutationEnvelope',
        'request_schema' => 'ReassignPatientCommunication',
        'expected_statuses' => ['200', '401', '403', '404', '409', '422', '503'],
    ],
    'POST '.HUMMINGBIRD_COMMUNICATION_PREFIX.'/threads/{workItemUuid}/reroute' => [
        'capability' => 'RespondPatientCommunications',
        'gate' => 'respondPatientCommunications',
        'idempotency' => 'REQUIRED_UUID_HEADER',
        'success_schema' => 'PatientCommunicationRerouteMutationEnvelope',
        'request_schema' => 'ReroutePatientCommunication',
        'expected_statuses' => ['200', '401', '403', '404', '409', '422', '503'],
    ],
];

$documentedOperations = [];
$paths = $spec['paths'] ?? [];
if (! is_array($paths)) {
    $errors[] = 'The staff BFF contract paths node must be a mapping.';
    $paths = [];
}

foreach ($paths as $path => $pathItem) {
    if (! is_string($path)
        || ($path !== HUMMINGBIRD_COMMUNICATION_PREFIX
            && ! str_starts_with($path, HUMMINGBIRD_COMMUNICATION_PREFIX.'/'))) {
        continue;
    }
    if (! is_array($pathItem)) {
        $errors[] = "{$path} must be a path-item mapping.";

        continue;
    }

    foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
        if (! array_key_exists($method, $pathItem)) {
            continue;
        }

        $operationKey = strtoupper($method).' '.$path;
        $documentedOperations[$operationKey] = true;
        $operation = $pathItem[$method];
        $expected = $expectedOperations[$operationKey] ?? null;
        if (! is_array($expected)) {
            $errors[] = "Undispositioned patient-communications operation {$operationKey}.";

            continue;
        }
        if (! is_array($operation)) {
            $errors[] = "{$operationKey} must be an operation mapping.";

            continue;
        }

        if (($operation['tags'] ?? null) !== ['patient-communications']) {
            $errors[] = "{$operationKey} must use only the patient-communications tag.";
        }

        $authorization = $operation['x-authorization-capability'] ?? null;
        if (! is_array($authorization)
            || ($authorization['enum_case'] ?? null) !== $expected['capability']
            || ($authorization['gate'] ?? null) !== $expected['gate']) {
            $errors[] = "{$operationKey} authorization metadata does not match its Laravel capability gate.";
        }

        if (($operation['x-data-classification'] ?? null) !== 'phi') {
            $errors[] = "{$operationKey} must classify its data as phi.";
        }
        if (($operation['x-offline-class'] ?? null) !== 'NO_CACHE') {
            $errors[] = "{$operationKey} must prohibit offline caching and writes.";
        }
        if (($operation['x-idempotency'] ?? null) !== $expected['idempotency']) {
            $errors[] = "{$operationKey} has the wrong idempotency declaration.";
        }

        $parameterReferences = hummingbirdStaffParameterReferences($operation);
        $threadPath = str_contains($path, '{workItemUuid}');
        $hasWorkItemParameter = in_array(
            '#/components/parameters/PatientCommunicationWorkItemUuid',
            $parameterReferences,
            true,
        );
        if ($threadPath !== $hasWorkItemParameter) {
            $errors[] = "{$operationKey} work-item path parameter declaration is inconsistent.";
        }

        $isWrite = strtoupper($method) !== 'GET';
        $hasIdempotencyParameter = in_array(
            '#/components/parameters/PatientCommunicationIdempotencyKey',
            $parameterReferences,
            true,
        );
        if ($isWrite !== $hasIdempotencyParameter) {
            $errors[] = "{$operationKey} idempotency-header declaration is inconsistent.";
        }

        $requestReference = $operation['requestBody']['content']['application/json']['schema']['$ref'] ?? null;
        $expectedRequestReference = is_string($expected['request_schema'])
            ? '#/components/schemas/'.$expected['request_schema']
            : null;
        if ($requestReference !== $expectedRequestReference) {
            $errors[] = "{$operationKey} request schema does not match the governed command contract.";
        }
        if ($isWrite && ($operation['requestBody']['required'] ?? null) !== true) {
            $errors[] = "{$operationKey} must require a JSON request body.";
        }

        $responses = $operation['responses'] ?? null;
        if (! is_array($responses)) {
            $errors[] = "{$operationKey} must define responses.";

            continue;
        }

        $actualStatuses = array_map('strval', array_keys($responses));
        sort($actualStatuses);
        $expectedStatuses = $expected['expected_statuses'];
        sort($expectedStatuses);
        if ($actualStatuses !== $expectedStatuses) {
            $errors[] = "{$operationKey} response statuses must be exactly ".implode(', ', $expectedStatuses).'.';
        }

        $successReference = $responses['200']['content']['application/json']['schema']['$ref'] ?? null;
        if ($successReference !== '#/components/schemas/'.$expected['success_schema']) {
            $errors[] = "{$operationKey} success response uses the wrong envelope schema.";
        }

        foreach (array_diff($expectedStatuses, ['200']) as $status) {
            $responseReference = $responses[$status]['$ref'] ?? null;
            $expectedResponseName = match ($status) {
                '401' => 'Unauthorized',
                '403' => 'Forbidden',
                '404' => 'NotFound',
                '409' => 'Conflict',
                '422' => 'InvalidBody',
                '503' => 'ServiceUnavailable',
                default => null,
            };
            if (! is_string($expectedResponseName)
                || $responseReference !== '#/components/responses/'.$expectedResponseName) {
                $errors[] = "{$operationKey} {$status} must use the governed reusable error response.";
            }
        }
    }
}

$expectedDocumented = array_keys($expectedOperations);
$actualDocumented = array_keys($documentedOperations);
sort($expectedDocumented);
sort($actualDocumented);
if ($expectedDocumented !== $actualDocumented) {
    $errors[] = 'The nine governed patient-communications operations are not documented exactly once.';
}

$expectedSchemaRequired = [
    'PatientCommunicationTopic' => ['code', 'label'],
    'PatientCommunicationUnit' => ['id', 'label'],
    'PatientCommunicationPool' => ['label', 'pool_uuid'],
    'PatientCommunicationRoutingActions' => ['can_reassign', 'can_release', 'can_reroute'],
    'PatientCommunicationReleaseReasonOption' => ['code', 'label'],
    'PatientCommunicationReassignReasonOption' => ['code', 'label'],
    'PatientCommunicationRerouteReasonOption' => ['code', 'label'],
    'PatientCommunicationRoutingReasonOptions' => ['reassign', 'release', 'reroute'],
    'PatientCommunicationReassignCandidate' => ['label', 'membership_role', 'membership_uuid'],
    'PatientCommunicationRerouteCandidate' => ['label', 'pool_uuid', 'scope_type', 'unit'],
    'PatientCommunicationRouteCandidatesData' => [
        'actions',
        'reassign_candidates',
        'reason_options',
        'reroute_candidates',
        'thread_version',
        'work_item_uuid',
        'work_item_version',
    ],
    'PatientCommunicationMessage' => [
        'body',
        'delivery_state',
        'message_kind',
        'message_uuid',
        'sender_display_role',
        'sent_at',
        'visibility',
    ],
    'PatientCommunicationWorkItem' => [
        'assigned_to_me',
        'closed_at',
        'due_at',
        'escalate_at',
        'is_escalation_due',
        'is_response_due',
        'last_message_at',
        'ownership_state',
        'patient_context_ref',
        'pool',
        'status',
        'thread_uuid',
        'thread_version',
        'topic',
        'unit',
        'work_item_uuid',
        'work_item_version',
    ],
    'PatientCommunicationInboxData' => ['count', 'items'],
    'PatientCommunicationMutationData' => ['event_uuid', 'message', 'replayed', 'work_item'],
    'PatientCommunicationRerouteCommittedData' => ['event_uuid', 'message', 'replayed', 'work_item'],
    'PatientCommunicationRerouteReplayReceipt' => ['event_uuid', 'message', 'replayed', 'work_item'],
    'PatientCommunicationWebRestrictedMeta' => ['as_of', 'classification', 'offline_writes_allowed'],
    'PatientCommunicationInboxEnvelope' => ['data', 'links', 'meta'],
    'PatientCommunicationThreadEnvelope' => ['data', 'links', 'meta'],
    'PatientCommunicationRouteCandidatesEnvelope' => ['data', 'links', 'meta'],
    'PatientCommunicationMutationEnvelope' => ['data', 'links', 'meta'],
    'PatientCommunicationRerouteMutationEnvelope' => ['data', 'links', 'meta'],
    'PatientCommunicationWebRerouteMutationEnvelope' => ['data', 'meta'],
    'ClaimPatientCommunication' => ['thread_version', 'work_item_version'],
    'ReplyPatientCommunication' => ['client_message_uuid', 'message', 'thread_version', 'work_item_version'],
    'ClosePatientCommunication' => ['reason_code', 'thread_version', 'work_item_version'],
    'ReleasePatientCommunication' => ['reason_code', 'thread_version', 'work_item_version'],
    'ReassignPatientCommunication' => [
        'reason_code',
        'target_membership_uuid',
        'thread_version',
        'work_item_version',
    ],
    'ReroutePatientCommunication' => [
        'reason_code',
        'target_pool_uuid',
        'thread_version',
        'work_item_version',
    ],
];

foreach ($expectedSchemaRequired as $schemaName => $expectedRequired) {
    sort($expectedRequired);
    $schema = $spec['components']['schemas'][$schemaName] ?? null;
    if (! is_array($schema)) {
        $errors[] = "Missing governed schema {$schemaName}.";

        continue;
    }
    if (hummingbirdStaffRequiredFields($spec, $schemaName) !== $expectedRequired) {
        $errors[] = "{$schemaName} has drifted required fields.";
    }
    if (($schema['additionalProperties'] ?? null) !== false) {
        $errors[] = "{$schemaName} must reject undocumented properties.";
    }
}

$rerouteOperation = $spec['paths'][HUMMINGBIRD_COMMUNICATION_PREFIX.'/threads/{workItemUuid}/reroute']['post'] ?? null;
$webEquivalent = is_array($rerouteOperation) ? ($rerouteOperation['x-web-equivalent'] ?? null) : null;
if (! is_array($webEquivalent)
    || ($webEquivalent['path'] ?? null) !== '/patient-communications/threads/{workItemUuid}/reroute'
    || ($webEquivalent['success_schema'] ?? null) !== 'PatientCommunicationWebRerouteMutationEnvelope'
) {
    $errors[] = 'Reroute must bind the mobile contract to its content-minimized web response schema.';
}

$rerouteVariants = $spec['components']['schemas']['PatientCommunicationRerouteMutationData']['oneOf'] ?? null;
$expectedRerouteVariants = [
    ['$ref' => '#/components/schemas/PatientCommunicationRerouteCommittedData'],
    ['$ref' => '#/components/schemas/PatientCommunicationRerouteReplayReceipt'],
];
if ($rerouteVariants !== $expectedRerouteVariants) {
    $errors[] = 'Reroute success data must be the committed projection or minimal immutable replay receipt union.';
}

$committedReroute = $spec['components']['schemas']['PatientCommunicationRerouteCommittedData']['properties'] ?? null;
$replayReceipt = $spec['components']['schemas']['PatientCommunicationRerouteReplayReceipt']['properties'] ?? null;
if (! is_array($committedReroute)
    || ($committedReroute['work_item']['$ref'] ?? null) !== '#/components/schemas/PatientCommunicationWorkItem'
    || ($committedReroute['message']['type'] ?? null) !== 'null'
    || ($committedReroute['event_uuid']['format'] ?? null) !== 'uuid'
    || ($committedReroute['replayed']['const'] ?? null) !== false
) {
    $errors[] = 'The first reroute success must expose a current work item, null message, event UUID, and replayed=false.';
}
if (! is_array($replayReceipt)
    || ($replayReceipt['work_item']['type'] ?? null) !== 'null'
    || ($replayReceipt['message']['type'] ?? null) !== 'null'
    || ($replayReceipt['event_uuid']['format'] ?? null) !== 'uuid'
    || ($replayReceipt['replayed']['const'] ?? null) !== true
) {
    $errors[] = 'An exact reroute replay must expose only null projection fields, the immutable event UUID, and replayed=true.';
}

foreach ([
    'PatientCommunicationRerouteMutationEnvelope' => ['data', '#/components/schemas/PatientCommunicationRerouteMutationData'],
    'PatientCommunicationWebRerouteMutationEnvelope' => ['data', '#/components/schemas/PatientCommunicationRerouteMutationData'],
] as $schemaName => [$property, $reference]) {
    if (($spec['components']['schemas'][$schemaName]['properties'][$property]['$ref'] ?? null) !== $reference) {
        $errors[] = "{$schemaName} must use the governed reroute data union.";
    }
}

$messageBody = $spec['components']['schemas']['PatientCommunicationMessage']['properties']['body']['oneOf'] ?? [];
$messageBodyMaximum = null;
foreach (is_array($messageBody) ? $messageBody : [] as $variant) {
    if (is_array($variant) && ($variant['type'] ?? null) === 'string') {
        $messageBodyMaximum = $variant['maxLength'] ?? null;
    }
}
if ($messageBodyMaximum !== 4000) {
    $errors[] = 'PatientCommunicationMessage.body must be bounded to 4000 characters.';
}
if (($spec['components']['schemas']['PatientCommunicationWorkItem']['properties']['messages']['maxItems'] ?? null) !== 250) {
    $errors[] = 'Thread detail history must remain bounded to 250 messages.';
}
if (($spec['components']['schemas']['PatientCommunicationInboxData']['properties']['items']['maxItems'] ?? null) !== 200) {
    $errors[] = 'The staff inbox must remain bounded to 200 content-free work items.';
}

$routeCandidateSchema = $spec['components']['schemas']['PatientCommunicationRouteCandidatesData']['properties'] ?? [];
foreach (['reassign_candidates', 'reroute_candidates'] as $candidateKey) {
    if (($routeCandidateSchema[$candidateKey]['maxItems'] ?? null) !== 50) {
        $errors[] = "{$candidateKey} must remain bounded to 50 candidates.";
    }
}
$reasonOptionSchema = $spec['components']['schemas']['PatientCommunicationRoutingReasonOptions']['properties'] ?? [];
foreach (['release', 'reassign', 'reroute'] as $operation) {
    if (($reasonOptionSchema[$operation]['maxItems'] ?? null) !== 12) {
        $errors[] = "{$operation} reason options must remain bounded to 12.";
    }
}

$expectedReasonCodes = [
    'release' => ['return_to_team', 'shift_handoff', 'responder_unavailable', 'incorrect_assignment'],
    'reassign' => ['supervisor_assignment', 'shift_handoff', 'coverage_change', 'workload_balance'],
    'reroute' => ['wrong_team', 'unit_transfer', 'service_change', 'specialty_needed'],
];
$reasonSchemas = [
    'release' => ['ReleasePatientCommunication', 'PatientCommunicationReleaseReasonOption'],
    'reassign' => ['ReassignPatientCommunication', 'PatientCommunicationReassignReasonOption'],
    'reroute' => ['ReroutePatientCommunication', 'PatientCommunicationRerouteReasonOption'],
];
foreach ($reasonSchemas as $operation => [$requestSchema, $optionSchema]) {
    foreach ([
        $spec['components']['schemas'][$requestSchema]['properties']['reason_code']['enum'] ?? null,
        $spec['components']['schemas'][$optionSchema]['properties']['code']['enum'] ?? null,
    ] as $actualCodes) {
        if ($actualCodes !== $expectedReasonCodes[$operation]) {
            $errors[] = "{$operation} reason codes have drifted from the server-owned allowlist.";
        }
    }
}

$canonicalUuidPattern = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';
if (($spec['components']['parameters']['PatientCommunicationIdempotencyKey']['schema']['pattern'] ?? null)
    !== $canonicalUuidPattern) {
    $errors[] = 'Patient communication mutation idempotency keys must be canonical lowercase UUIDs.';
}
foreach ([
    ['ReassignPatientCommunication', 'target_membership_uuid'],
    ['ReroutePatientCommunication', 'target_pool_uuid'],
] as [$schemaName, $propertyName]) {
    if (($spec['components']['schemas'][$schemaName]['properties'][$propertyName]['pattern'] ?? null)
        !== $canonicalUuidPattern) {
        $errors[] = "{$schemaName}.{$propertyName} must be a canonical lowercase UUID.";
    }
}

$meData = $spec['components']['schemas']['MeEnvelope']['allOf'][1]['properties']['data'] ?? null;
$meCapabilityProperties = is_array($meData)
    ? ($meData['properties']['can']['properties'] ?? null)
    : null;
$meCapabilityRequired = is_array($meData)
    ? ($meData['properties']['can']['required'] ?? null)
    : null;
if (! is_array($meCapabilityProperties)
    || ($meCapabilityProperties['view_patient_communications']['type'] ?? null) !== 'boolean'
    || ($meCapabilityProperties['respond_patient_communications']['type'] ?? null) !== 'boolean'
    || ! is_array($meCapabilityRequired)) {
    $errors[] = 'MeEnvelope must expose server-derived patient-communications capability booleans.';
} else {
    sort($meCapabilityRequired);
    if ($meCapabilityRequired !== ['respond_patient_communications', 'view_patient_communications']) {
        $errors[] = 'MeEnvelope must require both patient-communications capability booleans.';
    }
}

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$registeredOperations = [];
foreach (Route::getRoutes() as $route) {
    if (! $route instanceof IlluminateRoute
        || ($route->uri() !== 'api/mobile/v1/patient-communications'
            && ! str_starts_with($route->uri(), 'api/mobile/v1/patient-communications/'))) {
        continue;
    }

    $middleware = $route->gatherMiddleware();
    $requiredMiddleware = [
        'auth:sanctum',
        'staff.realm',
        CheckForAnyAbility::class.':mobile:read',
        'throttle:mobile-api',
        'patient.staff-messaging',
        'can:viewPatientCommunications',
    ];
    foreach ($requiredMiddleware as $required) {
        if (! in_array($required, $middleware, true)) {
            $errors[] = 'Registered route '.$route->uri()." is missing {$required}.";
        }
    }

    $relativePath = substr('/'.$route->uri(), strlen(HUMMINGBIRD_STAFF_PREFIX));
    foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
        $operationKey = $method.' '.$relativePath;
        $registeredOperations[$operationKey] = true;
        $requiresResponseAuthority = ($expectedOperations[$operationKey]['capability'] ?? null)
            === 'RespondPatientCommunications';
        foreach ([
            CheckForAnyAbility::class.':mobile:act',
            'can:respondPatientCommunications',
        ] as $writeMiddleware) {
            if ($requiresResponseAuthority !== in_array($writeMiddleware, $middleware, true)) {
                $errors[] = "{$operationKey} has inconsistent write authorization middleware {$writeMiddleware}.";
            }
        }
    }

    if (str_contains($route->uri(), '{workItemUuid}')) {
        $workItemConstraint = $route->wheres['workItemUuid'] ?? null;
        if (! is_string($workItemConstraint)
            || ! str_contains($workItemConstraint, '{8}')
            || substr_count($workItemConstraint, '{4}') !== 3
            || ! str_contains($workItemConstraint, '{12}')
            || ! str_contains($workItemConstraint, 'a-fA-F')) {
            $errors[] = 'Registered route '.$route->uri().' must constrain workItemUuid as a UUID.';
        }
    }
}

$expectedRegistered = array_keys($expectedOperations);
$actualRegistered = array_keys($registeredOperations);
sort($expectedRegistered);
sort($actualRegistered);
if ($expectedRegistered !== $actualRegistered) {
    $errors[] = 'Laravel and OpenAPI patient-communications operation inventories differ.';
}

if ($errors !== []) {
    failHummingbirdStaffContract(array_values(array_unique($errors)));
}

fwrite(
    STDOUT,
    sprintf(
        "Hummingbird staff contract verified: %d accountable patient-communications operations match Laravel, authorization, idempotency, bounded schemas, and offline policy.\n",
        count($expectedOperations),
    ),
);
