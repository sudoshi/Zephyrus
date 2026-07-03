<?php

namespace Tests\Feature;

use App\Services\Mobile\MobilePersonaCatalog;
use Tests\TestCase;

class MobileRoleCatalogParityTest extends TestCase
{
    private const CANONICAL_CATALOG = 'docs/hummingbird/role-catalog.v1.json';

    private const IOS_ROLE = 'hummingbird/iosApp/Hummingbird/Features/Onboarding/Role.swift';

    private const IOS_ROLE_EXPERIENCE = 'hummingbird/iosApp/Hummingbird/Features/Onboarding/RoleExperience.swift';

    private const ANDROID_MODELS = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/data/Models.kt';

    private const ANDROID_API_CLIENT = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/data/ApiClient.kt';

    private const ANDROID_ALTITUDE_VIEW_MODEL = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/data/AltitudeViewModel.kt';

    private const ANDROID_MAIN_SCREEN = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/MainScreen.kt';

    private const ANDROID_MAIN_ACTIVITY = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/MainActivity.kt';

    private const ANDROID_FOR_YOU_SCREEN = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/ForYouScreen.kt';

    private const ANDROID_ALTITUDE_SCREENS = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/altitude/AltitudeScreens.kt';

    private const ANDROID_COMPONENTS_DIR = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/components';

    private const ANDROID_ROLE_PACKAGE_DIRS = [
        'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/transport',
        'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/evs',
        'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/rtdc',
        'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/capacity',
        'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/or',
        'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/executive',
        'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/staffing',
        'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/improvement',
        'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/altitude',
    ];

    private const ANDROID_THEME = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/theme/ZephyrusColors.kt';

    private const ANDROID_PANEL = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/components/Panel.kt';

    private const ANDROID_RETRYABLE_MESSAGE = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/components/RetryableMessage.kt';

    private const ANDROID_STATUS_CHIP = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/components/StatusChip.kt';

    private const ANDROID_TRANSPORT_SCREEN = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/transport/TransportScreens.kt';

    private const ANDROID_TRANSPORT_VIEW_MODEL = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/transport/TransportViewModel.kt';

    private const ANDROID_EVS_SCREEN = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/evs/EvsScreens.kt';

    private const ANDROID_EVS_VIEW_MODEL = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/evs/EvsViewModel.kt';

    private const MOBILE_EVS_CONTROLLER = 'app/Http/Controllers/Api/Mobile/EvsController.php';

    private const EVS_REQUEST_MODEL = 'app/Models/Evs/EvsRequest.php';

    private const PERSONA_RELAY_POLICY = 'app/Services/Mobile/PersonaRelayPolicy.php';

    private const MOBILE_RTDC_CONTROLLER = 'app/Http/Controllers/Api/Mobile/RtdcController.php';

    private const MOBILE_FOR_YOU_SERVICE = 'app/Services/Mobile/MobileForYouService.php';

    private const MOBILE_OPS_CONTROLLER = 'app/Http/Controllers/Api/Mobile/OpsController.php';

    private const MOBILE_STAFFING_CONTROLLER = 'app/Http/Controllers/Api/Mobile/StaffingController.php';

    private const MOBILE_PATIENT_CONTEXT_SERVICE = 'app/Services/Mobile/MobilePatientContextService.php';

    private const MOBILE_OR_CONTROLLER = 'app/Http/Controllers/Api/Mobile/ORController.php';

    private const MOBILE_COMMAND_CONTROLLER = 'app/Http/Controllers/Api/Mobile/CommandController.php';

    private const MOBILE_IMPROVEMENT_CONTROLLER = 'app/Http/Controllers/Api/Mobile/ImprovementController.php';

    private const ANDROID_CAPACITY_SCREEN = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/capacity/HouseCapacityScreens.kt';

    private const ANDROID_CAPACITY_VIEW_MODEL = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/capacity/HouseCapacityViewModel.kt';

    private const ANDROID_CAPACITY_DEMAND_SCREEN = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/capacity/CapacityDemandScreens.kt';

    private const ANDROID_CAPACITY_DEMAND_VIEW_MODEL = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/capacity/CapacityDemandViewModel.kt';

    private const ANDROID_OR_SCREEN = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/or/ORScreens.kt';

    private const ANDROID_OR_VIEW_MODEL = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/or/ORViewModel.kt';

    private const ANDROID_EXECUTIVE_SCREEN = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/executive/ExecutiveScreens.kt';

    private const ANDROID_EXECUTIVE_VIEW_MODEL = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/executive/ExecutiveViewModel.kt';

    private const ANDROID_STAFFING_SCREEN = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/staffing/StaffingScreens.kt';

    private const ANDROID_STAFFING_VIEW_MODEL = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/staffing/StaffingViewModel.kt';

    private const ANDROID_IMPROVEMENT_SCREEN = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/improvement/ImprovementScreens.kt';

    private const ANDROID_IMPROVEMENT_VIEW_MODEL = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/improvement/ImprovementViewModel.kt';

    private const IOS_THEME = 'hummingbird/iosApp/Hummingbird/DesignSystem/Theme.swift';

    private const IOS_FOR_YOU_VIEW = 'hummingbird/iosApp/Hummingbird/Features/ForYou/ForYouView.swift';

    private const IOS_CAPACITY_DEMAND_VIEW = 'hummingbird/iosApp/Hummingbird/Features/Capacity/CapacityDemandView.swift';

    private const IOS_STAFFING_VIEW = 'hummingbird/iosApp/Hummingbird/Features/Staffing/StaffingView.swift';

    private const IOS_IMPROVEMENT_VIEW = 'hummingbird/iosApp/Hummingbird/Features/Improvement/ImprovementView.swift';

    private const IOS_ALTITUDE_COMPONENTS = 'hummingbird/iosApp/Hummingbird/Features/Altitude/AltitudeComponents.swift';

    private const MOBILE_ALTITUDE_SERVICE = 'app/Services/Mobile/MobileAltitudeService.php';

    public function test_all_role_catalogs_contain_the_same_role_ids(): void
    {
        $expected = $this->canonicalRoleIds();

        $this->assertSame($expected, $this->sorted(MobilePersonaCatalog::ROLE_IDS), 'Backend MobilePersonaCatalog drifted.');
        $this->assertSame($expected, $this->swiftRoleIds(), 'iOS Role.swift drifted.');
        $this->assertSame($expected, $this->swiftRoleExperienceIds(), 'iOS RoleExperience.swift drifted.');
        $this->assertSame($expected, $this->androidRoleIds(), 'Android MobileRoleCatalog drifted.');
    }

    public function test_canonical_role_catalog_contains_required_phase_one_fields(): void
    {
        $required = [
            'role_id',
            'title',
            'subtitle',
            'unit_bound',
            'home_kind',
            'default_domain',
            'queue_filter',
            'glance_question',
            'web_deeplink',
            'ios_icon',
            'android_icon',
        ];
        $homeKinds = $this->canonicalHomeKinds();
        $queueFilters = ['all', 'placements', 'escalations', 'myUnit', 'criticalCare', 'turns', 'none'];

        foreach ($this->canonicalRoles() as $role) {
            foreach ($required as $field) {
                $this->assertArrayHasKey($field, $role, "Missing {$field} for {$role['role_id']}.");
            }

            $this->assertIsBool($role['unit_bound'], "{$role['role_id']} unit_bound must be boolean.");
            $this->assertContains($role['home_kind'], $homeKinds, "{$role['role_id']} home_kind is not canonical.");
            $this->assertContains($role['queue_filter'], $queueFilters, "{$role['role_id']} queue_filter is not canonical.");
            $this->assertNotSame('', $role['web_deeplink'], "{$role['role_id']} needs a web_deeplink.");
            $this->assertStringStartsWith('/', $role['web_deeplink'], "{$role['role_id']} web_deeplink must be app-relative.");
        }
    }

    public function test_home_kind_values_are_canonical_across_backend_ios_and_android(): void
    {
        $expectedHomeKinds = $this->canonicalHomeKinds();

        $this->assertSame(
            $expectedHomeKinds,
            $this->swiftHomeKinds(),
            'iOS RoleExperience.HomeKind drifted from role-catalog.v1.json.',
        );
        $this->assertSame(
            $expectedHomeKinds,
            array_values($this->androidHomeKindWireValues()),
            'Android HomeKind wire values drifted from role-catalog.v1.json.',
        );

        $expectedByRole = collect($this->canonicalRoles())->pluck('home_kind', 'role_id')->sortKeys()->all();
        $backendByRole = collect($this->canonicalRoleIds())
            ->mapWithKeys(fn (string $roleId): array => [$roleId => app(MobilePersonaCatalog::class)->describe($roleId)['home']])
            ->all();

        $this->assertSame($expectedByRole, $backendByRole, 'Backend MobilePersonaCatalog home values drifted.');
        $this->assertSame($expectedByRole, $this->swiftRoleExperienceHomeKinds(), 'iOS RoleExperience role homes drifted.');
        $this->assertSame($expectedByRole, $this->androidRoleHomeKinds(), 'Android MobileRoleCatalog home kinds drifted.');
    }

    public function test_android_role_icon_names_match_the_shared_catalog(): void
    {
        $expectedByRole = collect($this->canonicalRoles())->pluck('android_icon', 'role_id')->sortKeys()->all();

        $this->assertSame($expectedByRole, $this->androidRoleIconNames(), 'Android MobileRoleCatalog icon names drifted.');
    }

