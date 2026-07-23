<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: verify-github-ci.php <workflow-runs.json> <commit>\n");
    exit(64);
}

[$script, $path, $commit] = $argv;

if (! preg_match('/\A[0-9a-f]{40}\z/', $commit)) {
    fwrite(STDERR, "Error: commit must be a full 40-character SHA.\n");
    exit(64);
}

$contents = file_get_contents($path);
if ($contents === false) {
    fwrite(STDERR, "Error: unable to read the GitHub workflow response.\n");
    exit(1);
}

try {
    $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, "Error: GitHub returned invalid JSON.\n");
    exit(1);
}

$runs = is_array($payload['workflow_runs'] ?? null) ? $payload['workflow_runs'] : [];
$matchingRuns = array_values(array_filter(
    $runs,
    static fn (mixed $run): bool => is_array($run)
        && ($run['head_sha'] ?? null) === $commit
        && ($run['head_branch'] ?? null) === 'main'
        && ($run['event'] ?? null) === 'push',
));

if ($matchingRuns === []) {
    fwrite(STDERR, "Error: no main-branch push CI run exists for {$commit}.\n");
    exit(1);
}

usort(
    $matchingRuns,
    static fn (array $left, array $right): int => strcmp(
        (string) ($right['created_at'] ?? ''),
        (string) ($left['created_at'] ?? ''),
    ),
);

$run = $matchingRuns[0];
$status = (string) ($run['status'] ?? 'unknown');
$conclusion = (string) ($run['conclusion'] ?? '');
$url = (string) ($run['html_url'] ?? '');

if ($status === 'completed' && $conclusion === 'success') {
    echo "GitHub CI passed for {$commit}";
    echo $url !== '' ? " ({$url})\n" : "\n";
    exit(0);
}

if (in_array($status, ['queued', 'in_progress', 'pending', 'requested', 'waiting'], true)) {
    fwrite(STDERR, "Error: GitHub CI is {$status} for {$commit}");
    fwrite(STDERR, $url !== '' ? " ({$url})\n" : "\n");
    exit(75);
}

fwrite(
    STDERR,
    "Error: GitHub CI did not pass for {$commit}; status={$status}, conclusion="
        .($conclusion !== '' ? $conclusion : 'none')
        .($url !== '' ? " ({$url})" : '')
        ."\n",
);
exit(1);
