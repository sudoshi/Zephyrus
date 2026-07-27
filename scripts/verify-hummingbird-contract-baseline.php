<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__).'/vendor/autoload.php';

/**
 * Breaking-change checker for the Hummingbird OpenAPI contracts (plan §6.2).
 *
 * The two specs are the published interface. This verifier reads them directly
 * (no Laravel bootstrap), extracts every operation, and diffs against an
 * append-only baseline lock. A baselined operation may only disappear with a
 * record in contract-breaking-changes.v1.yaml; a new operation must be appended
 * to the lock. This makes a silent removal/rename of a contract operation a
 * hard CI failure so native clients never break unannounced.
 */
const SPECS = [
    'docs/hummingbird/api-contract/hummingbird-bff.v1.yaml',
    'docs/hummingbird/api-contract/hummingbird-patient.v1.yaml',
];
const LOCK_PATH = 'docs/hummingbird/api-contract/contract-operations.lock';
const CHANGES_PATH = 'docs/hummingbird/api-contract/contract-breaking-changes.v1.yaml';
const HTTP_METHODS = ['get', 'put', 'post', 'patch', 'delete'];

/** @param list<string> $errors */
function failBaseline(array $errors): never
{
    fwrite(STDERR, "Hummingbird contract baseline verification failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

$root = dirname(__DIR__);
$errors = [];

/** @return list<string> */
function currentOperations(string $root, array &$errors): array
{
    $ops = [];
    foreach (SPECS as $relative) {
        $file = $root.'/'.$relative;
        if (! is_file($file)) {
            $errors[] = "Missing contract spec {$relative}.";

            continue;
        }
        try {
            $spec = Yaml::parseFile($file);
        } catch (ParseException $exception) {
            $errors[] = "{$relative} is not valid YAML: ".$exception->getMessage();

            continue;
        }
        $prefix = rtrim((string) (parse_url($spec['servers'][0]['url'] ?? '', PHP_URL_PATH) ?? ''), '/');
        foreach (($spec['paths'] ?? []) as $path => $item) {
            if (! is_string($path) || ! is_array($item)) {
                continue;
            }
            foreach (HTTP_METHODS as $method) {
                if (! is_array($item[$method] ?? null)) {
                    continue;
                }
                $op = strtoupper($method).' '.$prefix.$path;
                $operationId = $item[$method]['operationId'] ?? null;
                if (is_string($operationId) && $operationId !== '') {
                    $op .= ' '.$operationId;
                }
                $ops[] = $op;
            }
        }
    }
    sort($ops);

    return $ops;
}

$current = currentOperations($root, $errors);
$currentSet = array_fill_keys($current, true);

// Load the append-only baseline lock.
$lockFile = $root.'/'.LOCK_PATH;
$baseline = [];
if (! is_file($lockFile)) {
    $errors[] = 'Missing '.LOCK_PATH.' (append-only contract operations baseline).';
} else {
    foreach (file($lockFile, FILE_IGNORE_NEW_LINES) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (isset($baseline[$trimmed])) {
            $errors[] = "contract-operations.lock lists '{$trimmed}' more than once.";
        }
        $baseline[$trimmed] = true;
    }
}

// Load breaking-change records.
$changesFile = $root.'/'.CHANGES_PATH;
$recordedRemovals = [];
if (! is_file($changesFile)) {
    $errors[] = 'Missing '.CHANGES_PATH.'.';
} else {
    try {
        $doc = Yaml::parseFile($changesFile);
    } catch (ParseException $exception) {
        $doc = null;
        $errors[] = 'contract-breaking-changes.v1.yaml is not valid YAML: '.$exception->getMessage();
    }
    if (is_array($doc)) {
        if (($doc['version'] ?? null) !== 1) {
            $errors[] = 'contract-breaking-changes.v1.yaml version must be the integer 1.';
        }
        $changes = $doc['changes'] ?? null;
        if (! is_array($changes) || ! array_is_list($changes)) {
            $errors[] = 'contract-breaking-changes.v1.yaml changes must be a list.';
        } else {
            foreach ($changes as $index => $record) {
                $label = "changes[{$index}]";
                if (! is_array($record)) {
                    $errors[] = "{$label} must be a mapping.";

                    continue;
                }
                $operation = $record['operation'] ?? null;
                if (! is_string($operation) || trim($operation) === '') {
                    $errors[] = "{$label}.operation must be the baselined operation line.";

                    continue;
                }
                $recordedRemovals[$operation] = true;
                if (! in_array($record['kind'] ?? null, ['removed', 'renamed'], true)) {
                    $errors[] = "{$operation} kind must be 'removed' or 'renamed'.";
                }
                if (! is_string($record['changed_on'] ?? null)
                    || preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($record['changed_on'] ?? '')) !== 1
                ) {
                    $errors[] = "{$operation} changed_on must be a YYYY-MM-DD date.";
                }
                if (! is_string($record['reason'] ?? null) || trim((string) $record['reason']) === '') {
                    $errors[] = "{$operation} reason must be a non-empty string.";
                }
            }
        }
    }
}

// Enforcement: every current op is baselined; every baselined op is current or
// has a breaking-change record.
foreach ($current as $op) {
    if (! isset($baseline[$op])) {
        $errors[] = "Contract operation '{$op}' is not in contract-operations.lock; append it (keep sorted) when adding an operation.";
    }
}
foreach (array_keys($baseline) as $op) {
    if (! isset($currentSet[$op]) && ! isset($recordedRemovals[$op])) {
        $errors[] = "Contract operation '{$op}' was removed or renamed without a record in contract-breaking-changes.v1.yaml.";
    }
}

if ($errors !== []) {
    failBaseline($errors);
}

fwrite(
    STDOUT,
    sprintf(
        "Hummingbird contract baseline verified: %d published operations match the append-only lock with %d breaking-change records.\n",
        count($current),
        count($recordedRemovals),
    ),
);