    public function test_android_role_onboarding_fields_match_the_shared_catalog(): void
    {
        $expectedSubtitlesByRole = collect($this->canonicalRoles())->pluck('subtitle', 'role_id')->sortKeys()->all();
        $expectedUnitBoundByRole = collect($this->canonicalRoles())->pluck('unit_bound', 'role_id')->sortKeys()->all();
        $expectedQueueFiltersByRole = collect($this->canonicalRoles())->pluck('queue_filter', 'role_id')->sortKeys()->all();

        $this->assertSame($expectedSubtitlesByRole, $this->androidRoleSubtitles(), 'Android MobileRoleCatalog subtitles drifted.');
        $this->assertSame($expectedUnitBoundByRole, $this->androidRoleUnitBoundFlags(), 'Android MobileRoleCatalog unit_bound flags drifted.');
        $this->assertSame($expectedQueueFiltersByRole, $this->androidRoleQueueFilters(), 'Android MobileRoleCatalog queue filters drifted.');
    }

    public function test_feature_route_values_are_canonical_for_android_workspace_domains(): void
    {
        $workspaceRoutes = collect($this->canonicalFeatureRoutes())
            ->where('kind', 'workspace')
            ->pluck('id')
            ->values()
            ->all();

        $this->assertSame($workspaceRoutes, $this->androidWorkspaceDomains());
    }

    public function test_android_role_package_structure_is_pinned(): void
    {
        $mainScreen = file_get_contents(base_path(self::ANDROID_MAIN_SCREEN));
        $altitudeScreens = file_get_contents(base_path(self::ANDROID_ALTITUDE_SCREENS));

        foreach (self::ANDROID_ROLE_PACKAGE_DIRS as $dir) {
            $this->assertDirectoryExists(base_path($dir), "Missing Android role package {$dir}.");
        }

        $this->assertDirectoryExists(base_path(self::ANDROID_COMPONENTS_DIR), 'Missing shared Android components package.');

        foreach ([self::ANDROID_PANEL, self::ANDROID_RETRYABLE_MESSAGE, self::ANDROID_STATUS_CHIP] as $path) {
            $this->assertFileExists(base_path($path), "Missing shared Android component {$path}.");
        }

        $this->assertStringContainsString('package net.acumenus.hummingbird.ui.altitude', $altitudeScreens);

        foreach ([
            'AltitudeHomeScreen',
            'ActivityFeedScreen',
            'DebugAltitudeExplorerScreen',
            'DrillDetailScreen',
            'PatientContextScreen',
            'EddyContextScreen',
        ] as $screen) {
            $this->assertStringContainsString("fun {$screen}(", $altitudeScreens);
            $this->assertStringContainsString("import net.acumenus.hummingbird.ui.altitude.{$screen}", $mainScreen);
        }

        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.components.RetryableMessage', $altitudeScreens);
        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.components.StatusChip', $altitudeScreens);
        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.components.panel', $mainScreen);
        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.components.panel', $altitudeScreens);
        $this->assertStringContainsString('HomeKind.Census -> AltitudeHomeScreen(', $mainScreen);
        $this->assertStringNotContainsString('else -> AltitudeHomeScreen(', $mainScreen);
        $this->assertStringNotContainsString('AltitudeWorkspaceScreen(', $mainScreen);
        $this->assertStringNotContainsString('AltitudeWorkspaceScreen(', $altitudeScreens);
    }

    public function test_android_transport_package_matches_ios_wave_one_contract(): void
    {
        $models = file_get_contents(base_path(self::ANDROID_MODELS));
        $apiClient = file_get_contents(base_path(self::ANDROID_API_CLIENT));
        $mainScreen = file_get_contents(base_path(self::ANDROID_MAIN_SCREEN));
        $transportScreen = file_get_contents(base_path(self::ANDROID_TRANSPORT_SCREEN));
        $transportViewModel = file_get_contents(base_path(self::ANDROID_TRANSPORT_VIEW_MODEL));

        foreach (['TransportMetrics', 'TransportSla', 'TransportJob', 'TransportQueue'] as $dto) {
            $this->assertStringContainsString("data class {$dto}", $models);
        }

        foreach (['transportQueue', 'transportStatus', 'transportHandoff', 'parseTransportQueue', 'parseTransportJob'] as $method) {
            $this->assertStringContainsString("fun {$method}", $apiClient);
        }

        $this->assertStringContainsString('class TransportViewModel(app: Application) : AndroidViewModel(app)', $transportViewModel);
        $this->assertStringContainsString('api.transportQueue(bearer)', $transportViewModel);
        $this->assertStringContainsString('api.transportStatus(bearer, id, status)', $transportViewModel);
        $this->assertStringContainsString('api.transportHandoff(bearer, id, handoffTo, summary)', $transportViewModel);
        $this->assertStringContainsString('fun claim(bearer: String, id: Int)', $transportViewModel);
        $this->assertStringContainsString('api.transportStatus(bearer, id, "assigned")', $transportViewModel);

        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.transport.TransportJobsScreen', $mainScreen);
        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.transport.TransportJobDetailScreen', $mainScreen);
        $this->assertStringContainsString('data class Transport(val job: TransportJob, val webLink: String?) : AltitudeDetail', $mainScreen);
        $this->assertStringContainsString('HomeKind.TransportJobs -> TransportJobsScreen(', $mainScreen);
        $this->assertStringContainsString('TransportJobDetailScreen(', $mainScreen);

        foreach (['fun TransportJobsScreen(', 'fun TransportJobDetailScreen(', 'fun HandoffSheet(', 'fun JobPriorityChip('] as $symbol) {
            $this->assertStringContainsString($symbol, $transportScreen);
        }

        $this->assertStringContainsString('title = "Can\'t load trips"', $transportScreen);
        $this->assertStringContainsString('title = "Queue clear"', $transportScreen);
        $this->assertStringContainsString('val myTrips = claimedTransportJobs(queue.jobs)', $transportScreen);
        $this->assertStringContainsString('val availableTrips = availableTransportJobs(queue.jobs)', $transportScreen);
        $this->assertStringContainsString('TransportSectionLabel("My trips (${myTrips.size})")', $transportScreen);
        $this->assertStringContainsString('TransportSectionLabel("Available trips (${availableTrips.size})")', $transportScreen);
        $this->assertStringContainsString('TransportInlineAction("Claim", vm.workingJobId == job.id)', $transportScreen);
        $this->assertStringContainsString('vm.claim(bearer, job.id)', $transportScreen);
        $this->assertStringContainsString('private fun isTransportClaimable(status: String): Boolean', $transportScreen);
        $this->assertStringContainsString('Text("Structured handoff"', $transportScreen);
        $this->assertStringContainsString('Text("Explain trip signal")', $transportScreen);
        $this->assertStringContainsString('Text("Open transport-safe patient context")', $transportScreen);
        $this->assertStringContainsString('fontFamily = FontFamily.Monospace', $transportScreen);
        $this->assertStringContainsString('Icon(status.icon', $transportScreen);
        $this->assertStringContainsString('Text(job.priority.uppercase()', $transportScreen);
    }

    public function test_android_evs_package_matches_ios_wave_one_contract(): void
    {
        $models = file_get_contents(base_path(self::ANDROID_MODELS));
        $apiClient = file_get_contents(base_path(self::ANDROID_API_CLIENT));
        $mainScreen = file_get_contents(base_path(self::ANDROID_MAIN_SCREEN));
        $evsScreen = file_get_contents(base_path(self::ANDROID_EVS_SCREEN));
        $evsViewModel = file_get_contents(base_path(self::ANDROID_EVS_VIEW_MODEL));
        $evsController = file_get_contents(base_path(self::MOBILE_EVS_CONTROLLER));
        $evsModel = file_get_contents(base_path(self::EVS_REQUEST_MODEL));
        $relayPolicy = file_get_contents(base_path(self::PERSONA_RELAY_POLICY));

        foreach (['EvsMetrics', 'EvsSla', 'EvsTurn', 'EvsQueue'] as $dto) {
            $this->assertStringContainsString("data class {$dto}", $models);
        }

        foreach (['evsQueue', 'evsStatus', 'parseEvsQueue', 'parseEvsTurn'] as $method) {
            $this->assertStringContainsString("fun {$method}", $apiClient);
        }

        $this->assertStringContainsString('class EvsViewModel(app: Application) : AndroidViewModel(app)', $evsViewModel);
        $this->assertStringContainsString('api.evsQueue(bearer)', $evsViewModel);
        $this->assertStringContainsString('api.evsStatus(bearer, id, status)', $evsViewModel);
        $this->assertStringContainsString('queue = api.evsQueue(bearer)', $evsViewModel);

        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.evs.BedTurnsScreen', $mainScreen);
        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.evs.TurnDetailScreen', $mainScreen);
        $this->assertStringContainsString('data class Evs(val turn: EvsTurn, val webLink: String?) : AltitudeDetail', $mainScreen);
        $this->assertStringContainsString('HomeKind.EvsTurns -> BedTurnsScreen(', $mainScreen);
        $this->assertStringContainsString('TurnDetailScreen(', $mainScreen);

        foreach (['fun BedTurnsScreen(', 'fun TurnDetailScreen(', 'fun TurnPriorityChip(', 'fun IsolationBadge('] as $symbol) {
            $this->assertStringContainsString($symbol, $evsScreen);
        }

        $this->assertStringContainsString('title = "Can\'t load turns"', $evsScreen);
        $this->assertStringContainsString('title = "All clear"', $evsScreen);
        $this->assertStringContainsString('next dirty bed first', $evsScreen);
        $this->assertStringContainsString('Text("Explain turn signal")', $evsScreen);
        $this->assertStringContainsString('Text("Open operational dependency context")', $evsScreen);
        $this->assertStringContainsString('Text("Isolation clean - PPE required"', $evsScreen);
        $this->assertStringContainsString('unableEvsAction(status)', $evsScreen);
        $this->assertStringContainsString('EvsAction("Unable to clean", "failed")', $evsScreen);
        $this->assertStringContainsString('evsTerminalMessage(status)', $evsScreen);
        $this->assertStringContainsString('Unable to clean - dispatcher alerted', $evsScreen);
        $this->assertStringContainsString('evsTerminalTone(status)', $evsScreen);
        $this->assertStringContainsString('FontFamily.Monospace', $evsScreen);
        $this->assertStringContainsString('Icon(status.icon', $evsScreen);
        $this->assertStringContainsString('Text(turn.priority.uppercase()', $evsScreen);

        $this->assertStringContainsString("array_merge(EvsOperationsService::ACTIVE_STATUSES, ['completed', 'canceled', 'failed'])", $evsController);
        $this->assertStringContainsString("'completed' => 'evs.completed'", $evsController);
        $this->assertStringContainsString("->whereNotIn('status', ['completed', 'canceled', 'failed'])", $evsModel);
        $this->assertStringContainsString("'notify_now' => \$eventType === 'evs.completed' ? ['bed_manager', 'charge_nurse'] : []", $relayPolicy);
    }

