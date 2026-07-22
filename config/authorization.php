<?php

use App\Authorization\Capability;

$all = array_column(Capability::cases(), 'value');

return [
    'role_aliases' => [
        'super_user' => 'superuser',
    ],

    /*
    | Canonical role profiles
    |--------------------------------------------------------------------------
    | Keys are normalized by RoleCapabilityService (lowercase; spaces and
    | hyphens become underscores). These profiles intentionally distinguish
    | identity, privilege, integration, stewardship, audit, and facility duties.
    */
    'role_capabilities' => [
        'super_admin' => $all,

        'admin' => [
            Capability::ViewAdministration->value,
            Capability::ViewIdentity->value,
            Capability::ManageIdentity->value,
            Capability::ManagePrivileges->value,
            Capability::ViewAudit->value,
            Capability::ViewAccessReviews->value,
            Capability::ManageAccessReviews->value,
            Capability::ViewAuthorization->value,
            Capability::ViewSystemHealth->value,
            Capability::RunDiagnostics->value,
            Capability::ViewEnterpriseSetup->value,
            Capability::ViewCockpitPolicy->value,
            Capability::ManageCockpitPolicy->value,
            Capability::ViewAiGovernance->value,
            Capability::ManageAiGovernance->value,
            Capability::ViewCarePathwayCatalog->value,
            Capability::ManageStaffingOperations->value,
            Capability::RequestTransportOperations->value,
            Capability::ManageTransportDispatch->value,
            Capability::ProgressTransportOperations->value,
            Capability::ViewPatientCommunications->value,
            Capability::RespondPatientCommunications->value,
            Capability::UseEddyActions->value,
            Capability::WriteOrCases->value,
            Capability::AssumeAnyMobilePersona->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],

        'superuser' => [
            Capability::ViewEnterpriseSetup->value,
            Capability::ManageEnterpriseSetup->value,
            Capability::ManageFacilityAdministration->value,
            Capability::ViewIntegrations->value,
            Capability::ManageIntegrationConfiguration->value,
            Capability::OperateIntegrations->value,
            Capability::ApproveIntegrationChanges->value,
            Capability::RotateIntegrationCredentials->value,
            Capability::ExecuteDestructiveReplay->value,
            Capability::ManageOutboundPolicy->value,
            Capability::ManageDataStewardship->value,
            Capability::ViewCarePathwayCatalog->value,
            Capability::AdoptCarePathwaySource->value,
            Capability::AuthorCarePathwayContent->value,
            Capability::ReviewCarePathwayEvidence->value,
            Capability::ManageStaffingOperations->value,
            Capability::RequestTransportOperations->value,
            Capability::ManageTransportDispatch->value,
            Capability::ProgressTransportOperations->value,
            Capability::ViewPatientCommunications->value,
            Capability::RespondPatientCommunications->value,
            Capability::UseEddyActions->value,
            Capability::WriteOrCases->value,
            Capability::AssumeAnyMobilePersona->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],

        'identity_admin' => [
            Capability::ViewAdministration->value,
            Capability::ViewIdentity->value,
            Capability::ManageIdentity->value,
        ],
        'privilege_admin' => [
            Capability::ViewAdministration->value,
            Capability::ViewIdentity->value,
            Capability::ManageIdentity->value,
            Capability::ManagePrivileges->value,
            Capability::ViewAccessReviews->value,
            Capability::ManageAccessReviews->value,
            Capability::ViewAuthorization->value,
        ],
        'integration_admin' => [
            Capability::ViewIntegrations->value,
            Capability::ManageIntegrationConfiguration->value,
            Capability::OperateIntegrations->value,
            Capability::RotateIntegrationCredentials->value,
            Capability::ManageOutboundPolicy->value,
        ],
        'integration_operator' => [
            Capability::ViewIntegrations->value,
            Capability::OperateIntegrations->value,
        ],
        'integration_approver' => [
            Capability::ViewIntegrations->value,
            Capability::ApproveIntegrationChanges->value,
        ],
        'data_steward' => [
            Capability::ViewIntegrations->value,
            Capability::ManageDataStewardship->value,
            Capability::ViewCarePathwayCatalog->value,
            Capability::AdoptCarePathwaySource->value,
        ],
        'care_pathway_author' => [
            Capability::ViewCarePathwayCatalog->value,
            Capability::AuthorCarePathwayContent->value,
        ],
        'care_pathway_evidence_reviewer' => [
            Capability::ViewCarePathwayCatalog->value,
            Capability::ReviewCarePathwayEvidence->value,
        ],
        'care_pathway_clinical_approver' => [
            Capability::ViewCarePathwayCatalog->value,
            Capability::ReviewCarePathwayEvidence->value,
            Capability::ApproveCarePathwayClinical->value,
        ],
        'care_pathway_release_manager' => [
            Capability::ViewCarePathwayCatalog->value,
            Capability::ActivateCarePathwayCatalog->value,
        ],
        'care_pathway_instance_manager' => [
            Capability::ViewCarePathwayCatalog->value,
            Capability::ViewEncounterCarePathway->value,
            Capability::ManageCarePathwayInstances->value,
        ],
        'auditor' => [
            Capability::ViewAdministration->value,
            Capability::ViewAudit->value,
            Capability::ViewAccessReviews->value,
            Capability::ViewAuthorization->value,
            Capability::ViewSystemHealth->value,
            Capability::ViewEnterpriseSetup->value,
            Capability::ViewIntegrations->value,
            Capability::ViewCockpitPolicy->value,
            Capability::ViewAiGovernance->value,
            Capability::ViewCarePathwayCatalog->value,
        ],
        'facility_admin' => [
            Capability::ViewAdministration->value,
            Capability::ViewEnterpriseSetup->value,
            Capability::ManageFacilityAdministration->value,
            Capability::ManageStaffingOperations->value,
            Capability::ViewCockpitPolicy->value,
            Capability::ManageCockpitPolicy->value,
        ],
        // Dedicated operational-policy stewards. Cockpit thresholds are a
        // facility-operations duty; AI governance is its own steward duty and
        // deliberately does NOT ride along with identity or integration roles.
        'cockpit_policy_steward' => [
            Capability::ViewAdministration->value,
            Capability::ViewCockpitPolicy->value,
            Capability::ManageCockpitPolicy->value,
        ],
        'ai_governance_steward' => [
            Capability::ViewAdministration->value,
            Capability::ViewAiGovernance->value,
            Capability::ManageAiGovernance->value,
        ],

        'ops_leader' => [
            Capability::ViewEnterpriseSetup->value,
            Capability::ManageEnterpriseSetup->value,
            Capability::ManageStaffingOperations->value,
            Capability::RequestTransportOperations->value,
            Capability::ManageTransportDispatch->value,
            Capability::ProgressTransportOperations->value,
            Capability::ViewPatientCommunications->value,
            Capability::RespondPatientCommunications->value,
            Capability::UseEddyActions->value,
            Capability::WriteOrCases->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'staffing_coordinator' => [
            Capability::ManageStaffingOperations->value,
            Capability::UseEddyActions->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'house_supervisor' => [
            Capability::ManageStaffingOperations->value,
            Capability::RequestTransportOperations->value,
            Capability::ManageTransportDispatch->value,
            Capability::ProgressTransportOperations->value,
            Capability::ViewPatientCommunications->value,
            Capability::RespondPatientCommunications->value,
            Capability::UseEddyActions->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'capacity_lead' => [
            Capability::RequestTransportOperations->value,
            Capability::ManageTransportDispatch->value,
            Capability::ProgressTransportOperations->value,
            Capability::UseEddyActions->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'transport_dispatcher' => [
            Capability::RequestTransportOperations->value,
            Capability::ManageTransportDispatch->value,
            Capability::ProgressTransportOperations->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'transfer_center' => [
            Capability::RequestTransportOperations->value,
            Capability::ManageTransportDispatch->value,
            Capability::ProgressTransportOperations->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'transport' => [
            Capability::RequestTransportOperations->value,
            Capability::ProgressTransportOperations->value,
            Capability::UseEddyActions->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'bed_manager' => [
            Capability::RequestTransportOperations->value,
            Capability::UseEddyActions->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'charge_nurse' => [
            Capability::RequestTransportOperations->value,
            Capability::ViewPatientCommunications->value,
            Capability::RespondPatientCommunications->value,
            Capability::UseEddyActions->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'hospitalist' => [
            Capability::RequestTransportOperations->value,
            Capability::ViewPatientCommunications->value,
            Capability::RespondPatientCommunications->value,
            Capability::UseEddyActions->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'intensivist' => [
            Capability::RequestTransportOperations->value,
            Capability::ViewPatientCommunications->value,
            Capability::RespondPatientCommunications->value,
            Capability::UseEddyActions->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'case_manager' => [
            Capability::RequestTransportOperations->value,
            Capability::ViewPatientCommunications->value,
            Capability::RespondPatientCommunications->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'discharge_coordinator' => [
            Capability::RequestTransportOperations->value,
            Capability::ViewPatientCommunications->value,
            Capability::RespondPatientCommunications->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'ed_nurse' => [
            Capability::RequestTransportOperations->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'or_nurse' => [
            Capability::RequestTransportOperations->value,
            Capability::UseEddyActions->value,
            Capability::WriteOrCases->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'periop_manager' => [
            Capability::UseEddyActions->value,
            Capability::WriteOrCases->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'evs' => [
            Capability::UseEddyActions->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'pi_lead' => [
            Capability::UseEddyActions->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'bedside_nurse' => [
            Capability::ViewPatientCommunications->value,
            Capability::RespondPatientCommunications->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
        'executive' => [Capability::MobileRead->value, Capability::MobileAct->value],
        'user' => [Capability::MobileRead->value, Capability::MobileAct->value],
    ],

    // These identities retain cross-enterprise scope for compatibility. Every
    // other principal must have an effective organization/facility scope row.
    'global_scope_roles' => ['super_admin', 'superuser'],

    // Workforce assignments may establish facility scope only for operational
    // capabilities. They can never grant identity, privilege, source-config,
    // credential, approval, stewardship, audit, or facility-admin authority.
    'workforce_scoped_capabilities' => [
        Capability::ManageStaffingOperations->value,
        Capability::RequestTransportOperations->value,
        Capability::ManageTransportDispatch->value,
        Capability::ProgressTransportOperations->value,
        Capability::ViewPatientCommunications->value,
        Capability::RespondPatientCommunications->value,
        Capability::ViewEncounterCarePathway->value,
        Capability::ManageCarePathwayInstances->value,
        Capability::UseEddyActions->value,
        Capability::WriteOrCases->value,
        Capability::MobileRead->value,
        Capability::MobileAct->value,
    ],

    /*
    | Capability catalog metadata
    |--------------------------------------------------------------------------
    | The read-only Roles / Capabilities surface derives its catalog from these
    | exhaustive groups. They describe policy; they do not grant authority.
    */
    'capability_domains' => [
        'Administration' => [
            Capability::ViewAdministration->value,
            Capability::ViewAuthorization->value,
            Capability::ViewSystemHealth->value,
            Capability::RunDiagnostics->value,
        ],
        'Identity and access' => [
            Capability::ViewIdentity->value,
            Capability::ManageIdentity->value,
            Capability::ManagePrivileges->value,
            Capability::ViewAudit->value,
            Capability::ViewAccessReviews->value,
            Capability::ManageAccessReviews->value,
        ],
        'Enterprise configuration' => [
            Capability::ViewEnterpriseSetup->value,
            Capability::ManageEnterpriseSetup->value,
            Capability::ManageFacilityAdministration->value,
        ],
        'Healthcare interoperability' => [
            Capability::ViewIntegrations->value,
            Capability::ManageIntegrationConfiguration->value,
            Capability::OperateIntegrations->value,
            Capability::ApproveIntegrationChanges->value,
            Capability::RotateIntegrationCredentials->value,
            Capability::ExecuteDestructiveReplay->value,
            Capability::ManageOutboundPolicy->value,
            Capability::ManageDataStewardship->value,
        ],
        'Operational policy governance' => [
            Capability::ViewCockpitPolicy->value,
            Capability::ManageCockpitPolicy->value,
            Capability::ViewAiGovernance->value,
            Capability::ManageAiGovernance->value,
        ],
        'Care pathway governance' => [
            Capability::ViewCarePathwayCatalog->value,
            Capability::AdoptCarePathwaySource->value,
            Capability::AuthorCarePathwayContent->value,
            Capability::ReviewCarePathwayEvidence->value,
            Capability::ApproveCarePathwayClinical->value,
            Capability::ActivateCarePathwayCatalog->value,
            Capability::ViewEncounterCarePathway->value,
            Capability::ManageCarePathwayInstances->value,
        ],
        'Healthcare operations' => [
            Capability::ManageStaffingOperations->value,
            Capability::RequestTransportOperations->value,
            Capability::ManageTransportDispatch->value,
            Capability::ProgressTransportOperations->value,
            Capability::ViewPatientCommunications->value,
            Capability::RespondPatientCommunications->value,
            Capability::UseEddyActions->value,
            Capability::WriteOrCases->value,
        ],
        'Mobile' => [
            Capability::AssumeAnyMobilePersona->value,
            Capability::MobileRead->value,
            Capability::MobileAct->value,
        ],
    ],

    'global_only_capabilities' => [
        Capability::ViewAdministration->value,
        Capability::ViewAuthorization->value,
        Capability::ViewSystemHealth->value,
        Capability::RunDiagnostics->value,
        Capability::ViewIdentity->value,
        Capability::ManageIdentity->value,
        Capability::ManagePrivileges->value,
        Capability::ViewAudit->value,
        Capability::ViewAccessReviews->value,
        Capability::ManageAccessReviews->value,
        Capability::ViewCockpitPolicy->value,
        Capability::ManageCockpitPolicy->value,
        Capability::ViewAiGovernance->value,
        Capability::ManageAiGovernance->value,
        Capability::ViewCarePathwayCatalog->value,
        Capability::AdoptCarePathwaySource->value,
        Capability::AuthorCarePathwayContent->value,
        Capability::ReviewCarePathwayEvidence->value,
        Capability::ApproveCarePathwayClinical->value,
        Capability::ActivateCarePathwayCatalog->value,
    ],

    'mobile_ability_map' => [
        Capability::MobileRead->value => 'mobile:read',
        Capability::MobileAct->value => 'mobile:act',
    ],
];
