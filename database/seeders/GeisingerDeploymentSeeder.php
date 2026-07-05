<?php

namespace Database\Seeders;

use App\Services\Deployment\DeploymentCapabilityImporter;
use App\Services\Deployment\DeploymentFacilityImporter;
use App\Services\Deployment\ServiceLineNormalizer;
use App\Services\Deployment\ServiceLineRegistrar;
use Illuminate\Database\Seeder;

/**
 * Archetype fixture (test/demo only, review_status='assumed'): Geisinger's
 * hub-and-spoke IDN — Level I trauma at Danville/Wyoming Valley, Level II at
 * Scranton, Level IV stabilize-and-transfer at Muncy/Lewistown. Proves the schema
 * holds a heterogeneous IDN without false uniformity.
 */
class GeisingerDeploymentSeeder extends Seeder
{
    public function run(): void
    {
        app(ServiceLineRegistrar::class)->seed();
        ServiceLineNormalizer::flush();

        app(DeploymentFacilityImporter::class)
            ->importFile(database_path('seeders/fixtures/geisinger-facilities.json'));

        app(DeploymentCapabilityImporter::class)
            ->importFile(database_path('seeders/fixtures/geisinger-capabilities.json'));
    }
}