    public function test_android_house_capacity_package_matches_ios_wave_one_contract(): void
    {
        $models = file_get_contents(base_path(self::ANDROID_MODELS));
        $apiClient = file_get_contents(base_path(self::ANDROID_API_CLIENT));
        $mainScreen = file_get_contents(base_path(self::ANDROID_MAIN_SCREEN));
        $capacityScreen = file_get_contents(base_path(self::ANDROID_CAPACITY_SCREEN));
        $capacityViewModel = file_get_contents(base_path(self::ANDROID_CAPACITY_VIEW_MODEL));
        $rtdcController = file_get_contents(base_path(self::MOBILE_RTDC_CONTROLLER));

        foreach ([
            'HouseOccupancy',
            'HouseRollup',
            'Placement',
            'PlacementRecommendations',
            'PlacementRecommendation',
            'PlacementChip',
            'PlacementDecisionResult',
        ] as $dto) {
            $this->assertStringContainsString("data class {$dto}", $models);
        }

        foreach (['rtdcHouse', 'placements', 'placementRecommendations', 'placeBed', 'parseHouseRollup', 'parsePlacement', 'parsePlacementRecommendations'] as $method) {
            $this->assertStringContainsString("fun {$method}", $apiClient);
        }

        $this->assertStringContainsString('class HouseCapacityViewModel(app: Application) : AndroidViewModel(app)', $capacityViewModel);
        $this->assertStringContainsString('house = api.rtdcHouse(bearer)', $capacityViewModel);
        $this->assertStringContainsString('placements = api.placements(bearer)', $capacityViewModel);
        $this->assertStringContainsString('recommendations = api.placementRecommendations(bearer, placementId)', $capacityViewModel);
        $this->assertStringContainsString('api.placeBed(bearer, placementId, action, chosenBedId)', $capacityViewModel);
        $this->assertStringContainsString('recommendations = null', $capacityViewModel);

        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.capacity.HouseCapacityScreen', $mainScreen);
        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.capacity.PlacementDetailScreen', $mainScreen);
        $this->assertStringContainsString('data class PlacementDecision(val placement: Placement) : AltitudeDetail', $mainScreen);
        $this->assertStringContainsString('HomeKind.HouseCapacity -> HouseCapacityScreen(', $mainScreen);
        $this->assertStringContainsString('PlacementDetailScreen(', $mainScreen);

        foreach (['fun HouseCapacityScreen(', 'fun PlacementDetailScreen('] as $symbol) {
            $this->assertStringContainsString($symbol, $capacityScreen);
        }

        foreach (['House occupancy', 'net bed need', 'placements', 'ED boarding', 'Units under pressure'] as $copy) {
            $this->assertStringContainsString($copy, $capacityScreen);
        }

        $this->assertStringContainsString('val sortedPlacements = priorityPlacements(vm.placements)', $capacityScreen);
        $this->assertStringContainsString('Pending placements (${sortedPlacements.size}) / highest risk, oldest first', $capacityScreen);
        $this->assertStringContainsString('compareByDescending<Placement> { it.capacity.severity }', $capacityScreen);
        $this->assertStringContainsString('.thenBy { it.at ?: "" }', $capacityScreen);
        $this->assertStringContainsString('Text("Waiting $it"', $capacityScreen);
        $this->assertStringContainsString('onPlace = { vm.decide(bearer, placement.id, "accepted", top.bedId, onBack) }', $capacityScreen);
        $this->assertStringContainsString('onReject = { vm.decide(bearer, placement.id, "rejected", null, onBack) }', $capacityScreen);
        $this->assertStringContainsString('Text("Recommended bed"', $capacityScreen);
        $this->assertStringContainsString('Text("Place in ${top.bedLabel}"', $capacityScreen);
        $this->assertStringContainsString('Text("Reject request"', $capacityScreen);
        $this->assertStringContainsString('Text("Explain placement signal")', $capacityScreen);
        $this->assertStringContainsString('Text("Open patient context")', $capacityScreen);
        $this->assertStringContainsString('FontFamily.Monospace', $capacityScreen);
        $this->assertStringNotContainsString('patient_ref', $capacityScreen);

        $this->assertStringContainsString("BedRequest::pending()->orderBy('created_at')->get()", $rtdcController);
        $this->assertStringContainsString("\$this->ledger->record(\$validated['action'] === 'accepted' ? 'bed_request.placed' : 'recommendation.rejected'", $rtdcController);
        $this->assertStringContainsString("'status' => \$fresh->status", $rtdcController);
    }

    public function test_android_or_package_matches_ios_wave_two_contract(): void
    {
        $models = file_get_contents(base_path(self::ANDROID_MODELS));
        $apiClient = file_get_contents(base_path(self::ANDROID_API_CLIENT));
        $mainScreen = file_get_contents(base_path(self::ANDROID_MAIN_SCREEN));
        $orScreen = file_get_contents(base_path(self::ANDROID_OR_SCREEN));
        $orViewModel = file_get_contents(base_path(self::ANDROID_OR_VIEW_MODEL));
        $orController = file_get_contents(base_path(self::MOBILE_OR_CONTROLLER));

        foreach (['ORBoard', 'ORMetrics', 'ORRoom', 'ORCaseInfo', 'ORNextInfo'] as $dto) {
            $this->assertStringContainsString("data class {$dto}", $models);
        }

        foreach (['orBoard', 'parseORBoard', 'parseORRoom', 'parseORCaseInfo', 'parseORNextInfo'] as $method) {
            $this->assertStringContainsString("fun {$method}", $apiClient);
        }

        $this->assertStringContainsString('class ORViewModel(app: Application) : AndroidViewModel(app)', $orViewModel);
        $this->assertStringContainsString('board = api.orBoard(bearer)', $orViewModel);

        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.or.ORBoardScreen', $mainScreen);
        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.or.CaseDetailScreen', $mainScreen);
        $this->assertStringContainsString('data class ORCase(val room: ORRoom, val webLink: String?) : AltitudeDetail', $mainScreen);
        $this->assertStringContainsString('HomeKind.OrBoard -> ORBoardScreen(', $mainScreen);
        $this->assertStringContainsString('CaseDetailScreen(', $mainScreen);

        foreach (['fun ORBoardScreen(', 'fun CaseDetailScreen(', 'private fun ORMetricsRow(', 'private fun ORRoomRow(', 'private fun ORReadOnlyActions('] as $symbol) {
            $this->assertStringContainsString($symbol, $orScreen);
        }

        $this->assertStringContainsString('title = { Text("OR Board"', $orScreen);
        $this->assertStringContainsString('Text("Safety note acknowledgement not available on mobile yet")', $orScreen);
        $this->assertStringContainsString('Text("Room and delay status changes are read-only")', $orScreen);
        $this->assertStringContainsString('LinearProgressIndicator(', $orScreen);
        $this->assertStringContainsString('currentCaseProgress(current)', $orScreen);
        $this->assertStringContainsString('roomStatusLabel(room.status)', $orScreen);
        $this->assertStringContainsString('GET /api/mobile/v1/or/board', $orController);
        $this->assertStringNotContainsString('patient_ref', $orScreen);
    }

