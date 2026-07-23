package net.acumenus.hummingbird.data

import net.acumenus.hummingbird.ui.theme.CapacityStatus

/** Result of POST /api/auth/token — either a token pair or a must-change-password challenge. */
data class TokenResult(
    val accessToken: String?,
    val refreshToken: String?,
    val expiresIn: Int?,
    val abilities: List<String>,
    val passwordChangeRequired: Boolean,
    val changeToken: String?,
)

data class MeData(
    val id: Int,
    val name: String,
    val username: String,
    val roles: List<String>,
    val workflowPreference: String?,
    val isAdmin: Boolean,
    val canViewPatientCommunications: Boolean = false,
    val canRespondPatientCommunications: Boolean = false,
)

data class ConfirmedProfile(
    val roleId: String? = null,
    val unitId: Int? = null,
    val unitName: String? = null,
) {
    val isConfirmed: Boolean get() = roleId != null
}

data class CensusUnit(
    val unitId: Int,
    val name: String,
    val type: String,
    val staffedBedCount: Int,
    val occupied: Int,
    val available: Int,
    val blocked: Int,
    val canAdmit: Int,
    val bedNeed: Int,
    val status: String,
) {
    val capacity: CapacityStatus get() = CapacityStatus.from(status)
}

data class CensusResult(
    val units: List<CensusUnit>,
    val asOf: String?,
    val stale: Boolean,
    val webLink: String?,
)

/** GET /api/mobile/v1/for-you — one prioritized, PHI-minimized action item. */
data class ForYouItem(
    val id: String,
    val type: String,
    val domain: String?,
    val tier: String,
    val title: String,
    val subtitle: String,
    val unit: String?,
    val at: String?,
    val patientContextRef: String?,
) {
    val capacity: CapacityStatus get() = CapacityStatus.from(tier)
}

data class TransportMetrics(
    val active: Int,
    val stat: Int,
    val atRisk: Int,
    val completedToday: Int,
)

data class TransportSla(
    val minutesUntilDue: Int?,
    val atRisk: Boolean,
    val label: String,
)

data class TransportJob(
    val id: Int,
    val uuid: String?,
    val type: String,
    val priority: String,
    val status: String,
    val visualStatus: String,
    val origin: String?,
    val destination: String?,
    val mode: String?,
    val neededAt: String?,
    val patientContextRef: String?,
    val claimedByMe: Boolean,
    val availableToClaim: Boolean,
    val resourceName: String?,
    val handoffRequired: Boolean,
    val allowedTransitions: List<String>,
    val canHandoff: Boolean,
    val lifecycleVersion: Int,
    val sla: TransportSla,
) {
    val capacity: CapacityStatus get() = CapacityStatus.from(visualStatus)
}

data class TransportQueue(
    val metrics: TransportMetrics,
    val jobs: List<TransportJob>,
    val webLink: String?,
    val stale: Boolean,
    val nextCursor: String?,
    val hasMore: Boolean,
)

data class EvsMetrics(
    val pending: Int,
    val overdue: Int,
    val isolation: Int,
    val completedToday: Int,
)

data class EvsSla(
    val minutesUntilDue: Int?,
    val atRisk: Boolean,
    val label: String,
)

data class EvsTurn(
    val id: Int,
    val uuid: String?,
    val requestType: String,
    val priority: String,
    val status: String,
    val visualStatus: String,
    val locationLabel: String?,
    val unitId: Int?,
    val turnType: String?,
    val isolationRequired: Boolean,
    val neededAt: String?,
    val patientContextRef: String?,
    val sla: EvsSla,
) {
    val capacity: CapacityStatus get() = CapacityStatus.from(visualStatus)
}

data class EvsQueue(
    val metrics: EvsMetrics,
    val turns: List<EvsTurn>,
    val webLink: String?,
    val stale: Boolean,
)

