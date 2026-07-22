<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__).'/vendor/autoload.php';

const PATIENT_DISCLOSURE_MATRIX = 'docs/hummingbird/patient-disclosure-matrix.v1.yaml';

/** @param list<string> $errors */
function disclosureFail(array $errors): never
{
    fwrite(STDERR, "Hummingbird patient disclosure verification failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }

    exit(1);
}

/** @return list<string> */
function disclosureStringList(mixed $value, string $field, string $id, array &$errors): array
{
    if (! is_array($value) || ! array_is_list($value) || $value === []) {
        $errors[] = "{$id}.{$field} must be a non-empty list.";

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

    if (count($strings) !== count(array_unique($strings))) {
        $errors[] = "{$id}.{$field} contains duplicate values.";
    }

    return $strings;
}

$root = dirname(__DIR__);
$errors = [];

try {
    $matrix = Yaml::parseFile($root.'/'.PATIENT_DISCLOSURE_MATRIX);
} catch (ParseException $exception) {
    disclosureFail([PATIENT_DISCLOSURE_MATRIX.' is not valid YAML: '.$exception->getMessage()]);
}

if (! is_array($matrix)) {
    disclosureFail([PATIENT_DISCLOSURE_MATRIX.' must contain a YAML mapping.']);
}

if (($matrix['version'] ?? null) !== 1) {
    $errors[] = 'Matrix version must be the integer 1.';
}

if (($matrix['governance_status'] ?? null) !== 'draft_requires_multidisciplinary_approval') {
    $errors[] = 'The initial matrix must remain visibly draft until multidisciplinary approval is recorded.';
}

$owners = $matrix['owners'] ?? null;
if (! is_array($owners)) {
    $errors[] = 'owners must be a mapping.';
} else {
    foreach (['product', 'engineering'] as $ownerField) {
        if (! is_string($owners[$ownerField] ?? null) || trim((string) $owners[$ownerField]) === '') {
            $errors[] = "owners.{$ownerField} must be a non-empty accountable group.";
        }
    }
    disclosureStringList($owners['required_approvers'] ?? null, 'required_approvers', 'owners', $errors);
}

$relationships = $matrix['relationship_classes'] ?? null;
if (! is_array($relationships) || $relationships === []) {
    $errors[] = 'relationship_classes must be a non-empty mapping.';
    $relationships = [];
}

$globalProhibitions = disclosureStringList(
    $matrix['global_prohibitions'] ?? null,
    'global_prohibitions',
    'matrix',
    $errors,
);

$defaults = $matrix['policy_defaults'] ?? null;
foreach ([
    'external_identifier',
    'deny_behavior',
    'provenance_required',
    'source_freshness_required',
    'policy_version_required',
    'release_failure_behavior',
    'offline_default',
    'notification_default',
] as $requiredDefault) {
    if (! is_array($defaults) || ! array_key_exists($requiredDefault, $defaults)) {
        $errors[] = "policy_defaults.{$requiredDefault} is required.";
    }
}

$disclosures = $matrix['disclosures'] ?? null;
if (! is_array($disclosures) || ! array_is_list($disclosures) || $disclosures === []) {
    disclosureFail(array_merge($errors, ['disclosures must be a non-empty list.']));
}

$requiredText = [
    'display_label',
    'patient_explanation',
    'release_rule',
    'sensitivity',
    'freshness_expectation',
    'uncertainty_behavior',
    'correction_retraction',
    'translation_owner',
    'offline_policy',
    'notification_policy',
    'approval_status',
];
$requiredLists = [
    'source_systems',
    'source_identifiers',
    'permitted_relationships',
    'allowed_fields',
    'prohibited_fields',
];
$ids = [];

foreach ($disclosures as $index => $disclosure) {
    if (! is_array($disclosure)) {
        $errors[] = "disclosures[{$index}] must be a mapping.";

        continue;
    }

    $id = $disclosure['id'] ?? "disclosures[{$index}]";
    if (! is_string($id) || preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $id) !== 1) {
        $errors[] = "disclosures[{$index}].id must be a stable lowercase identifier.";
        $id = "disclosures[{$index}]";
    } elseif (isset($ids[$id])) {
        $errors[] = "Duplicate disclosure id {$id}.";
    } else {
        $ids[$id] = true;
    }

    foreach ($requiredText as $field) {
        if (! is_string($disclosure[$field] ?? null) || trim((string) $disclosure[$field]) === '') {
            $errors[] = "{$id}.{$field} must be a non-empty string.";
        }
    }

    $lists = [];
    foreach ($requiredLists as $field) {
        $lists[$field] = disclosureStringList($disclosure[$field] ?? null, $field, $id, $errors);
    }

    foreach ($lists['permitted_relationships'] as $relationship) {
        if (! array_key_exists($relationship, $relationships)) {
            $errors[] = "{$id}.permitted_relationships references unknown relationship {$relationship}.";
        }
    }

    $overlap = array_intersect($lists['allowed_fields'], $lists['prohibited_fields']);
    if ($overlap !== []) {
        $errors[] = "{$id} allows and prohibits the same field(s): ".implode(', ', $overlap).'.';
    }

    if (! str_contains((string) ($disclosure['offline_policy'] ?? ''), 'no_cache')
        && ! str_contains((string) ($disclosure['offline_policy'] ?? ''), 'encrypted')) {
        $errors[] = "{$id}.offline_policy must explicitly require no cache or encrypted storage.";
    }

    if (($disclosure['approval_status'] ?? null) === 'approved') {
        $errors[] = "{$id} cannot be approved while the matrix governance status is draft.";
    }
}

foreach (['other_patient_data', 'staff_internal_note', 'unreleased_result_or_document'] as $criticalProhibition) {
    if (! in_array($criticalProhibition, $globalProhibitions, true)) {
        $errors[] = "Critical global prohibition {$criticalProhibition} is missing.";
    }
}

if ($errors !== []) {
    disclosureFail($errors);
}

fwrite(
    STDOUT,
    sprintf(
        "Hummingbird patient disclosure matrix verified: %d draft disclosure classes, %d global prohibitions, %d relationship classes.\n",
        count($disclosures),
        count($globalProhibitions),
        count($relationships),
    ),
);