    public function test_android_capacity_demand_package_matches_ios_wave_two_contract(): void
    {
        $models = file_get_contents(base_path(self::ANDROID_MODELS));
        $apiClient = file_get_contents(base_path(self::ANDROID_API_CLIENT));
        $mainScreen = file_get_contents(base_path(self::ANDROID_MAIN_SCREEN));
        $capacityDemandScreen = file_get_contents(base_path(self::ANDROID_CAPACITY_DEMAND_SCREEN));
        $capacityDemandViewModel = file_get_contents(base_path(self::ANDROID_CAPACITY_DEMAND_VIEW_MODEL));
        $commandController = file_get_contents(base_path(self::MOBILE_COMMAND_CONTROLLER));
        $opsController = file_get_contents(base_path(self::MOBILE_OPS_CONTROLLER));

        foreach (['HouseBrief', 'ExecStrain', 'StrainDriver', 'HeroKpi', 'OpsApproval'] as $dto) {
            $this->assertStringContainsString("data class {$dto}", $models);
        }

        foreach (['commandHouse', 'opsInbox', 'opsDecision', 'parseHouseBrief', 'parseExecStrain', 'parseStrainDriver', 'parseHeroKpi', 'parseOpsApproval'] as $method) {
            $this->assertStringContainsString("fun {$method}", $apiClient);
        }

        $this->assertStringContainsString('class CapacityDemandViewModel(app: Application) : AndroidViewModel(app)', $capacityDemandViewModel);
        $this->assertStringContainsString('runCatching { api.commandHouse(bearer) }', $capacityDemandViewModel);
        $this->assertStringContainsString('.onSuccess { brief = it }', $capacityDemandViewModel);
        $this->assertStringContainsString('approvals = api.opsInbox(bearer)', $capacityDemandViewModel);
        $this->assertStringContainsString('api.opsDecision(bearer, approval.approvalUuid, decision)', $capacityDemandViewModel);
        $this->assertStringContainsString('workingApprovalIds = workingApprovalIds + approval.approvalUuid', $capacityDemandViewModel);
        $this->assertStringContainsString('workingApprovalIds = workingApprovalIds - approval.approvalUuid', $capacityDemandViewModel);

        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.capacity.CapacityDemandScreen', $mainScreen);
        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.capacity.ApprovalDetailScreen', $mainScreen);
        $this->assertStringContainsString('data class Approval(val approval: OpsApproval, val webLink: String?) : AltitudeDetail', $mainScreen);
        $this->assertStringContainsString('HomeKind.CapacityDemand -> CapacityDemandScreen(', $mainScreen);
        $this->assertStringContainsString('ApprovalDetailScreen(', $mainScreen);

        foreach (['fun CapacityDemandScreen(', 'fun ApprovalDetailScreen(', 'private fun StrainHeader(', 'private fun HeroKpiRow(', 'private fun ApprovalRow(', 'private fun ApprovalActionBar('] as $symbol) {
            $this->assertStringContainsString($symbol, $capacityDemandScreen);
        }

        foreach ([
            'Capacity & Demand',
            'HOUSE STRAIN',
            'Hero KPIs',
            'Approvals (${vm.approvals.size})',
            'Approve',
            'Reject',
            'Open in Zephyrus',
        ] as $copy) {
            $this->assertStringContainsString($copy, $capacityDemandScreen);
        }

        $this->assertStringContainsString('onOpenApproval(approval, vm.brief?.webLink)', $capacityDemandScreen);
        $this->assertStringContainsString('vm.decide(bearer, approval, "approved", onBack)', $capacityDemandScreen);
        $this->assertStringContainsString('vm.decide(bearer, approval, "rejected", onBack)', $capacityDemandScreen);
        $this->assertStringContainsString('Text("Human approval is required before this action can proceed."', $capacityDemandScreen);
        $this->assertStringContainsString('title = "Can\'t load approvals"', $capacityDemandScreen);
        $this->assertStringContainsString('title = "Inbox clear"', $capacityDemandScreen);
        $this->assertStringNotContainsString('patient_ref', $capacityDemandScreen);

        $this->assertStringContainsString('GET /api/mobile/v1/command/house', $commandController);
        $this->assertStringContainsString("links: ['web' => url('/dashboard')]", $commandController);
        $this->assertStringContainsString('GET  /api/mobile/v1/ops/inbox', $opsController);
        $this->assertStringContainsString("links: ['web' => url('/ops/agent-inbox')]", $opsController);
        $this->assertStringContainsString("'recommendation.approved' : 'recommendation.rejected'", $opsController);
    }

    public function test_android_executive_package_matches_ios_wave_two_contract(): void
    {
        $models = file_get_contents(base_path(self::ANDROID_MODELS));
        $apiClient = file_get_contents(base_path(self::ANDROID_API_CLIENT));
        $mainScreen = file_get_contents(base_path(self::ANDROID_MAIN_SCREEN));
        $executiveScreen = file_get_contents(base_path(self::ANDROID_EXECUTIVE_SCREEN));
        $executiveViewModel = file_get_contents(base_path(self::ANDROID_EXECUTIVE_VIEW_MODEL));
        $commandController = file_get_contents(base_path(self::MOBILE_COMMAND_CONTROLLER));

        foreach (['HouseBrief', 'ExecStrain', 'StrainDriver', 'HeroKpi'] as $dto) {
            $this->assertStringContainsString("data class {$dto}", $models);
        }

        foreach (['commandHouse', 'parseHouseBrief', 'parseExecStrain', 'parseStrainDriver', 'parseHeroKpi'] as $method) {
            $this->assertStringContainsString("fun {$method}", $apiClient);
        }

        $this->assertStringContainsString('class ExecutiveViewModel(app: Application) : AndroidViewModel(app)', $executiveViewModel);
        $this->assertStringContainsString('brief = api.commandHouse(bearer)', $executiveViewModel);

        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.executive.HouseBriefScreen', $mainScreen);
        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.executive.StrainDetailScreen', $mainScreen);
        $this->assertStringContainsString('data class Strain(val brief: HouseBrief) : AltitudeDetail', $mainScreen);
        $this->assertStringContainsString('HomeKind.HouseBrief -> HouseBriefScreen(', $mainScreen);
        $this->assertStringContainsString('StrainDetailScreen(', $mainScreen);

        foreach (['fun HouseBriefScreen(', 'fun StrainDetailScreen(', 'private fun ExecutiveStrainCard(', 'private fun ExecutiveOneThing(', 'private fun ExecutiveCalmState(', 'private fun HeroKpiTile('] as $symbol) {
            $this->assertStringContainsString($symbol, $executiveScreen);
        }

        foreach (['House Brief', 'HOUSE STRAIN', 'THE ONE THING', 'No material breach right now', 'Executive brief', 'HOUSE KPIS', 'Open in Zephyrus'] as $copy) {
            $this->assertStringContainsString($copy, $executiveScreen);
        }

        // Composable calls can't be function references inside ?.let — pinned as explicit branches.
        $this->assertStringContainsString('val one = materialDriver(brief.strain)', $executiveScreen);
        $this->assertStringContainsString('if (one != null) ExecutiveOneThing(one) else ExecutiveCalmState()', $executiveScreen);
        $this->assertStringContainsString('/command/brief is available on mobile', $executiveScreen);
        $this->assertStringContainsString('GET /api/mobile/v1/command/house', $commandController);
        $this->assertStringContainsString("links: ['web' => url('/dashboard')]", $commandController);
        $this->assertStringNotContainsString('patient_ref', $executiveScreen);
    }

    public function test_android_staffing_package_matches_ios_wave_three_contract(): void
    {
        $models = file_get_contents(base_path(self::ANDROID_MODELS));
        $apiClient = file_get_contents(base_path(self::ANDROID_API_CLIENT));
        $mainScreen = file_get_contents(base_path(self::ANDROID_MAIN_SCREEN));
        $staffingScreen = file_get_contents(base_path(self::ANDROID_STAFFING_SCREEN));
        $staffingViewModel = file_get_contents(base_path(self::ANDROID_STAFFING_VIEW_MODEL));
        $staffingController = file_get_contents(base_path(self::MOBILE_STAFFING_CONTROLLER));

        foreach (['StaffingOverview', 'StaffingMetrics', 'UnitAtRisk', 'StaffingReq'] as $dto) {
            $this->assertStringContainsString("data class {$dto}", $models);
        }

        foreach (['staffingOverview', 'fillStaffingRequest', 'parseStaffingOverview', 'parseStaffingMetrics', 'parseUnitAtRisk', 'parseStaffingReq'] as $method) {
            $this->assertStringContainsString("fun {$method}", $apiClient);
        }

        $this->assertStringContainsString('class StaffingViewModel(app: Application) : AndroidViewModel(app)', $staffingViewModel);
        $this->assertStringContainsString('overview = api.staffingOverview(bearer)', $staffingViewModel);
        $this->assertStringContainsString('api.fillStaffingRequest(bearer, request.staffingRequestId, "Float Pool")', $staffingViewModel);
        $this->assertStringContainsString('workingRequestIds = workingRequestIds + request.staffingRequestId', $staffingViewModel);

        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.staffing.StaffingScreen', $mainScreen);
        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.staffing.StaffingRequestDetailScreen', $mainScreen);
        $this->assertStringContainsString('data class StaffingRequest(val request: StaffingReq, val webLink: String?) : AltitudeDetail', $mainScreen);
        $this->assertStringContainsString('HomeKind.Staffing -> StaffingScreen(', $mainScreen);
        $this->assertStringContainsString('StaffingRequestDetailScreen(', $mainScreen);

        foreach (['fun StaffingScreen(', 'fun StaffingRequestDetailScreen(', 'private fun StaffingMetricsRow(', 'private fun UnitAtRiskRow(', 'private fun StaffingRequestRow('] as $symbol) {
            $this->assertStringContainsString($symbol, $staffingScreen);
        }

        foreach (['Staffing', 'BELOW MINIMUM-SAFE', 'OPEN REQUESTS', 'below safe', 'Fill from float pool', 'Open in Zephyrus'] as $copy) {
            $this->assertStringContainsString($copy, $staffingScreen);
        }

        $this->assertStringContainsString('onOpenRequest(request, overview.webLink)', $staffingScreen);
        $this->assertStringContainsString('vm.fillFromFloatPool(bearer, request)', $staffingScreen);
        $this->assertStringContainsString('GET /api/mobile/v1/staffing/overview', $staffingController);
        $this->assertStringContainsString("links: ['web' => url('/staffing')]", $staffingController);
        $this->assertStringContainsString("\$this->ledger->record('staffing.request_filled'", $staffingController);
        $this->assertStringNotContainsString('patient_ref', $staffingScreen);
    }

