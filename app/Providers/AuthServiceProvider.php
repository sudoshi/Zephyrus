<?php

namespace App\Providers;

use App\Authorization\AuthorizationScope;
use App\Authorization\Capability;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientEncounterProjection;
use App\Models\Patient\PatientMessageThread;
use App\Models\User;
use App\Policies\Patient\PatientEncounterAccessGrantPolicy;
use App\Policies\Patient\PatientEncounterProjectionPolicy;
use App\Policies\Patient\PatientMessageThreadPolicy;
use App\Services\Authorization\RoleCapabilityService;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        PatientEncounterAccessGrant::class => PatientEncounterAccessGrantPolicy::class,
        PatientEncounterProjection::class => PatientEncounterProjectionPolicy::class,
        PatientMessageThread::class => PatientMessageThreadPolicy::class,
    ];

    /** @var list<string> */
    private const ANCILLARY_BARRIER_ROLES = [
        'super_admin', 'superuser', 'ops_leader', 'admin', 'bed_manager', 'radiology_manager',
    ];

    /** @var list<string> */
    private const ANCILLARY_PATIENT_DETAIL_ROLES = [
        'super_admin', 'superuser', 'ops_leader', 'admin', 'bed_manager',
        'house_supervisor', 'capacity_lead', 'charge_nurse', 'hospitalist',
        'emergency_physician', 'emergency_nurse', 'case_manager',
        'discharge_coordinator', 'periop_manager', 'or_nurse', 'radiology_manager',
        'radiologist', 'radiologic_technologist', 'ct_technologist',
        'mri_technologist', 'sonographer',
    ];

    /** @var list<string> */
    private const CONTROLLED_SUBSTANCE_OPERATIONS_ROLES = [
        'super_admin', 'superuser', 'ops_leader', 'admin', 'pharmacy_manager',
        'pharmacy_operations_lead', 'controlled_substance_officer',
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        $resolver = app(RoleCapabilityService::class);

        $gates = [
            'viewAdministration' => Capability::ViewAdministration,
            'viewIdentity' => Capability::ViewIdentity,
            'manageIdentity' => Capability::ManageIdentity,
            'managePrivileges' => Capability::ManagePrivileges,
            'viewUserAudit' => Capability::ViewAudit,
            'viewAccessReviews' => Capability::ViewAccessReviews,
            'manageAccessReviews' => Capability::ManageAccessReviews,
            'viewAuthorization' => Capability::ViewAuthorization,
            'viewSystemHealth' => Capability::ViewSystemHealth,
            'runDiagnostics' => Capability::RunDiagnostics,
            'viewDeploymentConsole' => Capability::ViewEnterpriseSetup,
            'manageDeploymentConfig' => Capability::ManageEnterpriseSetup,
            'manageFacilityAdministration' => Capability::ManageFacilityAdministration,
            'viewIntegrations' => Capability::ViewIntegrations,
            // Compatibility adapter for existing integration requests. New
            // actions should choose configuration, operation, approval, etc.
            'manageIntegrations' => Capability::ManageIntegrationConfiguration,
            'manageIntegrationConfiguration' => Capability::ManageIntegrationConfiguration,
            'operateIntegrations' => Capability::OperateIntegrations,
            'approveIntegrationChanges' => Capability::ApproveIntegrationChanges,
            'rotateIntegrationCredentials' => Capability::RotateIntegrationCredentials,
            'executeDestructiveReplay' => Capability::ExecuteDestructiveReplay,
            'manageOutboundPolicy' => Capability::ManageOutboundPolicy,
            'manageDataStewardship' => Capability::ManageDataStewardship,
            'viewCarePathwayCatalog' => Capability::ViewCarePathwayCatalog,
            'adoptCarePathwaySource' => Capability::AdoptCarePathwaySource,
            'authorCarePathwayContent' => Capability::AuthorCarePathwayContent,
            'reviewCarePathwayEvidence' => Capability::ReviewCarePathwayEvidence,
            'approveCarePathwayClinical' => Capability::ApproveCarePathwayClinical,
            'activateCarePathwayCatalog' => Capability::ActivateCarePathwayCatalog,
            'viewEncounterCarePathway' => Capability::ViewEncounterCarePathway,
            'manageCarePathwayInstances' => Capability::ManageCarePathwayInstances,
            'manageStaffingOperations' => Capability::ManageStaffingOperations,
            'requestTransportOperations' => Capability::RequestTransportOperations,
            'manageTransportDispatch' => Capability::ManageTransportDispatch,
            'progressTransportOperations' => Capability::ProgressTransportOperations,
            'viewPatientCommunications' => Capability::ViewPatientCommunications,
            'respondPatientCommunications' => Capability::RespondPatientCommunications,
            'viewCockpitPolicy' => Capability::ViewCockpitPolicy,
            'manageCockpitPolicy' => Capability::ManageCockpitPolicy,
            'viewAiGovernance' => Capability::ViewAiGovernance,
            'manageAiGovernance' => Capability::ManageAiGovernance,
            'useEddyActions' => Capability::UseEddyActions,
            'writeOrCases' => Capability::WriteOrCases,
        ];

        foreach ($gates as $gate => $capability) {
            Gate::define(
                $gate,
                fn (User $user, ?AuthorizationScope $scope = null): bool => $resolver->allows($user, $capability, $scope),
            );
        }

        // Ancillary operational gates are intentionally additive to the
        // canonical Admin capability catalog. Patient-detail and controlled-
        // substance surfaces remain narrower than general Admin access.
        Gate::define('manageAncillaryBarriers', fn (User $user): bool => in_array(
            self::canonicalRole((string) $user->role),
            self::ANCILLARY_BARRIER_ROLES,
            true,
        ) || $user->hasRole(['admin', 'super-admin', 'super_admin']));
        Gate::define('viewAncillaryPatientDetail', fn (User $user): bool => in_array(
            self::canonicalRole((string) $user->role),
            self::ANCILLARY_PATIENT_DETAIL_ROLES,
            true,
        ) || $user->hasRole(['admin', 'super-admin', 'super_admin']));
        Gate::define('viewControlledSubstanceOperations', fn (User $user): bool => in_array(
            self::canonicalRole((string) $user->role),
            self::CONTROLLED_SUBSTANCE_OPERATIONS_ROLES,
            true,
        ) || $user->hasRole(['admin', 'super-admin', 'super_admin']));
    }

    private static function canonicalRole(string $role): string
    {
        return str_replace([' ', '-'], '_', strtolower($role));
    }
}
