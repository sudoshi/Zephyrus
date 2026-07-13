#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$policyPath = $root.'/deploy/security/edge-policy.json';
$apachePath = $root.'/deploy/apache/zephyrus-edge-security.conf';
$partnerExamplePath = $root.'/deploy/apache/zephyrus-partner-ingress.conf.example';
$arguments = array_slice($argv, 1);

if ($arguments === [] || in_array('--help', $arguments, true)) {
    fwrite(STDERR, "Usage: php scripts/security/verify-edge-security.php --contract [--apache] [--live=https://host]\n");
    exit($arguments === [] ? 64 : 0);
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$policyJson = file_get_contents($policyPath);
$assert(is_string($policyJson), 'The edge policy is missing or unreadable.');
$policy = is_string($policyJson) ? json_decode($policyJson, true) : null;
$assert(is_array($policy), 'The edge policy is not valid JSON.');

if (is_array($policy)) {
    $assert(($policy['policy_version'] ?? null) === '1.0.0', 'The edge policy version is not recognized.');
    $assert(($policy['waf']['mode'] ?? null) === 'blocking', 'The WAF policy must operate in blocking mode.');
    $assert(($policy['waf']['ruleset'] ?? null) === 'OWASP Core Rule Set', 'OWASP CRS must be the declared WAF ruleset.');
    $assert(($policy['release_rules']['critical_or_high_findings_allowed'] ?? true) === false, 'Critical/high findings must block release.');
    $assert(($policy['release_rules']['production_phi_allowed_without_live_edge_verification'] ?? true) === false, 'PHI must require live edge verification.');
    $assert(count($policy['machine_ingress'] ?? []) >= 2, 'Every machine-ingress class must have an edge trust contract.');
}

$apache = file_get_contents($apachePath);
$assert(is_string($apache), 'The Apache edge policy is missing or unreadable.');
if (is_string($apache)) {
    foreach (['SecRuleEngine On', 'SecRequestBodyAccess On', 'SecRequestBodyLimitAction Reject', 'TraceEnable Off', 'OWASP CRS'] as $directive) {
        $assert(str_contains($apache, $directive), "Apache edge policy is missing: {$directive}");
    }
    $assert(! str_contains($apache, '<IfModule'), 'The WAF policy must fail closed, not disappear behind IfModule.');
}

$partnerExample = file_get_contents($partnerExamplePath);
$assert(is_string($partnerExample) && str_contains($partnerExample, '192.0.2.0/24'), 'The partner template must use a documentation-only CIDR.');

if (in_array('--apache', $arguments, true)) {
    exec('apache2ctl -M 2>&1', $moduleOutput, $moduleStatus);
    $modules = implode("\n", $moduleOutput);
    $assert($moduleStatus === 0, 'apache2ctl could not load the full active configuration.');
    foreach (($policy['required_modules'] ?? []) as $module) {
        $assert(str_contains($modules, (string) $module), "Required Apache module is not active: {$module}");
    }

    $installedInclude = (string) ($policy['apache_include'] ?? '');
    $assert($installedInclude !== '' && is_file($installedInclude), 'The production edge include is not installed.');
    if ($installedInclude !== '' && is_file($installedInclude)) {
        $assert(hash_file('sha256', $installedInclude) === hash_file('sha256', $apachePath), 'The installed edge include differs from the release policy.');
    }

    $tlsVhost = (string) ($policy['apache_tls_vhost'] ?? '');
    $vhostContents = $tlsVhost !== '' && is_file($tlsVhost) ? file_get_contents($tlsVhost) : false;
    $assert(is_string($vhostContents), 'The enabled Zephyrus TLS vhost is missing or unreadable.');
    if (is_string($vhostContents) && $installedInclude !== '') {
        $assert(
            preg_match('/^\s*Include\s+'.preg_quote($installedInclude, '/').'\s*$/m', $vhostContents) === 1,
            'The enabled Zephyrus TLS vhost does not include the edge policy.',
        );
    }
}

foreach ($arguments as $argument) {
    if (! str_starts_with($argument, '--live=')) {
        continue;
    }

    $origin = rtrim(substr($argument, strlen('--live=')), '/');
    $assert(filter_var($origin, FILTER_VALIDATE_URL) !== false && str_starts_with($origin, 'https://'), 'Live verification requires an HTTPS origin.');
    if (! str_starts_with($origin, 'https://')) {
        continue;
    }

    $request = static function (string $url, string $method = 'GET'): array {
        $command = sprintf(
            'curl --silent --show-error --output /dev/null --dump-header - --request %s --max-time 20 %s',
            escapeshellarg($method),
            escapeshellarg($url),
        );
        exec($command, $lines, $status);

        return [$status, implode("\n", $lines)];
    };

    [$status, $headers] = $request($origin.'/login');
    $assert($status === 0, 'The live login boundary was unreachable.');
    foreach (($policy['required_response_headers'] ?? []) as $header) {
        $assert(preg_match('/^'.preg_quote((string) $header, '/').':/mi', $headers) === 1, "Live response is missing {$header}.");
    }

    foreach (['/.env', '/.git/config', '/composer.lock'] as $path) {
        [$pathStatus, $pathHeaders] = $request($origin.$path);
        $assert($pathStatus === 0, "Sensitive-path probe failed for {$path}.");
        $assert(preg_match('/^HTTP\/\S+ (?:403|404)\b/m', $pathHeaders) === 1, "Sensitive path was not blocked: {$path}");
    }

    [$traceStatus, $traceHeaders] = $request($origin.'/', 'TRACE');
    $assert($traceStatus === 0, 'TRACE probe failed.');
    $assert(preg_match('/^HTTP\/\S+ (?:403|405|501)\b/m', $traceHeaders) === 1, 'TRACE was not rejected.');
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Edge security contract verified.\n");