    public function test_android_improvement_package_matches_ios_wave_four_contract(): void
    {
        $models = file_get_contents(base_path(self::ANDROID_MODELS));
        $apiClient = file_get_contents(base_path(self::ANDROID_API_CLIENT));
        $mainScreen = file_get_contents(base_path(self::ANDROID_MAIN_SCREEN));
        $improvementScreen = file_get_contents(base_path(self::ANDROID_IMPROVEMENT_SCREEN));
        $improvementViewModel = file_get_contents(base_path(self::ANDROID_IMPROVEMENT_VIEW_MODEL));
        $improvementController = file_get_contents(base_path(self::MOBILE_IMPROVEMENT_CONTROLLER));

        foreach (['PdsaCycle', 'Opportunity'] as $dto) {
            $this->assertStringContainsString("data class {$dto}", $models);
        }

        foreach (['improvementPdsa', 'improvementOpportunities', 'parsePdsaCycle', 'parseOpportunity'] as $method) {
            $this->assertStringContainsString("fun {$method}", $apiClient);
        }

        $this->assertStringContainsString('class ImprovementViewModel(app: Application) : AndroidViewModel(app)', $improvementViewModel);
        $this->assertStringContainsString('cycles = api.improvementPdsa(bearer)', $improvementViewModel);
        $this->assertStringContainsString('opportunities = api.improvementOpportunities(bearer)', $improvementViewModel);
        $this->assertStringContainsString('fun activeCycles(): List<PdsaCycle> = cycles.filter { it.status == "active" }', $improvementViewModel);

        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.improvement.ImprovementScreen', $mainScreen);
        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.improvement.PdsaDetailScreen', $mainScreen);
        $this->assertStringContainsString('import net.acumenus.hummingbird.ui.improvement.OpportunityDetailScreen', $mainScreen);
        $this->assertStringContainsString('data class Pdsa(val cycle: PdsaCycle) : AltitudeDetail', $mainScreen);
        $this->assertStringContainsString('data class ImprovementOpportunity(val opportunity: Opportunity) : AltitudeDetail', $mainScreen);
        $this->assertStringContainsString('HomeKind.Improvement -> ImprovementScreen(', $mainScreen);
        $this->assertStringContainsString('PdsaDetailScreen(', $mainScreen);
        $this->assertStringContainsString('OpportunityDetailScreen(', $mainScreen);

        foreach (['fun ImprovementScreen(', 'fun PdsaDetailScreen(', 'fun OpportunityDetailScreen(', 'private fun PdsaCycleRow(', 'private fun OpportunityRow('] as $symbol) {
            $this->assertStringContainsString($symbol, $improvementScreen);
        }

        foreach (['Improvement', 'ACTIVE PDSA CYCLES', 'OPPORTUNITIES (by impact)', 'Stage advance is web-only until the write API exists', 'Read-only on mobile until the PDSA stage advance API exists.', 'Open in Zephyrus'] as $copy) {
            $this->assertStringContainsString($copy, $improvementScreen);
        }

        $this->assertStringContainsString('vm.opportunities.sortedByDescending { it.impact ?: 0 }', $improvementScreen);
        $this->assertStringContainsString('GET /api/mobile/v1/improvement/pdsa + /improvement/opportunities', $improvementController);
        $this->assertStringContainsString("links: ['web' => url('/improvement/pdsa')]", $improvementController);
        $this->assertStringContainsString("links: ['web' => url('/improvement/opportunities')]", $improvementController);
        $this->assertStringNotContainsString('/advance', $improvementController);
        $this->assertStringNotContainsString('patient_ref', $improvementScreen);
    }

    public function test_android_for_you_parity_uses_role_filters_actions_and_navigation(): void
    {
        $models = file_get_contents(base_path(self::ANDROID_MODELS));
        $apiClient = file_get_contents(base_path(self::ANDROID_API_CLIENT));
        $viewModel = file_get_contents(base_path('hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/data/ForYouViewModel.kt'));
        $screen = file_get_contents(base_path(self::ANDROID_FOR_YOU_SCREEN));
        $mainScreen = file_get_contents(base_path(self::ANDROID_MAIN_SCREEN));
        $forYouService = file_get_contents(base_path(self::MOBILE_FOR_YOU_SERVICE));
        $opsController = file_get_contents(base_path(self::MOBILE_OPS_CONTROLLER));
        $staffingController = file_get_contents(base_path(self::MOBILE_STAFFING_CONTROLLER));

        $this->assertStringContainsString('enum class QueueFilter(val wireValue: String)', $models);
        $this->assertStringContainsString('queueFilter: QueueFilter', $models);
        foreach (['All("all")', 'Placements("placements")', 'Escalations("escalations")', 'MyUnit("myUnit")', 'CriticalCare("criticalCare")', 'Turns("turns")', 'None("none")'] as $filter) {
            $this->assertStringContainsString($filter, $models);
        }

        $this->assertStringContainsString('fun forYou(bearer: String, persona: String? = null)', $apiClient);
        $this->assertStringContainsString('withPersona("/api/mobile/v1/for-you", persona)', $apiClient);
        $this->assertStringContainsString('fun resolveBarrier(bearer: String, id: Int)', $apiClient);
        $this->assertStringContainsString('fun opsDecision(bearer: String, approvalUuid: String, decision: String)', $apiClient);
        $this->assertStringContainsString('/api/mobile/v1/ops/approvals/${urlPart(approvalUuid)}/decision', $apiClient);
        $this->assertStringContainsString('fun fillStaffingRequest(bearer: String, id: Int, assignedSource: String)', $apiClient);
        $this->assertStringContainsString('/api/mobile/v1/staffing/requests/$id/fill', $apiClient);

        $this->assertStringContainsString('fun load(bearer: String, role: MobileRole)', $viewModel);
        $this->assertStringContainsString('items = api.forYou(bearer, role.id)', $viewModel);
        $this->assertStringContainsString('fun filteredItems(role: MobileRole, unitName: String?)', $viewModel);
        $this->assertStringContainsString('QueueFilter.Placements -> item.type == "bed_request" || item.type == "capacity"', $viewModel);
        $this->assertStringContainsString('QueueFilter.Escalations -> item.type == "barrier" || item.type == "capacity"', $viewModel);
        $this->assertStringContainsString('QueueFilter.MyUnit -> unitName == null || item.unit == unitName || item.type == "bed_request"', $viewModel);
        $this->assertStringContainsString('fun resolveBarrier(bearer: String, item: ForYouItem, role: MobileRole)', $viewModel);
        $this->assertStringContainsString('fun claimTransport(bearer: String, item: ForYouItem, role: MobileRole)', $viewModel);
        $this->assertStringContainsString('fun claimEvsTurn(bearer: String, item: ForYouItem, role: MobileRole)', $viewModel);
        $this->assertStringContainsString('fun approveOpsAction(bearer: String, item: ForYouItem, role: MobileRole)', $viewModel);
        $this->assertStringContainsString('fun rejectOpsAction(bearer: String, item: ForYouItem, role: MobileRole)', $viewModel);
        $this->assertStringContainsString('fun fillStaffingRequest(bearer: String, item: ForYouItem, role: MobileRole)', $viewModel);
        $this->assertStringContainsString('api.opsDecision(bearer, approvalUuid, decision)', $viewModel);
        $this->assertStringContainsString('api.fillStaffingRequest(bearer, id, role.title)', $viewModel);

        $this->assertStringContainsString('selectedRole: MobileRole = MobileRoleCatalog.default', $screen);
        $this->assertStringContainsString('selectedUnitName: String? = null', $screen);
        $this->assertStringContainsString('val visibleItems = vm.filteredItems(selectedRole, selectedUnitName)', $screen);
        $this->assertStringContainsString('queueTitle(selectedRole)', $screen);
        $this->assertStringContainsString('emptyQueue(selectedRole)', $screen);
        $this->assertStringContainsString('onOpenUnit: ((CensusUnit, String?) -> Unit)? = null', $screen);
        $this->assertStringContainsString('actions = actionsFor(item, vm, bearer, selectedRole)', $screen);
        $this->assertStringContainsString('private fun actionsFor(item: ForYouItem, vm: ForYouViewModel, bearer: String, role: MobileRole): List<ForYouAction>', $screen);
        $this->assertStringContainsString('vm.resolveBarrier(bearer, item, role)', $screen);
        $this->assertStringContainsString('vm.claimTransport(bearer, item, role)', $screen);
        $this->assertStringContainsString('vm.claimEvsTurn(bearer, item, role)', $screen);
        $this->assertStringContainsString('ForYouAction("Approve", CapacityStatus.SUCCESS) { vm.approveOpsAction(bearer, item, role) }', $screen);
        $this->assertStringContainsString('ForYouAction("Reject", CapacityStatus.WARNING) { vm.rejectOpsAction(bearer, item, role) }', $screen);
        $this->assertStringContainsString('ForYouAction("Fill") { vm.fillStaffingRequest(bearer, item, role) }', $screen);
        $this->assertStringContainsString('actions.forEach { action ->', $screen);
        $this->assertStringContainsString('title = "Can\'t load your queue"', $screen);
        $this->assertStringContainsString('title = "Loading your queue"', $screen);

        $this->assertStringContainsString("'id' => 'ops-approval-'.\$approval->approval_uuid", $forYouService);
        $this->assertStringContainsString("'type' => 'ops_approval'", $forYouService);
        $this->assertStringContainsString("'id' => 'staffing-'.\$request->staffing_request_id", $forYouService);
        $this->assertStringContainsString("'type' => 'staffing_request'", $forYouService);
        $this->assertStringContainsString("'recommendation.approved' : 'recommendation.rejected'", $opsController);
        $this->assertStringContainsString("\$this->ledger->record('staffing.request_filled'", $staffingController);

        $this->assertStringContainsString('data class Unit(val unit: CensusUnit, val webLink: String?) : AltitudeDetail', $mainScreen);
        $this->assertStringContainsString('selectedRole = selectedRole', $mainScreen);
        $this->assertStringContainsString('selectedUnitName = vm.confirmedProfile.unitName', $mainScreen);
        $this->assertStringContainsString('onOpenUnit = { unit, webLink -> detail = AltitudeDetail.Unit(unit, webLink) }', $mainScreen);
        $this->assertStringContainsString('UnitDetailScreen(', $mainScreen);
    }

