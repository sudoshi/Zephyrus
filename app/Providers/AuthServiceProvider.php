<?php

namespace App\Providers;

use App\Authorization\AuthorizationScope;
use App\Authorization\Capability;
use App\Models\User;
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
        //
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
            'manageStaffingOperations' => Capability::ManageStaffingOperations,
            'requestTransportOperations' => Capability::RequestTransportOperations,
            'manageTransportDispatch' => Capability::ManageTransportDispatch,
            'progressTransportOperations' => Capability::ProgressTransportOperations,
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
    }
}
