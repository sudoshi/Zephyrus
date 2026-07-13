<?php

namespace App\Authorization;

/**
 * Stable application capabilities. Roles, workforce assignments, personas,
 * Laravel Gates, and token abilities are inputs or adapters; none of them are
 * the authorization contract itself.
 */
enum Capability: string
{
    case ViewAdministration = 'viewAdministration';
    case ViewIdentity = 'viewIdentity';
    case ManageIdentity = 'manageIdentity';
    case ManagePrivileges = 'managePrivileges';
    case ViewAudit = 'viewAudit';
    case ViewAccessReviews = 'viewAccessReviews';
    case ManageAccessReviews = 'manageAccessReviews';
    case ViewAuthorization = 'viewAuthorization';
    case ViewSystemHealth = 'viewSystemHealth';
    case RunDiagnostics = 'runDiagnostics';
    case ViewEnterpriseSetup = 'viewEnterpriseSetup';
    case ManageEnterpriseSetup = 'manageEnterpriseSetup';
    case ManageFacilityAdministration = 'manageFacilityAdministration';
    case ViewIntegrations = 'viewIntegrations';
    case ManageIntegrationConfiguration = 'manageIntegrationConfiguration';
    case OperateIntegrations = 'operateIntegrations';
    case ApproveIntegrationChanges = 'approveIntegrationChanges';
    case RotateIntegrationCredentials = 'rotateIntegrationCredentials';
    case ExecuteDestructiveReplay = 'executeDestructiveReplay';
    case ManageOutboundPolicy = 'manageOutboundPolicy';
    case ManageDataStewardship = 'manageDataStewardship';
    case ManageStaffingOperations = 'manageStaffingOperations';
    case RequestTransportOperations = 'requestTransportOperations';
    case ManageTransportDispatch = 'manageTransportDispatch';
    case ProgressTransportOperations = 'progressTransportOperations';
    case UseEddyActions = 'useEddyActions';
    case WriteOrCases = 'writeOrCases';
    case AssumeAnyMobilePersona = 'assumeAnyMobilePersona';
    case MobileRead = 'mobileRead';
    case MobileAct = 'mobileAct';

    public static function fromName(self|string $capability): self
    {
        if ($capability instanceof self) {
            return $capability;
        }

        return self::from($capability);
    }
}