    public function test_android_bottom_navigation_labels_derive_from_home_kind(): void
    {
        $mainScreen = file_get_contents(base_path(self::ANDROID_MAIN_SCREEN));

        $this->assertStringContainsString('private enum class HummingbirdTab { Home, ForYou, Activity }', $mainScreen);
        $this->assertStringContainsString('Text(homeKind.tabLabel)', $mainScreen);
        $this->assertStringContainsString('Text("For You")', $mainScreen);
        $this->assertStringContainsString('Icon(iconForRole(selectedRole)', $mainScreen);
        $this->assertStringContainsString('ForYouScreen(', $mainScreen);
        $this->assertStringContainsString('ActivityFeedScreen(', $mainScreen);
        $this->assertStringNotContainsString('AltitudeTopTab', $mainScreen);
        $this->assertStringNotContainsString('HummingbirdTab.Workspace', $mainScreen);
        $this->assertStringNotContainsString('AltitudeWorkspaceScreen(', $mainScreen);
        $this->assertStringNotContainsString('Text(homeKind.workspaceLabel)', $mainScreen);
        $this->assertStringNotContainsString('Text("A0")', $mainScreen);
        $this->assertStringNotContainsString('Text("A1")', $mainScreen);
    }

    public function test_android_default_shell_hides_debug_role_and_domain_selectors(): void
    {
        $altitudeScreens = file_get_contents(base_path(self::ANDROID_ALTITUDE_SCREENS));

        $this->assertStringContainsString('showRoleSelector: Boolean = false', $altitudeScreens);
        $this->assertStringContainsString('if (showRoleSelector)', $altitudeScreens);
        $this->assertStringNotContainsString('title = { Text("Altitude Home"', $altitudeScreens);
        $this->assertStringNotContainsString('SectionTitle("A0 glance tiles")', $altitudeScreens);
        $this->assertStringNotContainsString('SectionTitle("For You head")', $altitudeScreens);
    }

    public function test_android_shell_exposes_ios_equivalent_test_affordances(): void
    {
        $activity = file_get_contents(base_path(self::ANDROID_MAIN_ACTIVITY));
        $mainScreen = file_get_contents(base_path(self::ANDROID_MAIN_SCREEN));
        $forYou = file_get_contents(base_path(self::ANDROID_FOR_YOU_SCREEN));
        $altitudeScreens = file_get_contents(base_path(self::ANDROID_ALTITUDE_SCREENS));

        foreach (['HB_AUTOLOGIN', 'HB_ROLE', 'HB_TAB', 'HB_OPEN_UNIT', 'HB_OPEN_TARGET', 'HB_FORCE_ERROR', 'HB_DEBUG_EXPLORER'] as $extra) {
            $this->assertStringContainsString($extra, $activity, "MainActivity does not read {$extra}.");
        }

        $this->assertStringContainsString('data class HummingbirdLaunchConfig', $mainScreen);
        $this->assertStringContainsString('tabFromLaunch(launchConfig.tab)', $mainScreen);
        $this->assertStringContainsString('MobileRoleCatalog.roles.firstOrNull', $mainScreen);
        $this->assertStringContainsString('launchDetail(launchConfig)', $mainScreen);
        $this->assertStringContainsString('openUnitId?.let { AltitudeDetail.Drill("unit-$it") }', $mainScreen);
        $this->assertStringContainsString('forceError = launchConfig.forceError', $mainScreen);
        $this->assertStringContainsString('forceError: Boolean = false', $forYou);
        $this->assertStringContainsString('ForcedErrorPanel', $forYou);
        $this->assertStringContainsString('forceError: Boolean = false', $altitudeScreens);
        $this->assertStringContainsString("Can't reach the server. Check your connection and try again.", $altitudeScreens);
    }

    public function test_android_debug_altitude_explorer_is_gated_out_of_the_primary_shell(): void
    {
        $activity = file_get_contents(base_path(self::ANDROID_MAIN_ACTIVITY));
        $mainScreen = file_get_contents(base_path(self::ANDROID_MAIN_SCREEN));
        $altitudeScreens = file_get_contents(base_path(self::ANDROID_ALTITUDE_SCREENS));

        $this->assertStringContainsString('debugExplorer = intent.getStringExtra("HB_DEBUG_EXPLORER") == "1"', $activity);
        $this->assertStringContainsString('debugExplorer: Boolean = false', $mainScreen);
        $this->assertStringContainsString('data object DebugExplorer : AltitudeDetail', $mainScreen);
        $this->assertStringContainsString(
            'detail = if (launchConfig.debugExplorer) AltitudeDetail.DebugExplorer else launchDetail(launchConfig)',
            $mainScreen,
        );
        $this->assertStringContainsString('onOpenDebugExplorer = { detail = AltitudeDetail.DebugExplorer }', $mainScreen);
        $this->assertStringContainsString('DebugAltitudeExplorerScreen(', $mainScreen);
        $this->assertStringContainsString('DebugAltitudeExplorerScreen(', $altitudeScreens);
        $this->assertStringContainsString('title = { Text("Debug Altitude Explorer"', $altitudeScreens);
        $this->assertStringContainsString('RoleSelector(vm.selectedRole, vm::selectRole)', $altitudeScreens);
        $this->assertStringContainsString('DomainSelector(vm.selectedDomain, vm::selectDomain)', $altitudeScreens);
        $this->assertStringNotContainsString('AltitudeWorkspaceScreen(', $mainScreen);
        $this->assertStringNotContainsString('AltitudeWorkspaceScreen(', $altitudeScreens);
    }

    public function test_android_profile_entry_contains_role_scope_notifications_signout_and_demo_switcher(): void
    {
        $mainScreen = file_get_contents(base_path(self::ANDROID_MAIN_SCREEN));
        $forYou = file_get_contents(base_path(self::ANDROID_FOR_YOU_SCREEN));
        $altitudeScreens = file_get_contents(base_path(self::ANDROID_ALTITUDE_SCREENS));

        $this->assertStringContainsString('data object Profile : AltitudeDetail', $mainScreen);
        $this->assertStringContainsString('ProfileSettingsScreen(', $mainScreen);
        $this->assertStringContainsString('onOpenProfile = { detail = AltitudeDetail.Profile }', $mainScreen);
        $this->assertStringContainsString('Role confirmation', $mainScreen);
        $this->assertStringContainsString('Unit assignment', $mainScreen);
        $this->assertStringContainsString('Notification preferences', $mainScreen);
        $this->assertStringContainsString('Sign out', $mainScreen);
        $this->assertStringContainsString('Debug role switcher', $mainScreen);
        $this->assertStringContainsString('Open debug altitude explorer', $mainScreen);
        $this->assertStringContainsString('onOpenDebugExplorer: () -> Unit', $mainScreen);
        $this->assertGreaterThanOrEqual(6, substr_count($mainScreen, 'heightIn(min = 48.dp)'));
        $this->assertStringContainsString(
            'auth.me?.isAdmin == true || auth.me?.username?.contains("demo", ignoreCase = true) == true',
            $mainScreen,
        );
        $this->assertStringContainsString('MobileRoleCatalog.roles, key = { it.id }', $mainScreen);
        $this->assertStringContainsString('vm.selectRole(role)', $mainScreen);

        foreach ([$forYou, $altitudeScreens] as $source) {
            $this->assertStringContainsString('onOpenProfile: () -> Unit = {}', $source);
            $this->assertStringContainsString('contentDescription = "Profile"', $source);
        }
    }