data class ORBoard(
    val rooms: List<ORRoom>,
    val metrics: ORMetrics,
    val webLink: String?,
    val stale: Boolean,
)

data class ORMetrics(
    val running: Int,
    val turnover: Int,
    val available: Int,
    val total: Int,
    val avgTurnoverMin: Int,
)

data class ORRoom(
    val id: Int,
    val name: String,
    val status: String,
    val tier: String,
    val visualStatus: String,
    val timeRemaining: Int?,
    val turnoverMin: Int?,
    val current: ORCaseInfo?,
    val next: ORNextInfo?,
) {
    val capacity: CapacityStatus get() = CapacityStatus.from(visualStatus)
}

data class ORCaseInfo(
    val procedure: String,
    val surgeon: String,
    val elapsed: Int,
    val expectedDuration: Int,
    val expectedEnd: String?,
    val startTime: String?,
)

data class ORNextInfo(
    val startTime: String?,
    val procedure: String,
)

data class HouseBrief(
    val strain: ExecStrain,
    val hero: List<HeroKpi>,
    val generatedAt: String?,
    val webLink: String?,
    val stale: Boolean,
)

data class ExecStrain(
    val level: Int,
    val label: String,
    val status: String,
    val previousLevel: Int,
    val drivers: List<StrainDriver>,
    val updatedAt: String?,
) {
    val capacity: CapacityStatus get() = CapacityStatus.from(status)
}

data class StrainDriver(
    val label: String,
    val value: String,
    val status: String,
) {
    val capacity: CapacityStatus get() = CapacityStatus.from(status)
}

data class HeroKpi(
    val key: String,
    val label: String,
    val display: String,
    val status: String,
    val targetDisplay: String?,
) {
    val capacity: CapacityStatus get() = CapacityStatus.from(status)
}

data class OpsApproval(
    val approvalUuid: String,
    val title: String,
    val rationale: String?,
    val type: String?,
    val risk: String?,
    val tier: String,
    val visualStatus: String,
    val owner: String?,
    val requestedAt: String?,
) {
    val capacity: CapacityStatus get() = CapacityStatus.from(visualStatus)
}

data class StaffingOverview(
    val metrics: StaffingMetrics,
    val unitsAtRisk: List<UnitAtRisk>,
    val queue: List<StaffingReq>,
    val webLink: String?,
    val stale: Boolean,
)

data class StaffingMetrics(
    val openRequests: Int,
    val atRiskUnits: Int,
    val criticalGaps: Int,
    val coveragePct: Int,
    val statRequests: Int,
    val totalGapHeadcount: Int,
)

data class UnitAtRisk(
    val unitId: Int,
    val unitLabel: String,
    val status: String,
    val gapHeadcount: Int,
    val worstRoleLabel: String,
    val belowMinimumSafe: Boolean,
) {
    val capacity: CapacityStatus
        get() = CapacityStatus.from(
            when (status) {
                "critical_gap" -> "critical"
                "gap" -> "warning"
                else -> status
            },
        )
}

data class StaffingReq(
    val staffingRequestId: Int,
    val unitLabel: String?,
    val roleLabel: String?,
    val priority: String,
    val status: String,
    val headcountNeeded: Int?,
    val sla: EvsSla,
) {
    val capacity: CapacityStatus
        get() = when {
            priority == "stat" -> CapacityStatus.CRITICAL
            priority == "urgent" || sla.atRisk -> CapacityStatus.WARNING
            else -> CapacityStatus.INFO
    }
}

data class StaffingCandidate(
    val staffMemberId: Int,
    val displayName: String,
    val roleLabel: String,
    val eligible: Boolean,
    val eligibilityState: String,
    val reasonCodes: List<String>,
    val overlappingAssignments: Int,
)

data class PdsaCycle(
    val id: Int,
    val title: String,
    val status: String,
    val owner: String?,
    val objective: String?,
    val unit: String?,
    val startedAt: String?,
    val targetDate: String?,
)

