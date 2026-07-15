<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call([
            UserSeeder::class,
            CaseManagementSeeder::class, // Case reference data (case_statuses).
            // RtdcSeeder is the sole creator of prod.units + prod.beds. It MUST run
            // before CommandCenterDemoSeeder, whose per-unit loops only read/tune
            // units and silently no-op when units are absent. (Previously RtdcSeeder
            // ran only via the rtdc:demo-reset command, so a bare `db:seed` left the
            // RTDC spine empty.) RtdcSeeder is idempotent (firstOrCreate).
            RtdcSeeder::class,
            // Connector definitions are explicit templates, never observed-health
            // records. Loading the Integrations page never mutates this catalog.
            IntegrationConnectorTemplateSeeder::class,
            // Shared ancillary milestone/barrier/SLA policy catalogs. Department
            // demo generators and projectors depend on these stable codes.
            AncillaryReferenceSeeder::class,
            // Reference projection is explicit and precedes any scenario-owned
            // workforce rows. Demo roll-forward never mutates the taxonomy.
            StaffingReferenceSeeder::class,
            // ProviderRegistrySeeder seeds the full clinical provider roster
            // (prod.providers, NPIs 17000000xx) plus the service-line catalog
            // (prod.specialties / prod.services) from the HospitalManifest. It
            // runs BEFORE CommandCenterDemoSeeder so the named attendings and
            // every service line exist before CCDS layers operational demo data;
            // both key reference rows on natural keys (npi / code), so the
            // overlap is reused, not duplicated. Idempotent.
            ProviderRegistrySeeder::class,
            // CommandCenterDemoSeeder owns the full reference set (locations,
            // specialties, services, providers, rooms, ASA/case-type/class) AND the
            // operational demo data. (TestDataSeeder was removed from the chain: its
            // services/rooms/providers were redundant with CCDS and it failed on a
            // pristine DB by referencing prod.locations/specialties before CCDS
            // creates them.)
            CommandCenterDemoSeeder::class,
            ImprovementDemoSeeder::class,
            // ClinicalPathwaySeeder synthesises per-encounter clinical trajectories
            // (sepsis bundle, acute-ischemic-stroke pathway) into flow_core.flow_events
            // and the WHO surgical-safety checklist / OR phase timeline into
            // prod.case_timings + care_journey_milestones + case_safety_notes — the
            // conformance data the operational store does not capture (Part X / X.7).
            // Runs AFTER CommandCenterDemoSeeder because surgical safety attaches to
            // its seeded prod.or_cases. Idempotent (tag/cohort delete-then-insert).
            ClinicalPathwaySeeder::class,
            // ACUM-OPS-OCEL-001 reference-model registry. These are seeded,
            // explicitly non-observed bounded flows for the Arena landscape;
            // live/discovered evidence remains in ocel.events + arena.maps.
            OcelProcessLandscapeSeeder::class,
            // Virtual Rounds pilot templates (rounds.templates). Idempotent by
            // (name, version); template UUIDs are minted once and preserved.
            RoundTemplateSeeder::class,
            // DemoTuningSeeder runs LAST: it tunes whatever the base seeders produced into the
            // compelling live demo state (85% occupancy, today's staffing gaps, near-now SLAs,
            // clean ED bed inventory, varied OR surgeons). Idempotent; Postgres-only.
            DemoTuningSeeder::class,
        ]);
    }
}
