<?php

namespace Tests\Feature\CarePathways;

use App\Authorization\Capability;
use App\Models\User;
use App\Services\Authorization\RoleCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CarePathwayAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_governance_duties_are_separated_across_explicit_roles(): void
    {
        $expectations = [
            'data_steward' => [
                Capability::ViewCarePathwayCatalog,
                Capability::AdoptCarePathwaySource,
            ],
            'care_pathway_author' => [
                Capability::ViewCarePathwayCatalog,
                Capability::AuthorCarePathwayContent,
            ],
            'care_pathway_evidence_reviewer' => [
                Capability::ViewCarePathwayCatalog,
                Capability::ReviewCarePathwayEvidence,
            ],
            'care_pathway_clinical_approver' => [
                Capability::ViewCarePathwayCatalog,
                Capability::ReviewCarePathwayEvidence,
                Capability::ApproveCarePathwayClinical,
            ],
            'care_pathway_release_manager' => [
                Capability::ViewCarePathwayCatalog,
                Capability::ActivateCarePathwayCatalog,
            ],
            'care_pathway_instance_manager' => [
                Capability::ViewCarePathwayCatalog,
                Capability::ViewEncounterCarePathway,
                Capability::ManageCarePathwayInstances,
            ],
        ];
        $carePathwayCapabilities = $this->carePathwayCapabilities();
        $authorization = app(RoleCapabilityService::class);

        foreach ($expectations as $role => $expected) {
            $user = User::factory()->create(['role' => $role]);
            $expectedValues = collect($expected)->map->value->sort()->values()->all();
            $actualValues = collect($carePathwayCapabilities)
                ->filter(fn (Capability $capability): bool => $authorization->allows($user, $capability))
                ->map->value
                ->sort()
                ->values()
                ->all();

            $this->assertSame($expectedValues, $actualValues, "Unexpected care-pathway authority for {$role}.");
        }
    }

    public function test_source_adoption_clinical_approval_and_release_activation_never_collapse(): void
    {
        $authorization = app(RoleCapabilityService::class);
        $dataSteward = User::factory()->create(['role' => 'data_steward']);
        $clinicalApprover = User::factory()->create(['role' => 'care_pathway_clinical_approver']);
        $releaseManager = User::factory()->create(['role' => 'care_pathway_release_manager']);

        $this->assertTrue($authorization->allows($dataSteward, Capability::AdoptCarePathwaySource));
        $this->assertFalse($authorization->allows($dataSteward, Capability::ApproveCarePathwayClinical));
        $this->assertFalse($authorization->allows($dataSteward, Capability::ActivateCarePathwayCatalog));

        $this->assertTrue($authorization->allows($clinicalApprover, Capability::ApproveCarePathwayClinical));
        $this->assertFalse($authorization->allows($clinicalApprover, Capability::AdoptCarePathwaySource));
        $this->assertFalse($authorization->allows($clinicalApprover, Capability::ActivateCarePathwayCatalog));

        $this->assertTrue($authorization->allows($releaseManager, Capability::ActivateCarePathwayCatalog));
        $this->assertFalse($authorization->allows($releaseManager, Capability::AdoptCarePathwaySource));
        $this->assertFalse($authorization->allows($releaseManager, Capability::ApproveCarePathwayClinical));
    }

    public function test_gate_adapter_and_inactive_account_fail_closed(): void
    {
        $activeReviewer = User::factory()->create(['role' => 'care_pathway_evidence_reviewer']);
        $inactiveSuperAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => false,
        ]);

        $this->assertTrue(Gate::forUser($activeReviewer)->allows('viewCarePathwayCatalog'));
        $this->assertTrue(Gate::forUser($activeReviewer)->allows('reviewCarePathwayEvidence'));
        $this->assertFalse(Gate::forUser($activeReviewer)->allows('approveCarePathwayClinical'));

        foreach ($this->carePathwayCapabilities() as $capability) {
            $this->assertFalse(
                app(RoleCapabilityService::class)->allows($inactiveSuperAdmin, $capability),
                "Inactive account unexpectedly retained {$capability->value}.",
            );
        }
    }

    /** @return list<Capability> */
    private function carePathwayCapabilities(): array
    {
        return [
            Capability::ViewCarePathwayCatalog,
            Capability::AdoptCarePathwaySource,
            Capability::AuthorCarePathwayContent,
            Capability::ReviewCarePathwayEvidence,
            Capability::ApproveCarePathwayClinical,
            Capability::ActivateCarePathwayCatalog,
            Capability::ViewEncounterCarePathway,
            Capability::ManageCarePathwayInstances,
        ];
    }
}