data class Opportunity(
    val id: Int,
    val title: String,
    val description: String?,
    val department: String?,
    val priority: String,
    val status: String,
    val impact: Int?,
) {
    val priorityTier: CapacityStatus
        get() = when (priority) {
            "High" -> CapacityStatus.CRITICAL
            "Medium" -> CapacityStatus.WARNING
            else -> CapacityStatus.INFO
        }
}

data class HouseOccupancy(
    val occupied: Int,
    val staffed: Int,
    val percent: Int,
)

data class HouseRollup(
    val occupancy: HouseOccupancy,
    val netBedNeed: Int,
    val pendingPlacements: Int,
    val edBoarding: Int,
    val units: List<CensusUnit>,
    val webLink: String?,
    val stale: Boolean,
)

data class Placement(
    val id: Int,
    val source: String?,
    val service: String?,
    val acuityTier: Int?,
    val tier: String,
    val visualStatus: String,
    val isolationRequired: String?,
    val requiredUnitType: String?,
    val at: String?,
    val patientContextRef: String?,
) {
    val capacity: CapacityStatus get() = CapacityStatus.from(visualStatus)
    val needsIsolation: Boolean get() = !isolationRequired.isNullOrBlank() && isolationRequired != "none" && isolationRequired != "false"
}

data class PlacementRecommendations(
    val recommendations: List<PlacementRecommendation>,
    val runnerUpDelta: Int?,
    val webLink: String?,
)

data class PlacementRecommendation(
    val bedId: Int,
    val bedLabel: String,
    val unitName: String,
    val score: Int,
    val chips: List<PlacementChip>,
)

data class PlacementChip(
    val label: String,
    val ok: Boolean,
)

data class PlacementDecisionResult(
    val id: Int,
    val action: String,
    val status: String?,
    val webLink: String?,
)

data class MobileRole(
    val id: String,
    val title: String,
    val subtitle: String,
    val unitBound: Boolean,
    val defaultDomain: String,
    val question: String,
    val queueFilter: QueueFilter,
    val homeKind: HomeKind,
    val androidIconName: String,
)

enum class HomeKind(
    val wireValue: String,
    val tabLabel: String,
    val workspaceLabel: String,
) {
    Census("census", "House", "Workspace"),
    TransportJobs("transportJobs", "Trips", "Trips"),
    EvsTurns("evsTurns", "Turns", "Turns"),
    HouseCapacity("houseCapacity", "House", "Capacity"),
    OrBoard("orBoard", "OR", "OR"),
    CapacityDemand("capacityDemand", "Capacity", "Approvals"),
    HouseBrief("houseBrief", "Brief", "Brief"),
    Staffing("staffing", "Staffing", "Staffing"),
    Improvement("improvement", "Improve", "Improve");

    companion object {
        fun fromWire(value: String?): HomeKind =
            entries.firstOrNull { it.wireValue == value } ?: Census
    }
}

enum class QueueFilter(val wireValue: String) {
    All("all"),
    Placements("placements"),
    Escalations("escalations"),
    MyUnit("myUnit"),
    CriticalCare("criticalCare"),
    Turns("turns"),
    None("none"),
}

