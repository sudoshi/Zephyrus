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
    val safeCapacity: Int,
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
    val tier: String,
    val title: String,
    val subtitle: String,
    val unit: String?,
    val at: String?,
) {
    val capacity: CapacityStatus get() = CapacityStatus.from(tier)
}

/** Carries an HTTP status so the UI can react to 401 (re-auth). */
class ApiException(message: String, val statusCode: Int? = null) : Exception(message)
