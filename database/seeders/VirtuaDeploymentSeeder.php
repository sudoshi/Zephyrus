<?php

namespace Database\Seeders;

use App\Services\Deployment\DeploymentCapabilityImporter;
use App\Services\Deployment\DeploymentFacilityImporter;
use App\Services\Deployment\ServiceLineNormalizer;
use App\Services\Deployment\ServiceLineRegistrar;
use Illuminate\Database\Seeder;

/**
 * Archetype fixture (test/demo only, review_status='assumed'): Virtua Health —
 * transplant only at Our Lady of Lourdes, Regional Perinatal Center + Level III
 * NICU only at Voorhees, and an external Level I trauma partnership to Cooper
 * University Hospital. Proves specialty concentration and external transfer edges.
 */
class VirtuaDeploymentSeeder extends Seeder
{
    public function run(): void
    {
        app(ServiceLineRegistrar::class)->seed();
        ServiceLineNormalizer::flush();

        app(DeploymentFacilityImporter::class)
            ->importFile(database_path('seeders/fixtures/virtua-facilities.json'));

        app(DeploymentCapabilityImporter::class)
            ->importFile(database_path('seeders/fixtures/virtua-capabilities.json'));
    }
}
