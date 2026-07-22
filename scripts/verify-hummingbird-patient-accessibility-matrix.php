<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__).'/vendor/autoload.php';

const PATIENT_ACCESSIBILITY_MATRIX = 'docs/hummingbird/patient-accessibility-acceptance-matrix.v1.yaml';

/** @param list<string> $errors */
function accessibilityMatrixFail(array $errors): never
{
    fwrite(STDERR, "Hummingbird patient accessibility-matrix verification failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }

    exit(1);
}

/** @return list<string> */
function accessibilityMatrixStringList(mixed $value, string $field, string $subject, array &$errors, bool $allowEmpty = false): array
{
    if (! is_array($value) || ! array_is_list($value) || (! $allowEmpty && $value === [])) {
        $errors[] = "{$subject}.{$field} must be ".($allowEmpty ? 'a list' : 'a non-empty list').'.';

        return [];
    }

    $values = [];
    foreach ($value as $index => $item) {
        if (! is_string($item) || trim($item) === '') {
            $errors[] = "{$subject}.{$field}[{$index}] must be a non-empty string.";

            continue;
        }
        $values[] = $item;
    }

    if (count($values) !== count(array_unique($values))) {
        $errors[] = "{$subject}.{$field} contains duplicate values.";
    }

    return $values;
}

/** @param array<string, mixed> $matrix */
function accessibilityMatrixRequiredText(array $matrix, string $field, string $subject, array &$errors): void
{
    if (! is_string($matrix[$field] ?? null) || trim((string) $matrix[$field]) === '') {
        $errors[] = "{$subject}.{$field} must be a non-empty string.";
    }
}

$root = dirname(__DIR__);
$matrixPath = $root.'/'.PATIENT_ACCESSIBILITY_MATRIX;
$errors = [];

$matrixSource = file_get_contents($matrixPath);
if ($matrixSource === false) {
    accessibilityMatrixFail(['Unable to read '.PATIENT_ACCESSIBILITY_MATRIX.'.']);
}

$asOfMatch = [];
$hasCanonicalAsOf = preg_match('/^as_of:\s*(\d{4}-\d{2}-\d{2})\s*$/m', $matrixSource, $asOfMatch) === 1;
$asOfDate = $hasCanonicalAsOf
    ? DateTimeImmutable::createFromFormat('!Y-m-d', $asOfMatch[1])
    : false;
if (! $hasCanonicalAsOf || $asOfDate === false || $asOfDate->format('Y-m-d') !== $asOfMatch[1]) {
    $errors[] = 'as_of must be an ISO-8601 calendar date.';
}

try {
    $matrix = Yaml::parse($matrixSource);
} catch (ParseException $exception) {
    accessibilityMatrixFail([PATIENT_ACCESSIBILITY_MATRIX.' is not valid YAML: '.$exception->getMessage()]);
}

if (! is_array($matrix)) {
    accessibilityMatrixFail([PATIENT_ACCESSIBILITY_MATRIX.' must contain a YAML mapping.']);
}

if (($matrix['version'] ?? null) !== 1) {
    $errors[] = 'Matrix version must be the integer 1.';
}

if (($matrix['governance_status'] ?? null) !== 'draft_requires_multidisciplinary_approval') {
    $errors[] = 'The matrix must remain visibly draft until multidisciplinary approval is recorded.';
}

if (($matrix['release_status'] ?? null) !== 'not_pilot_ready') {
    $errors[] = 'The matrix must not claim pilot readiness before human validation is complete.';
}

$baseline = $matrix['baseline'] ?? null;
if (! is_array($baseline)) {
    $errors[] = 'baseline must be a mapping.';
} else {
    foreach (['wcag_version', 'conformance_target', 'ios_guidance', 'android_guidance', 'safety_boundary'] as $field) {
        accessibilityMatrixRequiredText($baseline, $field, 'baseline', $errors);
    }
    if (($baseline['wcag_version'] ?? null) !== '2.2' || ($baseline['conformance_target'] ?? null) !== 'AA') {
        $errors[] = 'baseline must name WCAG 2.2 AA as the patient product acceptance target.';
    }
}

$owners = $matrix['owners'] ?? null;
if (! is_array($owners)) {
    $errors[] = 'owners must be a mapping.';
} else {
    foreach (['product', 'engineering'] as $field) {
        accessibilityMatrixRequiredText($owners, $field, 'owners', $errors);
    }
    accessibilityMatrixStringList($owners['required_approvers'] ?? null, 'required_approvers', 'owners', $errors);
}

$criteria = $matrix['criteria'] ?? null;
if (! is_array($criteria) || ! array_is_list($criteria) || $criteria === []) {
    accessibilityMatrixFail(array_merge($errors, ['criteria must be a non-empty list.']));
}

$requiredCriteria = [
    'patient_accessibility.text_scaling',
    'patient_accessibility.decorative_scenery',
    'patient_accessibility.high_contrast_and_status_redundancy',
    'patient_accessibility.reduced_motion',
    'patient_accessibility.urgent_help_and_nonurgent_messaging',
    'patient_accessibility.loading_empty_and_error_recovery',
    'patient_accessibility.privacy_cover',
    'patient_accessibility.screen_reader_semantics_and_focus',
    'patient_accessibility.target_size_and_precision_independence',
    'patient_accessibility.cognitive_readability_and_error_prevention',
    'patient_accessibility.audio_video_education_alternatives',
    'patient_accessibility.language_access_and_interpreter',
];
$validStatuses = [
    'automated_evidence_exists',
    'manual_validation_required',
    'not_started',
];
$validRisks = ['medium', 'high', 'critical'];
$validPlatforms = ['ios', 'android'];
$ids = [];
$statusCounts = array_fill_keys($validStatuses, 0);

foreach ($criteria as $index => $criterion) {
    if (! is_array($criterion)) {
        $errors[] = "criteria[{$index}] must be a mapping.";

        continue;
    }

    $id = $criterion['id'] ?? "criteria[{$index}]";
    if (! is_string($id) || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $id) !== 1) {
        $errors[] = "criteria[{$index}].id must be a stable dotted lowercase identifier.";
        $id = "criteria[{$index}]";
    } elseif (isset($ids[$id])) {
        $errors[] = "Duplicate criterion id {$id}.";
    } else {
        $ids[$id] = true;
    }

    if (! in_array($criterion['risk'] ?? null, $validRisks, true)) {
        $errors[] = "{$id}.risk must be one of: ".implode(', ', $validRisks).'.';
    }

    $platforms = accessibilityMatrixStringList($criterion['applies_to'] ?? null, 'applies_to', $id, $errors);
    foreach ($platforms as $platform) {
        if (! in_array($platform, $validPlatforms, true)) {
            $errors[] = "{$id}.applies_to references unsupported platform {$platform}.";
        }
    }
    if (count($platforms) !== count($validPlatforms) || array_diff($validPlatforms, $platforms) !== []) {
        $errors[] = "{$id}.applies_to must cover both ios and android.";
    }

    accessibilityMatrixStringList($criterion['standards'] ?? null, 'standards', $id, $errors);
    foreach (['product_obligation', 'release_boundary'] as $field) {
        accessibilityMatrixRequiredText($criterion, $field, $id, $errors);
    }
    accessibilityMatrixStringList($criterion['human_validation_required'] ?? null, 'human_validation_required', $id, $errors);

    $status = $criterion['automation_status'] ?? null;
    if (! in_array($status, $validStatuses, true)) {
        $errors[] = "{$id}.automation_status must be one of: ".implode(', ', $validStatuses).'.';
    } else {
        $statusCounts[$status]++;
    }

    $evidence = accessibilityMatrixStringList(
        $criterion['automated_evidence'] ?? null,
        'automated_evidence',
        $id,
        $errors,
        true,
    );
    if ($status === 'automated_evidence_exists' && $evidence === []) {
        $errors[] = "{$id} claims automated evidence but lists none.";
    }
    if ($status !== 'automated_evidence_exists' && $evidence !== []) {
        $errors[] = "{$id} may list automated evidence only when automation_status is automated_evidence_exists.";
    }
    foreach ($evidence as $path) {
        if (str_contains($path, '..') || ! is_file($root.'/'.$path)) {
            $errors[] = "{$id}.automated_evidence references missing or unsafe repository file {$path}.";
        }
    }
}

foreach ($requiredCriteria as $id) {
    if (! isset($ids[$id])) {
        $errors[] = "Required accessibility criterion {$id} is missing.";
    }
}

if ($statusCounts['manual_validation_required'] === 0 || $statusCounts['not_started'] === 0) {
    $errors[] = 'The draft matrix must visibly retain both human-validation and unimplemented accessibility/language-access work.';
}

if ($errors !== []) {
    accessibilityMatrixFail($errors);
}

fwrite(
    STDOUT,
    sprintf(
        "Hummingbird patient accessibility matrix verified: %d criteria (%d automated-evidence, %d human-validation, %d not-started); draft and not pilot-ready.\n",
        count($criteria),
        $statusCounts['automated_evidence_exists'],
        $statusCounts['manual_validation_required'],
        $statusCounts['not_started'],
    ),
);