    public function test_android_profile_confirmation_matches_ios_onboarding_contract(): void
    {
        $activity = file_get_contents(base_path(self::ANDROID_MAIN_ACTIVITY));
        $models = file_get_contents(base_path(self::ANDROID_MODELS));
        $apiClient = file_get_contents(base_path(self::ANDROID_API_CLIENT));
        $viewModel = file_get_contents(base_path(self::ANDROID_ALTITUDE_VIEW_MODEL));
        $mainScreen = file_get_contents(base_path(self::ANDROID_MAIN_SCREEN));

        $this->assertStringContainsString('val user = intent.getStringExtra("HB_USER") ?: "demo"', $activity);
        $this->assertStringContainsString('val default: MobileRole = roles.first { it.id == "house_supervisor" }', $models);
        $this->assertStringContainsString('roles: List<String>', $models);
        $this->assertStringContainsString('data class ConfirmedProfile', $models);
        $this->assertStringContainsString('fun matchingServerRoles(serverRoles: List<String>)', $models);
        $this->assertStringContainsString('roles = data.optJSONArray("roles").strings()', $apiClient);
        $this->assertStringContainsString('fun loadProfileForUser(me: MeData?)', $viewModel);
        $this->assertStringContainsString('MobileRoleCatalog.matchingServerRoles(me.roles)', $viewModel);
        $this->assertStringContainsString('putString(profileKey("role", userId), role.id)', $viewModel);
        $this->assertStringContainsString('putInt(profileKey("unit", userId), unit.unitId)', $viewModel);
        $this->assertStringContainsString('loadProfileUnits(bearer)', $mainScreen);
        $this->assertStringContainsString('data object ProfileConfirmation : AltitudeDetail', $mainScreen);
        $this->assertStringContainsString('ProfileConfirmationScreen(', $mainScreen);
        $this->assertStringContainsString('Confirm your role for this shift', $mainScreen);
        $this->assertStringContainsString('Where are you working today?', $mainScreen);
        $this->assertStringContainsString('Assigned in Zephyrus', $mainScreen);
        $this->assertStringContainsString('House-wide', $mainScreen);
        $this->assertStringContainsString('Start shift', $mainScreen);
        $this->assertStringContainsString('heightIn(min = 48.dp)', $mainScreen);
    }

    public function test_android_visual_polish_tokens_and_action_copy_are_pinned(): void
    {
        $theme = file_get_contents(base_path(self::ANDROID_THEME));
        $panel = file_get_contents(base_path(self::ANDROID_PANEL));
        $retryableMessage = file_get_contents(base_path(self::ANDROID_RETRYABLE_MESSAGE));
        $iosTheme = file_get_contents(base_path(self::IOS_THEME));
        $apiClient = file_get_contents(base_path(self::ANDROID_API_CLIENT));
        $altitudeViewModel = file_get_contents(base_path(self::ANDROID_ALTITUDE_VIEW_MODEL));
        $mainScreen = file_get_contents(base_path(self::ANDROID_MAIN_SCREEN));
        $forYou = file_get_contents(base_path(self::ANDROID_FOR_YOU_SCREEN));
        $altitudeScreens = file_get_contents(base_path(self::ANDROID_ALTITUDE_SCREENS));
        $patientContextService = file_get_contents(base_path(self::MOBILE_PATIENT_CONTEXT_SERVICE));

        foreach (['s1 = 4.dp', 's2 = 8.dp', 's3 = 12.dp', 's4 = 16.dp', 's5 = 20.dp', 's6 = 24.dp'] as $token) {
            $this->assertStringContainsString($token, $theme, "Missing Android spacing token {$token}.");
        }

        $this->assertStringContainsString('static let radius: CGFloat = 14', $iosTheme);
        $this->assertStringContainsString('fun Modifier.panel(corner: Int = 14)', $panel);
        $this->assertStringContainsString('.shadow(elevation = 8.dp', $panel);
        $this->assertStringContainsString('.border(1.dp, Z.border', $panel);
        $this->assertStringContainsString('fun RetryableMessage(', $retryableMessage);
        $this->assertStringContainsString('title: String', $retryableMessage);
        $this->assertStringContainsString('message: String', $retryableMessage);
        $this->assertStringContainsString('tone: CapacityStatus = CapacityStatus.INFO', $retryableMessage);
        $this->assertStringContainsString('retryLabel: String? = null', $retryableMessage);
        $this->assertStringContainsString('heightIn(min = 48.dp)', $retryableMessage);
        $this->assertStringContainsString('RetryableMessage(', $forYou);
        $this->assertStringContainsString('RetryableMessage(', $altitudeScreens);
        $this->assertStringContainsString('title = "Can\'t load your queue"', $forYou);
        $this->assertStringContainsString('title = "Can\'t load this view"', $altitudeScreens);
        $this->assertStringContainsString('title = "Nothing here right now"', $altitudeScreens);
        $this->assertStringContainsString('title = "Loading latest data"', $altitudeScreens);
        $this->assertStringContainsString('private fun ActivityFeedHeader(role: MobileRole)', $altitudeScreens);
        $this->assertStringContainsString('Text("Operational activity"', $altitudeScreens);
        $this->assertStringContainsString('activityGroups(vm.activity.events).forEach { group ->', $altitudeScreens);
        $this->assertStringContainsString('private fun ActivityDayHeader(label: String, count: Int)', $altitudeScreens);
        $this->assertStringContainsString('onAck = activityAck(event) { vm.acknowledgeActivity(bearer, event.eventUuid) }', $altitudeScreens);
        $this->assertStringContainsString('event.eventType == "alert.acknowledged"', $altitudeScreens);
        $this->assertStringContainsString('private fun ActionRow(label: String, kind: String)', $altitudeScreens);
        $this->assertStringContainsString('Text(humanizeLocal(kind), color = Z.inkMuted', $altitudeScreens);
        $this->assertStringNotContainsString('ActionRow(action.label, action.kind, action.endpoint)', $altitudeScreens);
        $this->assertStringNotContainsString('Text(endpoint ?: humanizeLocal(kind)', $altitudeScreens);
        // Altitude invisibility: user-facing copy says "Details", never the model's "Drill".
        $this->assertStringContainsString('DetailTopBar("Details"', $altitudeScreens);
        $this->assertStringContainsString('DetailTopBar("Patient context"', $altitudeScreens);
        $this->assertStringContainsString('Text("Open patient context")', $altitudeScreens);
        $this->assertStringContainsString('onOpenDrill = { detail = AltitudeDetail.Drill(it) }', $mainScreen);
        $this->assertStringContainsString('onOpenPatient = { detail = AltitudeDetail.Patient(it) }', $mainScreen);
        $this->assertStringContainsString('is AltitudeDetail.Drill -> DrillDetailScreen(', $mainScreen);
        $this->assertStringContainsString('is AltitudeDetail.Patient -> PatientContextScreen(', $mainScreen);
        $this->assertStringContainsString('patientContext = api.patientOperationalContext(bearer, contextRef, selectedRole.id)', $altitudeViewModel);
        $this->assertStringContainsString('getData(withPersona("/api/mobile/v1/patients/${urlPart(contextRef)}/operational-context", persona), bearer)', $apiClient);
        $this->assertStringContainsString("throw new AuthorizationException('This patient operational context is not available to the current mobile persona.')", $patientContextService);
        $this->assertStringContainsString("if (! str_starts_with(\$requestedRef, 'ptok_'))", $patientContextService);
        $this->assertStringContainsString('Text("Explorer domain"', $altitudeScreens);
        $this->assertStringContainsString('Text("Operational drill"', $altitudeScreens);
        $this->assertStringContainsString('Text("Authorized operational context"', $altitudeScreens);
        $this->assertStringContainsString('val uriHandler = LocalUriHandler.current', $altitudeScreens);
        $this->assertStringContainsString('drill.web?.let { web ->', $altitudeScreens);
        $this->assertStringContainsString('context.web?.let { web ->', $altitudeScreens);
        $this->assertStringContainsString('WebLinkButton(web.label ?: "Open in Zephyrus") { uriHandler.openUri(href) }', $altitudeScreens);
        $this->assertStringContainsString('private fun WebLinkButton(label: String, onOpen: () -> Unit)', $altitudeScreens);
        $this->assertStringContainsString('Text(label.ifBlank { "Open in Zephyrus" })', $altitudeScreens);
        $this->assertStringNotContainsString('SectionTitle("A0 glance tiles")', $altitudeScreens);
        $this->assertStringNotContainsString('SectionTitle("For You head")', $altitudeScreens);
        $this->assertStringNotContainsString('Cross-persona relay', $altitudeScreens);
        $this->assertStringNotContainsString('No role-filtered activity yet.', $altitudeScreens);
        $this->assertStringNotContainsString('title = { Text("Altitude Home"', $altitudeScreens);
        $this->assertStringNotContainsString('Text("Workspace domain"', $altitudeScreens);
        $this->assertStringNotContainsString('Text("Drill ${drill.itemUuid}"', $altitudeScreens);
        $this->assertStringNotContainsString('DetailTopBar("A2 drill"', $altitudeScreens);
        $this->assertStringNotContainsString('DetailTopBar("A2P patient lens"', $altitudeScreens);
        $this->assertStringNotContainsString('Open A2P patient lens', $altitudeScreens);
        $this->assertStringNotContainsString('Patient lens ${formatRef(ref)}', $altitudeScreens);
        $this->assertStringNotContainsString('Patient context ${formatRef(ref)}', $altitudeScreens);
    }