object MobileRoleCatalog {
    val roles = listOf(
        MobileRole("charge_nurse", "Charge Nurse", "Run a unit - placements, barriers, staffing", true, "rtdc", "Is my unit safe to receive and discharge?", QueueFilter.MyUnit, HomeKind.Census, "group"),
        MobileRole("bedside_nurse", "Bedside / Duty Nurse", "Your patients on your unit", true, "rtdc", "Which of my patients need operational action?", QueueFilter.MyUnit, HomeKind.Census, "medical_services"),
        MobileRole("bed_manager", "Bed Manager / Flow", "House-wide capacity and placement", false, "capacity", "Can the house absorb demand?", QueueFilter.Placements, HomeKind.HouseCapacity, "bed"),
        MobileRole("house_supervisor", "House Supervisor", "House status and escalations", false, "rtdc", "What is threatening the house right now?", QueueFilter.Escalations, HomeKind.Census, "apartment"),
        MobileRole("hospitalist", "Hospitalist", "Your service and discharges", true, "rtdc", "Which patients are blocking flow or need a discharge decision?", QueueFilter.MyUnit, HomeKind.Census, "stethoscope"),
        MobileRole("intensivist", "Intensivist", "Critical care units", true, "rtdc", "Which critical-care decisions affect capacity and safety?", QueueFilter.CriticalCare, HomeKind.Census, "monitor_heart"),
        MobileRole("case_manager", "Case Manager", "Care progression, barriers, and transitions", true, "rtdc", "Which transitions or barriers need care-team follow-up?", QueueFilter.MyUnit, HomeKind.Census, "assignment_ind"),
        MobileRole("discharge_coordinator", "Discharge Coordinator", "Discharge readiness and patient transitions", true, "rtdc", "Which discharges need transition follow-up?", QueueFilter.MyUnit, HomeKind.Census, "logout"),
        MobileRole("evs", "EVS", "Bed turns and cleaning", false, "evs", "Which bed turn unlocks care next?", QueueFilter.Turns, HomeKind.EvsTurns, "cleaning_services"),
        MobileRole("transport", "Transport", "Patient moves and trips", false, "transport", "What trip needs me now?", QueueFilter.None, HomeKind.TransportJobs, "directions_walk"),
        MobileRole("or_nurse", "OR Nurse", "Room board, cases, and safety notes", false, "ops", "What case or room needs action now?", QueueFilter.None, HomeKind.OrBoard, "local_hospital"),
        MobileRole("capacity_lead", "Capacity Lead", "Capacity vs demand and approvals", false, "ops", "What decisions will change the next four hours?", QueueFilter.All, HomeKind.CapacityDemand, "bar_chart"),
        MobileRole("ops_leader", "Operations Leader", "House operations, capacity, and accountable follow-up", false, "ops", "What operational decisions need accountable follow-up now?", QueueFilter.All, HomeKind.CapacityDemand, "account_tree"),
        MobileRole("periop_manager", "Perioperative Manager", "OR day - starts, turnover, delays", false, "ops", "Is the OR day drifting?", QueueFilter.None, HomeKind.OrBoard, "event_note"),
        MobileRole("staffing_coordinator", "Staffing Coordinator", "Open requests and gaps below safe", false, "staffing", "Where are we below safe coverage?", QueueFilter.None, HomeKind.Staffing, "groups"),
        MobileRole("pi_lead", "PI / Quality Lead", "PDSA cycles and opportunities", false, "ops", "Which improvement work is tied to today's operational pain?", QueueFilter.Escalations, HomeKind.Improvement, "sync"),
        MobileRole("executive", "Executive", "Is the hospital OK? - house brief", false, "ops", "Is the hospital OK?", QueueFilter.Escalations, HomeKind.HouseBrief, "business_center"),
    )

    val default: MobileRole = roles.first { it.id == "house_supervisor" }

    fun byId(id: String?): MobileRole =
        roles.firstOrNull { it.id == id } ?: default

    fun matchingServerRoles(serverRoles: List<String>): MobileRole? {
        val normalized = serverRoles.map { it.lowercase().replace(' ', '_') }

        return roles.firstOrNull { role ->
            normalized.any { serverRole -> serverRole == role.id || serverRole.contains(role.id) || role.id.contains(serverRole) }
        }
    }
}

data class PersonaData(
    val roleId: String,
    val title: String,
    val assignmentScope: String?,
    val home: String?,
    val focus: String?,
    val question: String?,
    val web: String?,
) {
    val role: MobileRole get() = MobileRoleCatalog.byId(roleId)
}

