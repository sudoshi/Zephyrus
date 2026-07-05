<?php

namespace App\Support\Hospital;

use RuntimeException;

/**
 * Typed accessor for a hospital manifest. Summit Regional Medical Center
 * (config/hospital/hospital-1.php) is the default and reference deployment, but the
 * class is facility-parameterized (Phase 5): construct with a facility_key, or use
 * HospitalManifest::forFacility('<KEY>'), to load any facility registered in
 * config/hospital.php. Resolve from the container (e.g. app(HospitalManifest::class),
 * which yields the default facility) or constructor-inject. Every seeder/service that
 * previously hardcoded unit names, provider/nurse pools, teams, vendors, post-acute
 * partners, or facility names MUST read them through this class instead.
 *
 * Each facility's array is loaded once and statically cached (keyed by facility_key)
 * for the process lifetime.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 5)
 */
class HospitalManifest
{
    /** @var array<string,array<string,mixed>> keyed by facility_key */
    private static array $cache = [];

    private readonly string $facilityKey;

    public function __construct(?string $facilityKey = null)
    {
        $this->facilityKey = $facilityKey
            ?? (string) config('hospital.default_facility', 'SUMMIT_REGIONAL');
    }

    /** Build a manifest accessor for a specific facility_key. */
    public static function forFacility(string $facilityKey): self
    {
        return new self($facilityKey);
    }

    /** The facility_key this instance reads. */
    public function facilityKey(): string
    {
        return $this->facilityKey;
    }

    /** @return array<string,mixed> */
    private function data(): array
    {
        if (! isset(self::$cache[$this->facilityKey])) {
            self::$cache[$this->facilityKey] = require base_path($this->configPath());
        }

        return self::$cache[$this->facilityKey];
    }

    /** Resolve the facility_key to its manifest config path (config/hospital.php). */
    private function configPath(): string
    {
        /** @var array<string,string> $manifests */
        $manifests = (array) config('hospital.manifests', []);

        $path = $manifests[$this->facilityKey] ?? null;
        if (! is_string($path) || $path === '') {
            throw new RuntimeException(
                "No manifest is registered for facility_key '{$this->facilityKey}'. ".
                'Register it in config/hospital.php (generate with `hospital:generate-manifest`).'
            );
        }

        return $path;
    }

    /** Test/seed helper: drop the static cache for all facilities (e.g. between test cases). */
    public static function flush(): void
    {
        self::$cache = [];
    }

    // ---- facility identity ----

    /** @return array<string,mixed> */
    public function facility(): array
    {
        return $this->data()['facility'];
    }

    public function facilityName(): string
    {
        return $this->facility()['name'];
    }

    public function facilityCode(): string
    {
        return $this->facility()['code'];
    }

    /** The immutable CAD/RTDC join key ('ZEPHYRUS-500') — overlay branding, never rename. */
    public function cadFacilityCode(): string
    {
        return $this->facility()['cad_facility_code'];
    }

    // ---- units ----

    /** @return list<array<string,mixed>> */
    public function units(): array
    {
        return $this->data()['units'];
    }

    /** @return list<array<string,mixed>> */
    public function inpatientUnits(): array
    {
        return array_values(array_filter($this->units(), fn ($u) => $u['inpatient'] ?? false));
    }

    /** @return array<string,mixed>|null */
    public function unit(string $abbr): ?array
    {
        foreach ($this->units() as $u) {
            if (strcasecmp($u['abbr'], $abbr) === 0) {
                return $u;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    public function unitByCadCode(string $cadCode): ?array
    {
        foreach ($this->units() as $u) {
            if (strcasecmp($u['cad_code'], $cadCode) === 0) {
                return $u;
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    public function unitsByType(string $type): array
    {
        return array_values(array_filter($this->units(), fn ($u) => $u['type'] === $type));
    }

    /** @return list<array<string,mixed>> */
    public function unitsByServiceLine(string $serviceLine): array
    {
        return array_values(array_filter($this->units(), fn ($u) => $u['service_line'] === $serviceLine));
    }

    /** @return list<string> */
    public function unitAbbrs(): array
    {
        return array_map(fn ($u) => $u['abbr'], $this->units());
    }

    /** @return list<string> */
    public function unitNames(): array
    {
        return array_map(fn ($u) => $u['name'], $this->units());
    }

    // ---- service lines ----

    /** @return list<array<string,mixed>> */
    public function serviceLines(): array
    {
        return $this->data()['service_lines'];
    }

    // ---- providers ----

    /** @return list<array<string,mixed>> */
    public function providers(): array
    {
        return $this->data()['providers'];
    }

    /** @return list<array<string,mixed>> */
    public function providersForSpecialty(string $specialty): array
    {
        return array_values(array_filter($this->providers(), fn ($p) => $p['specialty'] === $specialty));
    }

    /** @return list<string> */
    public function providerNames(?string $specialty = null): array
    {
        $src = $specialty ? $this->providersForSpecialty($specialty) : $this->providers();

        return array_values(array_map(fn ($p) => $p['name'], $src));
    }

    // ---- nurses ----

    /** @return list<array<string,mixed>> */
    public function nurses(): array
    {
        return $this->data()['nurses'];
    }

    /** @return list<string> */
    public function nurseNames(?string $role = null): array
    {
        $src = $role ? array_filter($this->nurses(), fn ($n) => $n['role'] === $role) : $this->nurses();

        return array_values(array_map(fn ($n) => $n['name'], $src));
    }

    // ---- teams, transport, post-acute, ancillary ----

    /** @return list<array<string,mixed>> */
    public function careTeams(): array
    {
        return $this->data()['care_teams'];
    }

    /** @return list<string> */
    public function careTeamNames(): array
    {
        return array_map(fn ($t) => $t['name'], $this->careTeams());
    }

    /** @return array<string,mixed> */
    public function transport(): array
    {
        return $this->data()['transport'];
    }

    /** @return list<string> */
    public function transportTeamNames(): array
    {
        $names = [$this->transport()['internal_team']['name']];
        foreach ($this->transport()['vendors'] as $vendor) {
            $names[] = $vendor['name'];
        }

        return $names;
    }

    /** @return list<array<string,mixed>> */
    public function postAcuteNetwork(): array
    {
        return $this->data()['post_acute_network'];
    }

    /** @return list<string> */
    public function postAcuteNames(?string $type = null): array
    {
        $src = $type
            ? array_filter($this->postAcuteNetwork(), fn ($p) => $p['type'] === $type)
            : $this->postAcuteNetwork();

        return array_values(array_map(fn ($p) => $p['name'], $src));
    }

    /** @return list<array<string,mixed>> */
    public function ancillaryTeams(): array
    {
        return $this->data()['ancillary_teams'];
    }

    // ---- network facilities (multi-hospital filter dimension) ----

    /** @return list<array<string,mixed>> */
    public function networkFacilities(): array
    {
        return $this->data()['network_facilities'];
    }

    /** @return list<string> */
    public function networkFacilityNames(): array
    {
        return array_map(fn ($f) => $f['name'], $this->networkFacilities());
    }

    public function primaryNetworkFacilityName(): string
    {
        return $this->networkFacilities()[0]['name'];
    }

    // ---- demo tuning ----

    /** @return list<array<string,mixed>> */
    public function censusDemoTargets(): array
    {
        return $this->data()['census_demo_targets'];
    }
}
