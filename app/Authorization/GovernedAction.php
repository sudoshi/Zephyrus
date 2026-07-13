<?php

namespace App\Authorization;

enum GovernedAction: string
{
    case ActivateProductionSource = 'activate_production_source';
    case ScheduleProductionSourceActivation = 'schedule_production_source_activation';
    case ApplySourceConfiguration = 'apply_source_configuration';
    case RotateIntegrationCredential = 'rotate_integration_credential';
    case ExecuteDestructiveReplay = 'execute_destructive_replay';
    case ChangeOutboundDispatchPolicy = 'change_outbound_dispatch_policy';
    case ReleaseQuarantinedPayload = 'release_quarantined_payload';
    case ApplyClinicalPayloadHold = 'apply_clinical_payload_hold';
    case ReleaseClinicalPayloadHold = 'release_clinical_payload_hold';
    case PurgeClinicalPayload = 'purge_clinical_payload';
    case PurgeQuarantinedPayload = 'purge_quarantined_payload';
    case RecoverClinicalPayloadIntegrity = 'recover_clinical_payload_integrity';
    case PurgeUserIdentity = 'purge_user_identity';
    case ApplyCockpitThresholdPolicy = 'apply_cockpit_threshold_policy';
    case ApplyAiProviderPolicy = 'apply_ai_provider_policy';
    case ApplyEnterpriseRegistryImport = 'apply_enterprise_registry_import';

    public function authorCapability(): Capability
    {
        return match ($this) {
            self::ActivateProductionSource,
            self::ScheduleProductionSourceActivation,
            self::ApplySourceConfiguration => Capability::ManageIntegrationConfiguration,
            self::RotateIntegrationCredential => Capability::RotateIntegrationCredentials,
            self::ExecuteDestructiveReplay => Capability::ExecuteDestructiveReplay,
            self::ChangeOutboundDispatchPolicy => Capability::ManageOutboundPolicy,
            self::ReleaseQuarantinedPayload => Capability::OperateIntegrations,
            self::ApplyClinicalPayloadHold,
            self::ReleaseClinicalPayloadHold,
            self::PurgeClinicalPayload,
            self::PurgeQuarantinedPayload,
            self::RecoverClinicalPayloadIntegrity => Capability::ManageDataStewardship,
            self::PurgeUserIdentity => Capability::ManageIdentity,
            self::ApplyCockpitThresholdPolicy => Capability::ManageCockpitPolicy,
            self::ApplyAiProviderPolicy => Capability::ManageAiGovernance,
            self::ApplyEnterpriseRegistryImport => Capability::ManageEnterpriseSetup,
        };
    }

    public function approvalCapability(): Capability
    {
        return match ($this) {
            self::PurgeUserIdentity => Capability::ManagePrivileges,
            self::ApplyCockpitThresholdPolicy => Capability::ManageCockpitPolicy,
            self::ApplyAiProviderPolicy => Capability::ManageAiGovernance,
            self::ApplyEnterpriseRegistryImport => Capability::ManageEnterpriseSetup,
            default => Capability::ApproveIntegrationChanges,
        };
    }
}
