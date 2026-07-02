package net.acumenus.hummingbird.data

import net.acumenus.hummingbird.ui.theme.CapacityStatus

/** Result of POST /api/auth/token — either a token pair or a must-change-password challenge. */
data class TokenResult(
    val accessToken: String?,
    val refreshToken: String?,
    val abilities: List<String>,
    val passwordChangeRequired: Boolean,
)

data class MeData(
    val id: Int,
    val name: String,
    val username: String,
    val workflowPreference: String?,
    val isAdmin: Boolean,
)

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

data class MobileRole(
    val id: String,
    val title: String,
    val defaultDomain: String,
    val question: String,
)

object MobileRoleCatalog {
    val roles = listOf(
        MobileRole("charge_nurse", "Charge Nurse", "rtdc", "Is my unit safe to receive and discharge?"),
        MobileRole("bedside_nurse", "Bedside Nurse", "rtdc", "Which of my patients need operational action?"),
        MobileRole("bed_manager", "Bed Manager", "capacity", "Can the house absorb demand?"),
        MobileRole("house_supervisor", "House Supervisor", "rtdc", "What is threatening the house right now?"),
        MobileRole("hospitalist", "Hospitalist", "rtdc", "Which patients are blocking flow?"),
        MobileRole("intensivist", "Intensivist", "rtdc", "Which critical-care decisions affect capacity?"),
        MobileRole("evs", "EVS", "evs", "Which bed turn unlocks care next?"),
        MobileRole("transport", "Transport", "transport", "What trip needs me now?"),
        MobileRole("or_nurse", "OR Nurse", "ops", "What case or room needs action now?"),
        MobileRole("capacity_lead", "Capacity Lead", "ops", "What decisions change the next four hours?"),
        MobileRole("periop_manager", "Periop Manager", "ops", "Is the OR day drifting?"),
        MobileRole("staffing_coordinator", "Staffing", "staffing", "Where are we below safe coverage?"),
        MobileRole("pi_lead", "PI Lead", "ops", "Which improvement work is tied to today's pain?"),
        MobileRole("executive", "Executive", "ops", "Is the hospital OK?"),
    )

    val default: MobileRole = roles.first { it.id == "house_supervisor" }

    fun byId(id: String?): MobileRole =
        roles.firstOrNull { it.id == id } ?: default
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

/** Carries an HTTP status so the UI can react to 401 (re-auth). */
class ApiException(message: String, val statusCode: Int? = null) : Exception(message)