data class OperationalStatus(
    val value: String,
    val label: String,
    val glyph: String?,
    val generatedAt: String?,
) {
    val capacity: CapacityStatus get() = CapacityStatus.from(value)
}

data class DisplayField(
    val label: String,
    val value: String,
)

data class WebLink(
    val href: String?,
    val label: String?,
    val altitude: String?,
)

data class GenericAction(
    val kind: String,
    val label: String,
    val endpoint: String?,
    val requiresOnline: Boolean,
)

data class AltitudeTile(
    val key: String,
    val label: String,
    val value: String,
    val status: String,
    val provenance: List<DisplayField>,
) {
    val capacity: CapacityStatus get() = CapacityStatus.from(status)
}

data class ActivityEvent(
    val eventUuid: String,
    val eventType: String,
    val occurredAt: String?,
    val actorRole: String?,
    val sourceSurface: String?,
    val domain: String,
    val patientContextRef: String?,
    val statusValue: String?,
    val statusLabel: String?,
)

data class ActivityFeed(
    val events: List<ActivityEvent>,
    val nextCursor: String?,
)

data class AltitudeHome(
    val altitude: String,
    val persona: PersonaData,
    val status: OperationalStatus,
    val generatedAt: String?,
    val glanceQuestion: String,
    val tiles: List<AltitudeTile>,
    val forYouHead: List<ForYouItem>,
    val activity: List<ActivityEvent>,
    val web: WebLink?,
)

data class AltitudeWorkspaceSummary(
    val label: String,
    val count: Int?,
)

data class AltitudeWorkspaceItem(
    val id: String,
    val title: String,
    val subtitle: String?,
    val domain: String,
    val status: String,
    val patientContextRef: String?,
    val drillItemId: String?,
    val fields: List<DisplayField>,
) {
    val capacity: CapacityStatus get() = CapacityStatus.from(status)
}

data class AltitudeWorkspace(
    val altitude: String,
    val persona: PersonaData,
    val domain: String,
    val generatedAt: String?,
    val status: OperationalStatus,
    val summary: AltitudeWorkspaceSummary,
    val items: List<AltitudeWorkspaceItem>,
    val activity: List<ActivityEvent>,
    val web: WebLink?,
)

data class DrillDetail(
    val altitude: String,
    val persona: PersonaData,
    val itemUuid: String,
    val generatedAt: String?,
    val domain: String,
    val status: OperationalStatus,
    val explanation: String,
    val dependencies: List<List<DisplayField>>,
    val activity: List<ActivityEvent>,
    val patientContextRef: String?,
    val actions: List<GenericAction>,
    val web: WebLink?,
)

data class PatientIdentity(
    val patientContextRef: String?,
    val display: String?,
    val phiMinimized: Boolean,
)

data class PatientListRow(
    val title: String,
    val subtitle: String?,
    val status: String?,
    val at: String?,
    val fields: List<DisplayField>,
)

data class PatientOperationalContext(
    val altitude: String,
    val persona: PersonaData,
    val patient: PatientIdentity,
    val header: List<DisplayField>,
    val statusSpine: List<PatientListRow>,
    val timeline: List<PatientListRow>,
    val dependencies: List<PatientListRow>,
    val recommendations: List<PatientListRow>,
    val actions: List<GenericAction>,
    val activity: List<ActivityEvent>,
    val web: WebLink?,
    val phiPolicy: List<DisplayField>,
)

data class EddyContext(
    val scopeRef: String,
    val scopeType: String,
    val generatedAt: String?,
    val persona: PersonaData,
    val phiPolicy: List<DisplayField>,
    val context: List<DisplayField>,
    val questionsSupported: List<String>,
)

/**
 * Carries an HTTP status so the UI can react to 401 (re-auth). `errorCode` mirrors the
 * envelope's `error.code` (e.g. "invalid_since" on a 422 delta rejection).
 */
class ApiException(message: String, val statusCode: Int? = null, val errorCode: String? = null) : Exception(message)