    public function test_ios_altitude_two_drill_threading_covers_role_package_items(): void
    {
        $forYou = file_get_contents(base_path(self::IOS_FOR_YOU_VIEW));
        $capacityDemand = file_get_contents(base_path(self::IOS_CAPACITY_DEMAND_VIEW));
        $staffing = file_get_contents(base_path(self::IOS_STAFFING_VIEW));
        $improvement = file_get_contents(base_path(self::IOS_IMPROVEMENT_VIEW));
        $altitudeComponents = file_get_contents(base_path(self::IOS_ALTITUDE_COMPONENTS));
        $altitudeService = file_get_contents(base_path(self::MOBILE_ALTITUDE_SERVICE));

        foreach (['ops-approval-', 'staffing-', 'cap-', 'improvement-'] as $prefix) {
            $this->assertStringContainsString("item.id.hasPrefix(\"{$prefix}\")", $forYou);
            $this->assertStringContainsString("str_starts_with(\$itemUuid, '{$prefix}')", $altitudeService);
        }

        $this->assertStringContainsString('case "ops_approval": return "checkmark.seal.fill"', $forYou);
        $this->assertStringContainsString('case "staffing_request": return "person.2.badge.gearshape.fill"', $forYou);
        $this->assertStringContainsString('return "patient context available"', $forYou);
        $this->assertStringNotContainsString('return "context \(ref)"', $forYou);

        $this->assertStringContainsString('DrillDetailView(itemUuid: "ops-approval-\(a.approvalUuid)")', $capacityDemand);
        $this->assertStringContainsString('Label("Why this approval?", systemImage: "info.circle")', $capacityDemand);
        $this->assertStringContainsString('api.opsDecide(uuid: a.approvalUuid, decision: decision, bearer: bearer)', $capacityDemand);
        $this->assertStringContainsString('await vm.reject(a, bearer: auth.accessToken ?? "")', $capacityDemand);

        $this->assertStringContainsString('DrillDetailView(itemUuid: "staffing-\(r.id)")', $staffing);
        $this->assertStringContainsString('Label("Why this gap?", systemImage: "info.circle")', $staffing);
        $this->assertStringContainsString('DrillDetailView(itemUuid: "improvement-\(o.id)")', $improvement);
        $this->assertStringContainsString('Label("Why this opportunity?", systemImage: "info.circle")', $improvement);

        $this->assertStringContainsString('Label("Open operational patient context", systemImage: "person.text.rectangle")', $altitudeComponents);
        $this->assertStringContainsString('Text("Authorized context")', $altitudeComponents);
        $this->assertStringNotContainsString('Text(contextRef)', $altitudeComponents);

        $this->assertStringContainsString('$this->ledger->forEntity(\'approval\', (string) $approval->approval_uuid)', $altitudeService);
        $this->assertStringContainsString('$this->ledger->forEntity(\'staffing_request\', (string) $request->staffing_request_id)', $altitudeService);
        $this->assertStringContainsString('$this->ledger->forEntity(\'unit\', (string) $unit->unit_id)', $altitudeService);
        $this->assertStringContainsString('$this->ledger->forEntity(\'improvement_opportunity\', (string) $opportunity->opportunity_id)', $altitudeService);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function canonicalRoles(): array
    {
        $decoded = json_decode(file_get_contents(base_path(self::CANONICAL_CATALOG)), true, flags: JSON_THROW_ON_ERROR);

        return $decoded['roles'];
    }

    /**
     * @return array<int, string>
     */
    private function canonicalRoleIds(): array
    {
        return $this->sorted(array_column($this->canonicalRoles(), 'role_id'));
    }

    /**
     * @return array<int, string>
     */
    private function canonicalHomeKinds(): array
    {
        return array_column($this->canonicalDocument()['home_kinds'], 'id');
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function canonicalFeatureRoutes(): array
    {
        return $this->canonicalDocument()['feature_routes'];
    }

    /**
     * @return array<int, string>
     */
    private function swiftRoleIds(): array
    {
        return $this->sorted($this->regexMatches(self::IOS_ROLE, '/Role\\(id:\\s*"([^"]+)"/'));
    }

    /**
     * @return array<int, string>
     */
    private function swiftRoleExperienceIds(): array
    {
        return $this->sorted($this->regexMatches(self::IOS_ROLE_EXPERIENCE, '/case\\s+"([^"]+)":/'));
    }

    /**
     * @return array<int, string>
     */
    private function androidRoleIds(): array
    {
        return $this->sorted($this->regexMatches(self::ANDROID_MODELS, '/MobileRole\\("([^"]+)"/'));
    }

    /**
     * @return array<int, string>
     */
    private function swiftHomeKinds(): array
    {
        $block = $this->between(
            file_get_contents(base_path(self::IOS_ROLE_EXPERIENCE)),
            'enum HomeKind: Equatable {',
            'var tabLabel:',
        );

        preg_match_all('/case\\s+(\\w+)/', $block, $matches);

        return $matches[1] ?? [];
    }

    /**
     * @return array<string, string>
     */
    private function swiftRoleExperienceHomeKinds(): array
    {
        $source = file_get_contents(base_path(self::IOS_ROLE_EXPERIENCE));

        return collect($this->canonicalRoleIds())
            ->mapWithKeys(function (string $roleId) use ($source): array {
                $case = $this->swiftSwitchCaseBlock($source, $roleId);
                preg_match('/home:\\s+\\.(\\w+)/', $case, $matches);

                return [$roleId => $matches[1] ?? 'census'];
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function androidHomeKindWireValues(): array
    {
        preg_match_all(
            '/^\\s*(\\w+)\\("([^"]+)"/m',
            $this->between(file_get_contents(base_path(self::ANDROID_MODELS)), 'enum class HomeKind(', 'companion object'),
            $matches,
            PREG_SET_ORDER,
        );

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [$match[1] => $match[2]])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function androidRoleHomeKinds(): array
    {
        $homeKindWireValues = $this->androidHomeKindWireValues();
        preg_match_all(
            '/MobileRole\\("([^"]+)".*HomeKind\\.(\\w+)/',
            file_get_contents(base_path(self::ANDROID_MODELS)),
            $matches,
            PREG_SET_ORDER,
        );

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [$match[1] => $homeKindWireValues[$match[2]]])
            ->sortKeys()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function androidRoleIconNames(): array
    {
        preg_match_all(
            '/MobileRole\\("([^"]+)".*?HomeKind\\.\\w+,\\s*"([^"]+)"\\)/',
            file_get_contents(base_path(self::ANDROID_MODELS)),
            $matches,
            PREG_SET_ORDER,
        );

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [$match[1] => $match[2]])
            ->sortKeys()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function androidRoleQueueFilters(): array
    {
        $queueFilterWireValues = $this->androidQueueFilterWireValues();
        preg_match_all(
            '/MobileRole\\("([^"]+)".*?QueueFilter\\.(\\w+)/',
            file_get_contents(base_path(self::ANDROID_MODELS)),
            $matches,
            PREG_SET_ORDER,
        );

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [$match[1] => $queueFilterWireValues[$match[2]]])
            ->sortKeys()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function androidQueueFilterWireValues(): array
    {
        preg_match_all(
            '/^\\s*(\\w+)\\("([^"]+)"/m',
            $this->between(file_get_contents(base_path(self::ANDROID_MODELS)), 'enum class QueueFilter(', '}'),
            $matches,
            PREG_SET_ORDER,
        );

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [$match[1] => $match[2]])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function androidRoleSubtitles(): array
    {
        preg_match_all(
            '/MobileRole\\("([^"]+)",\\s*"[^"]+",\\s*"([^"]+)",\\s*(?:true|false),/',
            file_get_contents(base_path(self::ANDROID_MODELS)),
            $matches,
            PREG_SET_ORDER,
        );

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [$match[1] => $match[2]])
            ->sortKeys()
            ->all();
    }

    /**
     * @return array<string, bool>
     */
    private function androidRoleUnitBoundFlags(): array
    {
        preg_match_all(
            '/MobileRole\\("([^"]+)",\\s*"[^"]+",\\s*"[^"]+",\\s*(true|false),/',
            file_get_contents(base_path(self::ANDROID_MODELS)),
            $matches,
            PREG_SET_ORDER,
        );

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [$match[1] => $match[2] === 'true'])
            ->sortKeys()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function androidWorkspaceDomains(): array
    {
        $source = file_get_contents(base_path(self::ANDROID_ALTITUDE_SCREENS));
        preg_match('/workspaceDomains\\s*=\\s*listOf\\(([^)]+)\\)/', $source, $matches);
        preg_match_all('/"([^"]+)"/', $matches[1] ?? '', $domains);

        return $domains[1] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalDocument(): array
    {
        return json_decode(file_get_contents(base_path(self::CANONICAL_CATALOG)), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<int, string>
     */
    private function regexMatches(string $path, string $pattern): array
    {
        preg_match_all($pattern, file_get_contents(base_path($path)), $matches);

        return $matches[1] ?? [];
    }

    private function between(string $source, string $start, string $end): string
    {
        $startOffset = strpos($source, $start);
        $this->assertNotFalse($startOffset, "Unable to find {$start}.");
        $startOffset += strlen($start);
        $endOffset = strpos($source, $end, $startOffset);
        $this->assertNotFalse($endOffset, "Unable to find {$end}.");

        return substr($source, $startOffset, $endOffset - $startOffset);
    }

    private function swiftSwitchCaseBlock(string $source, string $roleId): string
    {
        $start = strpos($source, 'case "'.$roleId.'":');
        $this->assertNotFalse($start, "Unable to find iOS RoleExperience case for {$roleId}.");
        $nextCase = strpos($source, "\n        case ", $start + 1);
        $default = strpos($source, "\n        default:", $start + 1);
        $end = min(array_filter([$nextCase, $default], fn ($offset): bool => $offset !== false));

        return substr($source, $start, $end - $start);
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
