<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$failures = [];

$fail = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root.'/'.$path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$path}");
    }

    return $contents;
};

$yaml = static function (string $path) use ($root): array {
    $document = Yaml::parseFile($root.'/'.$path);
    if (! is_array($document)) {
        throw new RuntimeException("Expected a YAML mapping in {$path}");
    }

    return $document;
};

$xml = static function (string $path) use ($root): DOMXPath {
    $document = new DOMDocument;
    $document->preserveWhiteSpace = false;
    if (! $document->load($root.'/'.$path, LIBXML_NONET)) {
        throw new RuntimeException("Unable to parse {$path}");
    }

    return new DOMXPath($document);
};

$androidAttribute = static function (
    DOMXPath $xpath,
    string $expression,
    string $name
): ?string {
    $nodes = $xpath->query($expression);
    if ($nodes === false || $nodes->length !== 1) {
        return null;
    }
    $node = $nodes->item(0);
    if (! $node instanceof DOMElement) {
        return null;
    }

    return $node->getAttributeNS(
        'http://schemas.android.com/apk/res/android',
        $name
    ) ?: null;
};

try {
    foreach ([
        ['hummingbird/iosApp/project.yml', 'Hummingbird'],
        ['hummingbird/iosPatientApp/project.yml', 'HummingbirdPatient'],
    ] as [$projectPath, $target]) {
        $project = $yaml($projectPath);
        $properties = $project['targets'][$target]['info']['properties'] ?? null;
        $fail(
            is_array($properties),
            "{$projectPath} is missing target Info.plist properties"
        );
        $fail(
            is_array($properties)
                && ! array_key_exists('NSAppTransportSecurity', $properties),
            "{$projectPath} must use default ATS without a release exception"
        );
    }

    foreach ([
        'hummingbird/androidApp/app/src/main/AndroidManifest.xml',
        'hummingbird/androidPatientApp/app/src/main/AndroidManifest.xml',
    ] as $manifestPath) {
        $manifest = $xml($manifestPath);
        $fail(
            $androidAttribute(
                $manifest,
                '/manifest/application',
                'usesCleartextTraffic'
            ) === 'false',
            "{$manifestPath} must disable cleartext traffic"
        );
        $fail(
            $androidAttribute(
                $manifest,
                '/manifest/application',
                'networkSecurityConfig'
            ) === '@xml/network_security_config',
            "{$manifestPath} must bind the governed Network Security Configuration"
        );
    }

    foreach ([
        'hummingbird/androidApp/app/src/main/res/xml/network_security_config.xml',
        'hummingbird/androidPatientApp/app/src/main/res/xml/network_security_config.xml',
    ] as $configPath) {
        $config = $xml($configPath);
        $fail(
            $config->evaluate(
                'string(/network-security-config/base-config/@cleartextTrafficPermitted)'
            ) === 'false',
            "{$configPath} must deny cleartext in its base policy"
        );
        $fail(
            (int) $config->evaluate(
                'count(/network-security-config/base-config/trust-anchors/certificates[@src="system"])'
            ) === 1,
            "{$configPath} must trust the system certificate store"
        );
        $fail(
            (int) $config->evaluate(
                'count(/network-security-config//certificates[@src!="system"])'
            ) === 0,
            "{$configPath} must not add user or bundled trust anchors"
        );
        $fail(
            (int) $config->evaluate(
                'count(/network-security-config//pin-set)'
            ) === 0,
            "{$configPath} contains an ungoverned certificate pin"
        );
    }

    $debugConfigPath =
        'hummingbird/androidApp/app/src/debug/res/xml/network_security_config.xml';
    $debugConfig = $xml($debugConfigPath);
    $fail(
        $debugConfig->evaluate(
            'string(/network-security-config/base-config/@cleartextTrafficPermitted)'
        ) === 'false',
        "{$debugConfigPath} must retain a cleartext-deny base policy"
    );
    $debugDomains = [];
    $nodes = $debugConfig->query(
        '/network-security-config/domain-config[@cleartextTrafficPermitted="true"]/domain'
    );
    if ($nodes !== false) {
        foreach ($nodes as $node) {
            $debugDomains[] = trim($node->textContent);
            if ($node instanceof DOMElement) {
                $fail(
                    $node->getAttribute('includeSubdomains') === 'false',
                    "{$debugConfigPath} debug domains must not include subdomains"
                );
            }
        }
    }
    sort($debugDomains);
    $fail(
        $debugDomains === ['10.0.2.2', '127.0.0.1', 'localhost'],
        "{$debugConfigPath} cleartext allowlist must be exact"
    );

    $staffIOSPolicy = $read(
        'hummingbird/iosApp/Hummingbird/Networking/TransportSecurityPolicy.swift'
    );
    $staffIOSConfig = $read(
        'hummingbird/iosApp/Hummingbird/Networking/APIClient.swift'
    );
    $staffIOSRealtime = $read(
        'hummingbird/iosApp/Hummingbird/Networking/RealtimeClient.swift'
    );
    $staffAndroidPolicy = $read(
        'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/data/TransportSecurityPolicy.kt'
    );
    $patientIOSConfig = $read(
        'hummingbird/iosPatientApp/HummingbirdPatient/App/PatientAppConfiguration.swift'
    );
    $patientIOSNetwork = $read(
        'hummingbird/iosPatientApp/HummingbirdPatient/Networking/PatientAPIClient.swift'
    );
    $patientAndroidConfig = $read(
        'hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/data/PatientApiClient.kt'
    );
    $staffAndroidAPI = $read(
        'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/data/ApiClient.kt'
    );
    $staffAndroidRealtime = $read(
        'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/data/RealtimeClient.kt'
    );

    foreach ([
        'staff iOS policy' => $staffIOSPolicy,
        'staff Android policy' => $staffAndroidPolicy,
        'patient iOS policy' => $patientIOSConfig,
        'patient Android policy' => $patientAndroidConfig,
    ] as $label => $source) {
        $fail(
            str_contains($source, 'zephyrus.acumenus.net'),
            "{$label} must bind the approved production hostname"
        );
    }
    $fail(
        str_contains(
            $staffIOSConfig,
            '#if DEBUG && targetEnvironment(simulator)'
        ),
        'staff iOS must not select local HTTP for a Release simulator'
    );
    foreach ([
        'staff iOS' => [$staffIOSPolicy, 'HummingbirdNoRedirectDelegate'],
        'patient iOS' => [$patientIOSNetwork, 'PatientNoRedirectDelegate'],
    ] as $label => [$source, $delegate]) {
        $fail(
            str_contains($source, $delegate)
                && str_contains($source, 'completionHandler(nil)'),
            "{$label} must reject every URLSession redirect"
        );
    }
    $fail(
        str_contains($staffIOSConfig, 'HummingbirdURLSessionFactory.make()')
            && str_contains(
                $staffIOSConfig,
                'HummingbirdURLSessionFactory.make(configuration: configuration)'
            ),
        'staff iOS API sessions must use the no-redirect factory'
    );
    $fail(
        str_contains($staffIOSRealtime, 'HummingbirdURLSessionFactory.make()')
            && str_contains(
                $staffIOSRealtime,
                'session.webSocketTask(with: url)'
            ),
        'staff iOS realtime must use the governed no-redirect session'
    );
    $fail(
        str_contains(
            $patientIOSNetwork,
            'self.session = PatientURLSessionFactory.ephemeral()'
        ),
        'patient iOS API sessions must use the no-redirect factory'
    );
    $fail(
        preg_match(
            '/#if DEBUG\\s+.*?init\\(\\s*baseURL: URL,\\s*session: URLSession,.*?#endif/s',
            $staffIOSConfig
        ) === 1,
        'staff iOS custom URLSession injection must remain Debug-only'
    );
    $fail(
        preg_match(
            '/#if DEBUG\\s+.*?init\\(baseURL: URL, session: URLSession\\).*?#endif/s',
            $patientIOSNetwork
        ) === 1,
        'patient iOS custom URLSession injection must remain Debug-only'
    );
    $fail(
        str_contains($staffAndroidAPI, 'instanceFollowRedirects = false'),
        'staff Android API transport must reject redirects'
    );
    $fail(
        str_contains($staffAndroidRealtime, '.followRedirects(false)')
            && str_contains($staffAndroidRealtime, '.followSslRedirects(false)'),
        'staff Android realtime transport must reject redirects'
    );
    $fail(
        str_contains($patientAndroidConfig, '.followRedirects(false)')
            && str_contains($patientAndroidConfig, '.followSslRedirects(false)'),
        'patient Android transport must reject redirects'
    );

    $applicationSourceRoots = [
        'hummingbird/iosApp/Hummingbird',
        'hummingbird/iosPatientApp/HummingbirdPatient',
        'hummingbird/androidApp/app/src/main',
        'hummingbird/androidPatientApp/app/src/main',
    ];
    $forbiddenTrustHooks = [
        'NSAllowsArbitraryLoads',
        'NSAllowsLocalNetworking',
        'NSPinnedDomains',
        'NSPinnedCAIdentities',
        'NSPinnedLeafIdentities',
        'X509TrustManager',
        'HostnameVerifier',
        'hostnameVerifier',
        'sslSocketFactory',
        'trustAllCerts',
        'serverTrust',
    ];
    foreach ($applicationSourceRoots as $relativeRoot) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root.'/'.$relativeRoot,
                FilesystemIterator::SKIP_DOTS
            )
        );
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                throw new RuntimeException(
                    "Unable to read {$file->getPathname()}"
                );
            }
            foreach ($forbiddenTrustHooks as $marker) {
                $fail(
                    ! str_contains($source, $marker),
                    "{$relativeRoot}/{$file->getFilename()} contains ungoverned trust hook {$marker}"
                );
            }
        }
    }

    $adrPath =
        'docs/hummingbird/ADR-2026-07-24-mobile-transport-security-and-pinning.md';
    $adr = $read($adrPath);
    $fail(
        str_contains(
            $adr,
            '**Do not ship static certificate or public-key pins in this tranche.**'
        ),
        "{$adrPath} must retain an explicit pinning decision"
    );
    $fail(
        str_contains($adr, '2027-07-24'),
        "{$adrPath} must retain a bounded review trigger"
    );
} catch (Throwable $error) {
    $failures[] = $error->getMessage();
}

if ($failures !== []) {
    fwrite(STDERR, "Hummingbird transport-security verification failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

fwrite(
    STDOUT,
    "Hummingbird transport-security controls verified for 4 native products.\n"
);
