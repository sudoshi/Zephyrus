package net.acumenus.hummingbird.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedReader
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

/**
 * Thin coroutine API client for the Hummingbird BFF. The Android emulator reaches the Mac
 * host via 10.0.2.2, so the Dockerized `php artisan serve` on :8001 is at 10.0.2.2:8001.
 * This is the seam the KMP shared `data` module (Ktor) will replace later.
 */
class ApiClient(private val baseUrl: String = BASE_URL) {

    companion object {
        // The Android emulator reaches the Mac host loopback via 10.0.2.2.
        const val BASE_URL = "http://10.0.2.2:8001"
        const val REVERB_HOST = "10.0.2.2"
        const val REVERB_PORT = 8080
        const val REVERB_KEY = "zephyrus-key"
    }


    suspend fun token(username: String, password: String): TokenResult = withContext(Dispatchers.IO) {
        val body = JSONObject().put("username", username).put("password", password)
        val (code, text) = send("POST", "/api/auth/token", body.toString(), null)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        val json = JSONObject(text)
        TokenResult(
            accessToken = json.optStringOrNull("access_token"),
            refreshToken = json.optStringOrNull("refresh_token"),
            abilities = json.optJSONArray("abilities")?.let { arr -> List(arr.length()) { arr.getString(it) } } ?: emptyList(),
            passwordChangeRequired = json.optBoolean("password_change_required", false),
        )
    }

    suspend fun me(bearer: String): MeData = withContext(Dispatchers.IO) {
        val data = getData("/api/mobile/v1/me", bearer)
        MeData(
            id = data.optInt("id"),
            name = data.optString("name"),
            username = data.optString("username"),
            roles = data.optJSONArray("roles").strings(),
            workflowPreference = data.optStringOrNull("workflow_preference"),
            isAdmin = data.optBoolean("is_admin", false),
        )
    }

    suspend fun census(bearer: String): CensusResult = withContext(Dispatchers.IO) {
        val (code, text) = send("GET", "/api/mobile/v1/rtdc/census", null, bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        val root = JSONObject(text)
        val arr = root.getJSONArray("data")
        val units = List(arr.length()) { i -> parseCensusUnit(arr.getJSONObject(i)) }
        val meta = root.optJSONObject("meta")
        val web = root.optJSONObject("links")?.optStringOrNull("web")
        CensusResult(units, meta?.optStringOrNull("as_of"), meta?.optBoolean("stale", false) ?: false, web)
    }

    suspend fun forYou(bearer: String, persona: String? = null): List<ForYouItem> = withContext(Dispatchers.IO) {
        val (code, text) = send("GET", withPersona("/api/mobile/v1/for-you", persona), null, bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        val arr = JSONObject(text).getJSONArray("data")
        arr.objects().map(::parseForYouItem)
    }

    suspend fun resolveBarrier(bearer: String, id: Int): Boolean = withContext(Dispatchers.IO) {
        val (code, text) = send("POST", "/api/mobile/v1/rtdc/barriers/$id/resolve", "{}", bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        JSONObject(text).optJSONObject("data")?.optBoolean("resolved", true) ?: true
    }

    suspend fun transportQueue(bearer: String): TransportQueue = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/transport/queue", bearer)
        parseTransportQueue(root)
    }

    suspend fun transportStatus(bearer: String, id: Int, status: String): TransportJob = withContext(Dispatchers.IO) {
        val body = JSONObject().put("status", status)
        val (code, text) = send("POST", "/api/mobile/v1/transport/requests/$id/status", body.toString(), bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        parseTransportJob(JSONObject(text).getJSONObject("data"))
    }

    suspend fun transportHandoff(
        bearer: String,
        id: Int,
        handoffTo: String,
        summary: String?,
    ): TransportJob = withContext(Dispatchers.IO) {
        val body = JSONObject().put("handoff_to", handoffTo)
        if (!summary.isNullOrBlank()) body.put("handoff_summary", summary)
        val (code, text) = send("POST", "/api/mobile/v1/transport/requests/$id/handoff", body.toString(), bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        parseTransportJob(JSONObject(text).getJSONObject("data"))
    }

    suspend fun evsQueue(bearer: String): EvsQueue = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/evs/queue", bearer)
        parseEvsQueue(root)
    }

    suspend fun evsStatus(bearer: String, id: Int, status: String): EvsTurn = withContext(Dispatchers.IO) {
        val body = JSONObject().put("status", status)
        val (code, text) = send("POST", "/api/mobile/v1/evs/requests/$id/status", body.toString(), bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        parseEvsTurn(JSONObject(text).getJSONObject("data"))
    }

    suspend fun opsDecision(bearer: String, approvalUuid: String, decision: String): Boolean = withContext(Dispatchers.IO) {
        val body = JSONObject().put("decision", decision)
        val (code, text) = send("POST", "/api/mobile/v1/ops/approvals/${urlPart(approvalUuid)}/decision", body.toString(), bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        JSONObject(text).optJSONObject("data")?.optStringOrNull("decision") == decision
    }

    suspend fun fillStaffingRequest(bearer: String, id: Int, assignedSource: String): Boolean = withContext(Dispatchers.IO) {
        val body = JSONObject().put("assigned_source", assignedSource.ifBlank { "Mobile" })
        val (code, text) = send("POST", "/api/mobile/v1/staffing/requests/$id/fill", body.toString(), bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        JSONObject(text).has("data")
    }

    suspend fun orBoard(bearer: String): ORBoard = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/or/board", bearer)
        parseORBoard(root)
    }

    suspend fun commandHouse(bearer: String): HouseBrief = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/command/house", bearer)
        parseHouseBrief(root)
    }

    suspend fun opsInbox(bearer: String): List<OpsApproval> = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/ops/inbox", bearer)
        root.getJSONArray("data").objects().map(::parseOpsApproval)
    }

    suspend fun staffingOverview(bearer: String): StaffingOverview = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/staffing/overview", bearer)
        parseStaffingOverview(root)
    }

    suspend fun improvementPdsa(bearer: String): List<PdsaCycle> = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/improvement/pdsa", bearer)
        root.getJSONArray("data").objects().map(::parsePdsaCycle)
    }

    suspend fun improvementOpportunities(bearer: String): List<Opportunity> = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/improvement/opportunities", bearer)
        root.getJSONArray("data").objects().map(::parseOpportunity)
    }

    suspend fun rtdcHouse(bearer: String): HouseRollup = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/rtdc/house", bearer)
        parseHouseRollup(root)
    }

    suspend fun placements(bearer: String): List<Placement> = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/rtdc/bed-requests", bearer)
        root.getJSONArray("data").objects().map(::parsePlacement)
    }

    suspend fun placementRecommendations(bearer: String, id: Int): PlacementRecommendations = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/rtdc/bed-requests/$id/recommendations", bearer)
        parsePlacementRecommendations(root)
    }

    suspend fun placeBed(
        bearer: String,
        id: Int,
        action: String,
        chosenBedId: Int?,
        reason: String? = null,
    ): PlacementDecisionResult = withContext(Dispatchers.IO) {
        val body = JSONObject().put("action", action)
        chosenBedId?.let { body.put("chosen_bed_id", it) }
        if (!reason.isNullOrBlank()) body.put("reason", reason)
        val (code, text) = send("POST", "/api/mobile/v1/rtdc/bed-requests/$id/decision", body.toString(), bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        val root = JSONObject(text)
        val data = root.getJSONObject("data")
        PlacementDecisionResult(
            id = data.optInt("id", id),
            action = data.optString("action", action),
            status = data.optStringOrNull("status"),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
        )
    }

    suspend fun altitudeHome(bearer: String, persona: String): AltitudeHome = withContext(Dispatchers.IO) {
        parseAltitudeHome(getData(withPersona("/api/mobile/v1/altitude/home", persona), bearer))
    }

    suspend fun altitudeWorkspace(bearer: String, domain: String, persona: String): AltitudeWorkspace = withContext(Dispatchers.IO) {
        parseWorkspace(
            getData(withPersona("/api/mobile/v1/altitude/workspace/${urlPart(domain)}", persona), bearer),
        )
    }

    suspend fun drill(bearer: String, itemUuid: String, persona: String): DrillDetail = withContext(Dispatchers.IO) {
        parseDrill(
            getData(withPersona("/api/mobile/v1/drills/${urlPart(itemUuid)}", persona), bearer),
        )
    }

    suspend fun patientOperationalContext(
        bearer: String,
        contextRef: String,
        persona: String,
    ): PatientOperationalContext = withContext(Dispatchers.IO) {
        parsePatientContext(
            getData(withPersona("/api/mobile/v1/patients/${urlPart(contextRef)}/operational-context", persona), bearer),
        )
    }

    suspend fun activity(bearer: String, persona: String, cursor: String? = null): ActivityFeed = withContext(Dispatchers.IO) {
        val path = buildString {
            append(withPersona("/api/mobile/v1/activity", persona))
            if (!cursor.isNullOrBlank()) append("&cursor=").append(urlPart(cursor))
        }
        val root = getEnvelope(path, bearer)
        ActivityFeed(
            events = root.optJSONArray("data").objects().map(::parseActivityEvent),
            nextCursor = root.optJSONObject("meta")?.optStringOrNull("next_cursor"),
        )
    }

    suspend fun ackActivity(bearer: String, eventUuid: String, persona: String): Boolean = withContext(Dispatchers.IO) {
        val path = withPersona("/api/mobile/v1/activity/${urlPart(eventUuid)}/ack", persona)
        val (code, text) = send("POST", path, "{}", bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        JSONObject(text).optJSONObject("data")?.optBoolean("acknowledged", false) ?: false
    }

    suspend fun eddyContext(bearer: String, scopeRef: String, persona: String): EddyContext = withContext(Dispatchers.IO) {
        parseEddyContext(
            getData(withPersona("/api/mobile/v1/eddy/context/${urlPart(scopeRef)}", persona), bearer),
        )
    }

    suspend fun flowWindow(
        bearer: String,
        persona: String,
        scope: String? = null,
        since: String? = null,
    ): FlowWindowData = flowWindowRaw(bearer, persona, scope, since).data

    /**
     * Flow window with the raw envelope text retained so the caller can persist the last
     * FULL payload to the offline cache without re-serializing the hand-parsed DTOs.
     * `since` (ISO8601) requests a delta: events/snapshots trimmed to t > since; projections,
     * spaces and bed_statuses stay full. Out-of-range/malformed `since` ⇒ HTTP 422.
     */
    suspend fun flowWindowRaw(
        bearer: String,
        persona: String,
        scope: String? = null,
        since: String? = null,
    ): FlowWindowFetch = withContext(Dispatchers.IO) {
        val path = buildString {
            append(withPersona("/api/mobile/v1/flow/window", persona))
            if (!scope.isNullOrBlank()) {
                append(if (contains('?')) '&' else '?').append("scope=").append(urlPart(scope))
            }
            if (!since.isNullOrBlank()) {
                append(if (contains('?')) '&' else '?').append("since=").append(urlPart(since))
            }
        }
        val (root, raw) = getEnvelopeRaw(path, bearer)
        FlowWindowFetch(parseFlowWindow(root), raw)
    }

    suspend fun flowFloors(bearer: String): FlowFloorsDocument = flowFloorsRaw(bearer).first

    /** Floors document with its raw envelope text retained for the offline cache. */
    suspend fun flowFloorsRaw(bearer: String): Pair<FlowFloorsDocument, String> = withContext(Dispatchers.IO) {
        val (root, raw) = getEnvelopeRaw("/api/mobile/v1/flow/floors", bearer)
        parseFlowFloors(root) to raw
    }

    suspend fun revoke(bearer: String) = withContext(Dispatchers.IO) {
        runCatching { send("POST", "/api/auth/token/revoke", "{}", bearer) }
        Unit
    }

    // MARK: plumbing

    private fun getEnvelope(path: String, bearer: String): JSONObject = getEnvelopeRaw(path, bearer).first

    /** Like [getEnvelope] but also returns the raw response body (for the offline cache). */
    private fun getEnvelopeRaw(path: String, bearer: String): Pair<JSONObject, String> {
        val (code, text) = send("GET", path, null, bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code, errorCode(text))
        return JSONObject(text) to text
    }

    private fun getData(path: String, bearer: String): JSONObject {
        return getEnvelope(path, bearer).getJSONObject("data")
    }

    private fun send(method: String, path: String, body: String?, bearer: String?): Pair<Int, String> {
        val conn = (URL(baseUrl + path).openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = 15000
            readTimeout = 15000
            setRequestProperty("Accept", "application/json")
            bearer?.let { setRequestProperty("Authorization", "Bearer $it") }
            if (body != null) {
                doOutput = true
                setRequestProperty("Content-Type", "application/json")
            }
        }
        try {
            if (body != null) conn.outputStream.use { it.write(body.toByteArray()) }
            val code = conn.responseCode
            val stream = if (code in 200..299) conn.inputStream else conn.errorStream
            val text = stream?.bufferedReader()?.use(BufferedReader::readText) ?: ""
            return code to text
        } catch (e: Exception) {
            throw ApiException("Can't reach the server at $baseUrl. Is it running?", null)
        } finally {
            conn.disconnect()
        }
    }

    /** The envelope's `error.code` (e.g. "invalid_since"), when present. */
    private fun errorCode(text: String): String? = runCatching {
        JSONObject(text).optJSONObject("error")?.optStringOrNull("code")
    }.getOrNull()

    private fun errorMessage(text: String, code: Int): String {
        runCatching {
            val o = JSONObject(text)
            o.optJSONObject("error")?.optStringOrNull("message")?.let { return it }
            o.optStringOrNull("message")?.let { if (it.isNotEmpty()) return it }
        }
        if (code == 401) return "Your session has expired. Please sign in again."
        return "Request failed (HTTP $code)."
    }

    private fun withPersona(path: String, persona: String?): String {
        if (persona.isNullOrBlank()) return path
        return path + if (path.contains("?")) "&persona=${urlPart(persona)}" else "?persona=${urlPart(persona)}"
    }

    private fun urlPart(value: String): String = URLEncoder.encode(value, "UTF-8")

    internal fun parseAltitudeHome(data: JSONObject): AltitudeHome = AltitudeHome(
        altitude = data.optString("altitude", "A0"),
        persona = parsePersona(data.optJSONObject("persona")),
        status = parseStatus(data.optJSONObject("status")),
        generatedAt = data.optStringOrNull("generated_at"),
        glanceQuestion = data.optStringOrNull("glance_question")
            ?: data.optJSONObject("persona")?.optStringOrNull("question")
            ?: "What needs attention right now?",
        tiles = data.optJSONArray("tiles").objects().map(::parseTile),
        forYouHead = data.optJSONArray("for_you_head").objects().map(::parseForYouItem),
        activity = data.optJSONArray("activity").objects().map(::parseActivityEvent),
        web = parseWeb(data.optJSONObject("web")),
    )

    private fun parseWorkspace(data: JSONObject): AltitudeWorkspace {
        val workspace = data.optJSONObject("workspace") ?: JSONObject()
        val domain = data.optString("domain", "ops")
        val summary = workspace.optJSONObject("summary")
        return AltitudeWorkspace(
            altitude = data.optString("altitude", "A1"),
            persona = parsePersona(data.optJSONObject("persona")),
            domain = domain,
            generatedAt = data.optStringOrNull("generated_at"),
            status = parseStatus(data.optJSONObject("status")),
            summary = AltitudeWorkspaceSummary(
                label = summary?.optStringOrNull("label") ?: humanize(domain),
                count = summary?.optIntOrNull("count"),
            ),
            items = workspace.optJSONArray("items").objects().map { parseWorkspaceItem(domain, it) },
            activity = data.optJSONArray("activity").objects().map(::parseActivityEvent),
            web = parseWeb(data.optJSONObject("web")),
        )
    }

    private fun parseDrill(data: JSONObject): DrillDetail = DrillDetail(
        altitude = data.optString("altitude", "A2"),
        persona = parsePersona(data.optJSONObject("persona")),
        itemUuid = data.optString("item_uuid"),
        generatedAt = data.optStringOrNull("generated_at"),
        domain = data.optString("domain", "ops"),
        status = parseStatus(data.optJSONObject("status"), normalizeStatus(data.optStringOrNull("status"))),
        explanation = data.optStringOrNull("explanation") ?: "Operational drill detail.",
        dependencies = data.optJSONArray("dependencies").objects().map { safeFields(it) },
        activity = data.optJSONArray("activity").objects().map(::parseActivityEvent),
        patientContextRef = data.optStringOrNull("patient_context_ref"),
        actions = data.optJSONArray("actions").objects().map(::parseAction),
        web = parseWeb(data.optJSONObject("web")),
    )

    internal fun parsePatientContext(data: JSONObject): PatientOperationalContext {
        val patient = data.optJSONObject("patient") ?: JSONObject()
        return PatientOperationalContext(
            altitude = data.optString("altitude", "A2P"),
            persona = parsePersona(data.optJSONObject("persona")),
            patient = PatientIdentity(
                patientContextRef = patient.optStringOrNull("patient_context_ref"),
                display = patient.optStringOrNull("display"),
                phiMinimized = patient.optBoolean("phi_minimized", true),
            ),
            header = safeFields(data.optJSONObject("header")),
            statusSpine = data.optJSONArray("status_spine").objects().map(::parseStatusRow),
            timeline = data.optJSONArray("timeline").objects().map(::parseTimelineRow),
            dependencies = data.optJSONArray("dependencies").objects().map(::parseDependencyRow),
            recommendations = data.optJSONArray("recommendations").objects().map(::parseRecommendationRow),
            actions = data.optJSONArray("actions").objects().map(::parseAction),
            activity = data.optJSONArray("activity").objects().map(::parseActivityEvent),
            web = parseWeb(data.optJSONObject("web")),
            phiPolicy = safeFields(data.optJSONObject("phi_policy")),
        )
    }

    private fun parseEddyContext(data: JSONObject): EddyContext = EddyContext(
        scopeRef = data.optString("scope_ref"),
        scopeType = data.optString("scope_type", "scope"),
        generatedAt = data.optStringOrNull("generated_at"),
        persona = parsePersona(data.optJSONObject("persona")),
        phiPolicy = safeFields(data.optJSONObject("phi_policy")),
        context = summarizeContext(data.optJSONObject("context")),
        questionsSupported = data.optJSONArray("questions_supported").strings(),
    )

    private fun parsePersona(o: JSONObject?): PersonaData {
        val roleId = o?.optStringOrNull("role_id") ?: MobileRoleCatalog.default.id
        val local = MobileRoleCatalog.byId(roleId)
        return PersonaData(
            roleId = roleId,
            title = o?.optStringOrNull("title") ?: local.title,
            assignmentScope = o?.optStringOrNull("assignment_scope"),
            home = o?.optStringOrNull("home"),
            focus = o?.optStringOrNull("focus"),
            question = o?.optStringOrNull("question") ?: local.question,
            web = o?.optStringOrNull("web"),
        )
    }

    private fun parseStatus(o: JSONObject?, fallback: String = "info"): OperationalStatus {
        val value = normalizeStatus(o?.optStringOrNull("value") ?: fallback)
        return OperationalStatus(
            value = value,
            label = o?.optStringOrNull("label") ?: humanize(value),
            glyph = o?.optStringOrNull("glyph"),
            generatedAt = o?.optStringOrNull("generated_at"),
        )
    }

    private fun parseCensusUnit(o: JSONObject): CensusUnit = CensusUnit(
        unitId = o.optInt("unit_id"),
        name = o.optString("name"),
        type = o.optString("type"),
        staffedBedCount = o.optInt("staffed_bed_count"),
        occupied = o.optInt("occupied"),
        available = o.optInt("available"),
        blocked = o.optInt("blocked"),
        canAdmit = o.optInt("can_admit"),
        bedNeed = o.optInt("bed_need"),
        status = o.optString("status", "info"),
    )

    private fun parseTile(o: JSONObject): AltitudeTile {
        val status = normalizeStatus(o.optStringOrNull("status"))
        return AltitudeTile(
            key = o.optString("key"),
            label = o.optStringOrNull("label") ?: humanize(o.optString("key", "tile")),
            value = o.optString("value"),
            status = status,
            provenance = safeFields(o.optJSONObject("provenance")),
        )
    }

    internal fun parseForYouItem(o: JSONObject): ForYouItem {
        val status = normalizeStatus(
            o.optStringOrNull("visual_status")
                ?: o.optStringOrNull("tier")
                ?: o.optStringOrNull("status")
                ?: o.optJSONObject("status_detail")?.optStringOrNull("value"),
        )
        return ForYouItem(
            id = o.optString("id"),
            type = o.optString("type"),
            domain = o.optStringOrNull("domain"),
            tier = status,
            title = o.optStringOrNull("title") ?: "Operational item",
            subtitle = o.optStringOrNull("subtitle") ?: "",
            unit = o.optStringOrNull("unit"),
            at = o.optStringOrNull("at") ?: o.optStringOrNull("created_at"),
            patientContextRef = o.optStringOrNull("patient_context_ref"),
        )
    }

    internal fun parseTransportQueue(root: JSONObject): TransportQueue {
        val data = root.getJSONObject("data")
        val metrics = data.optJSONObject("metrics") ?: JSONObject()

        return TransportQueue(
            metrics = TransportMetrics(
                active = metrics.optInt("active"),
                stat = metrics.optInt("stat"),
                atRisk = metrics.optInt("at_risk"),
                completedToday = metrics.optInt("completed_today"),
            ),
            jobs = data.optJSONArray("jobs").objects().map(::parseTransportJob),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
            stale = root.optJSONObject("meta")?.optBoolean("stale", false) ?: false,
        )
    }

    internal fun parseTransportJob(o: JSONObject): TransportJob {
        val visualStatus = normalizeStatus(
            o.optStringOrNull("visual_status")
                ?: o.optStringOrNull("tier")
                ?: o.optStringOrNull("priority")
                ?: o.optStringOrNull("status"),
        )
        val sla = o.optJSONObject("sla") ?: JSONObject()

        return TransportJob(
            id = o.optInt("id"),
            uuid = o.optStringOrNull("uuid"),
            type = o.optStringOrNull("type") ?: "transport",
            priority = o.optStringOrNull("priority") ?: "routine",
            status = o.optStringOrNull("status") ?: "requested",
            visualStatus = visualStatus,
            origin = o.optStringOrNull("origin"),
            destination = o.optStringOrNull("destination"),
            mode = o.optStringOrNull("mode"),
            neededAt = o.optStringOrNull("needed_at"),
            patientContextRef = o.optStringOrNull("patient_context_ref"),
            sla = TransportSla(
                minutesUntilDue = sla.optIntOrNull("minutes_until_due"),
                atRisk = sla.optBoolean("at_risk", false),
                label = sla.optStringOrNull("label") ?: "No target",
            ),
        )
    }

    internal fun parseEvsQueue(root: JSONObject): EvsQueue {
        val data = root.getJSONObject("data")
        val metrics = data.optJSONObject("metrics") ?: JSONObject()

        return EvsQueue(
            metrics = EvsMetrics(
                pending = metrics.optInt("pending"),
                overdue = metrics.optInt("overdue"),
                isolation = metrics.optInt("isolation"),
                completedToday = metrics.optInt("completed_today"),
            ),
            turns = data.optJSONArray("turns").objects().map(::parseEvsTurn),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
            stale = root.optJSONObject("meta")?.optBoolean("stale", false) ?: false,
        )
    }

    internal fun parseEvsTurn(o: JSONObject): EvsTurn {
        val visualStatus = normalizeStatus(
            o.optStringOrNull("visual_status")
                ?: o.optStringOrNull("tier")
                ?: o.optStringOrNull("priority")
                ?: o.optStringOrNull("status"),
        )
        val sla = o.optJSONObject("sla") ?: JSONObject()

        return EvsTurn(
            id = o.optInt("id"),
            uuid = o.optStringOrNull("uuid"),
            requestType = o.optStringOrNull("request_type") ?: "clean",
            priority = o.optStringOrNull("priority") ?: "routine",
            status = o.optStringOrNull("status") ?: "requested",
            visualStatus = visualStatus,
            locationLabel = o.optStringOrNull("location_label"),
            unitId = o.optIntOrNull("unit_id"),
            turnType = o.optStringOrNull("turn_type"),
            isolationRequired = o.optBoolean("isolation_required", false),
            neededAt = o.optStringOrNull("needed_at"),
            patientContextRef = o.optStringOrNull("patient_context_ref"),
            sla = EvsSla(
                minutesUntilDue = sla.optIntOrNull("minutes_until_due"),
                atRisk = sla.optBoolean("at_risk", false),
                label = sla.optStringOrNull("label") ?: "No target",
            ),
        )
    }

    internal fun parseORBoard(root: JSONObject): ORBoard {
        val data = root.getJSONObject("data")
        val metrics = data.optJSONObject("metrics") ?: JSONObject()

        return ORBoard(
            rooms = data.optJSONArray("rooms").objects().map(::parseORRoom),
            metrics = ORMetrics(
                running = metrics.optInt("running"),
                turnover = metrics.optInt("turnover"),
                available = metrics.optInt("available"),
                total = metrics.optInt("total"),
                avgTurnoverMin = metrics.optInt("avg_turnover_min"),
            ),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
            stale = root.optJSONObject("meta")?.optBoolean("stale", false) ?: false,
        )
    }

    private fun parseORRoom(o: JSONObject): ORRoom {
        val visualStatus = normalizeStatus(
            o.optStringOrNull("visual_status")
                ?: o.optStringOrNull("tier")
                ?: o.optStringOrNull("status"),
        )

        return ORRoom(
            id = o.optInt("id"),
            name = o.optStringOrNull("name") ?: "OR-${o.optInt("id")}",
            status = o.optStringOrNull("status") ?: "available",
            tier = normalizeStatus(o.optStringOrNull("tier")),
            visualStatus = visualStatus,
            timeRemaining = o.optIntOrNull("time_remaining"),
            turnoverMin = o.optIntOrNull("turnover_min"),
            current = parseORCaseInfo(o.optJSONObject("current")),
            next = parseORNextInfo(o.optJSONObject("next")),
        )
    }

    private fun parseORCaseInfo(o: JSONObject?): ORCaseInfo? {
        if (o == null) return null

        return ORCaseInfo(
            procedure = o.optStringOrNull("procedure") ?: "Procedure",
            surgeon = o.optStringOrNull("surgeon") ?: "Care team",
            elapsed = o.optInt("elapsed"),
            expectedDuration = o.optInt("expected_duration"),
            expectedEnd = o.optStringOrNull("expected_end"),
            startTime = o.optStringOrNull("start_time"),
        )
    }

    private fun parseORNextInfo(o: JSONObject?): ORNextInfo? {
        if (o == null) return null

        return ORNextInfo(
            startTime = o.optStringOrNull("start_time"),
            procedure = o.optStringOrNull("procedure") ?: "Procedure",
        )
    }

    internal fun parseHouseBrief(root: JSONObject): HouseBrief {
        val data = root.getJSONObject("data")

        return HouseBrief(
            strain = parseExecStrain(data.optJSONObject("strain")),
            hero = data.optJSONArray("hero").objects().map(::parseHeroKpi),
            generatedAt = data.optStringOrNull("generated_at"),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
            stale = root.optJSONObject("meta")?.optBoolean("stale", false) ?: false,
        )
    }

    private fun parseExecStrain(o: JSONObject?): ExecStrain = ExecStrain(
        level = o?.optInt("level") ?: 0,
        label = o?.optStringOrNull("label") ?: "Surge Level 0",
        status = normalizeStatus(o?.optStringOrNull("status")),
        previousLevel = o?.optInt("previousLevel") ?: 0,
        drivers = o?.optJSONArray("drivers").objects().map(::parseStrainDriver),
        updatedAt = o?.optStringOrNull("updatedAtIso"),
    )

    private fun parseStrainDriver(o: JSONObject): StrainDriver = StrainDriver(
        label = o.optStringOrNull("label") ?: "Driver",
        value = o.optStringOrNull("value") ?: "-",
        status = normalizeStatus(o.optStringOrNull("status")),
    )

    private fun parseHeroKpi(o: JSONObject): HeroKpi = HeroKpi(
        key = o.optStringOrNull("key") ?: o.toString().hashCode().toString(),
        label = o.optStringOrNull("label") ?: "Metric",
        display = o.optStringOrNull("display") ?: o.optStringOrNull("value") ?: "-",
        status = normalizeStatus(o.optStringOrNull("status")),
        targetDisplay = o.optStringOrNull("target_display"),
    )

    internal fun parseOpsApproval(o: JSONObject): OpsApproval {
        val visualStatus = normalizeStatus(
            o.optStringOrNull("visual_status")
                ?: o.optStringOrNull("tier")
                ?: o.optStringOrNull("risk"),
        )

        return OpsApproval(
            approvalUuid = o.optStringOrNull("approval_uuid") ?: "",
            title = o.optStringOrNull("title") ?: "Operational approval",
            rationale = o.optStringOrNull("rationale"),
            type = o.optStringOrNull("type"),
            risk = o.optStringOrNull("risk"),
            tier = normalizeStatus(o.optStringOrNull("tier")),
            visualStatus = visualStatus,
            owner = o.optStringOrNull("owner"),
            requestedAt = o.optStringOrNull("requested_at"),
        )
    }

    internal fun parseStaffingOverview(root: JSONObject): StaffingOverview {
        val data = root.getJSONObject("data")

        return StaffingOverview(
            metrics = parseStaffingMetrics(data.optJSONObject("metrics") ?: JSONObject()),
            unitsAtRisk = data.optJSONArray("units_at_risk").objects().map(::parseUnitAtRisk),
            queue = data.optJSONArray("queue").objects().map(::parseStaffingReq),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
            stale = root.optJSONObject("meta")?.optBoolean("stale", false) ?: false,
        )
    }

    private fun parseStaffingMetrics(o: JSONObject): StaffingMetrics = StaffingMetrics(
        openRequests = o.optInt("open_requests"),
        atRiskUnits = o.optInt("at_risk_units"),
        criticalGaps = o.optInt("critical_gaps"),
        coveragePct = o.optInt("coverage_pct"),
        statRequests = o.optInt("stat_requests"),
        totalGapHeadcount = o.optInt("total_gap_headcount"),
    )

    private fun parseUnitAtRisk(o: JSONObject): UnitAtRisk = UnitAtRisk(
        unitId = o.optInt("unit_id"),
        unitLabel = o.optStringOrNull("unit_label") ?: "Unit",
        status = o.optStringOrNull("status") ?: "gap",
        gapHeadcount = o.optInt("gap_headcount"),
        worstRoleLabel = o.optStringOrNull("worst_role_label") ?: "Staff",
        belowMinimumSafe = o.optBoolean("below_minimum_safe", false),
    )

    internal fun parseStaffingReq(o: JSONObject): StaffingReq {
        val sla = o.optJSONObject("sla") ?: JSONObject()

        return StaffingReq(
            staffingRequestId = o.optInt("staffing_request_id"),
            unitLabel = o.optStringOrNull("unit_label"),
            roleLabel = o.optStringOrNull("role_label"),
            priority = o.optStringOrNull("priority") ?: "routine",
            status = o.optStringOrNull("status") ?: "requested",
            headcountNeeded = o.optIntOrNull("headcount_needed"),
            sla = EvsSla(
                minutesUntilDue = sla.optIntOrNull("minutes_until_due"),
                atRisk = sla.optBoolean("at_risk", false),
                label = sla.optStringOrNull("label") ?: "No target",
            ),
        )
    }

    internal fun parsePdsaCycle(o: JSONObject): PdsaCycle = PdsaCycle(
        id = o.optInt("id"),
        title = o.optStringOrNull("title") ?: "PDSA cycle",
        status = o.optStringOrNull("status") ?: "active",
        owner = o.optStringOrNull("owner"),
        objective = o.optStringOrNull("objective"),
        unit = o.optStringOrNull("unit"),
        startedAt = o.optStringOrNull("started_at"),
        targetDate = o.optStringOrNull("target_date"),
    )

    internal fun parseOpportunity(o: JSONObject): Opportunity = Opportunity(
        id = o.optInt("id"),
        title = o.optStringOrNull("title") ?: "Improvement opportunity",
        description = o.optStringOrNull("description"),
        department = o.optStringOrNull("department"),
        priority = o.optStringOrNull("priority") ?: "Low",
        status = o.optStringOrNull("status") ?: "Open",
        impact = o.optIntOrNull("impact"),
    )

    internal fun parseFlowWindow(root: JSONObject): FlowWindowData {
        val data = root.getJSONObject("data")
        val window = data.optJSONObject("window") ?: JSONObject()
        val lens = data.optJSONObject("lens") ?: JSONObject()
        val scope = data.optJSONObject("scope") ?: JSONObject()
        val spaces = data.optJSONObject("spaces")

        return FlowWindowData(
            window = FlowWindowRange(
                from = window.optString("from"),
                to = window.optString("to"),
                now = window.optString("now"),
                since = window.optStringOrNull("since"),
            ),
            lens = FlowLens(
                roleId = lens.optString("role_id"),
                scopesAllowed = lens.optJSONArray("scopes_allowed").strings(),
                layers = lens.optJSONArray("layers").strings(),
                eventKinds = lens.optJSONArray("event_kinds").strings(),
                projectionKinds = lens.optJSONArray("projection_kinds").strings(),
                patientDots = lens.optString("patient_dots", "none"),
                actions = lens.optJSONArray("actions").strings(),
                defaultZoomHours = lens.optInt("default_zoom_hours", 48),
            ),
            scope = FlowScope(
                type = scope.optString("type", "house"),
                floor = scope.optIntOrNull("floor"),
                unitId = scope.optIntOrNull("unit_id"),
                patientContextRef = scope.optStringOrNull("patient_context_ref"),
                label = scope.optStringOrNull("label") ?: "House",
            ),
            spacesFloors = spaces?.optJSONArray("floors").objects().map(::parseFlowFloorRollup),
            snapshots = data.optJSONArray("snapshots").objects().map(::parseFlowSnapshot),
            events = data.optJSONArray("events").objects().map(::parseFlowTimelineEvent),
            projections = data.optJSONArray("projections").objects().map(::parseFlowProjection),
            // Optional turn-map layer — absent unless scope is floor/unit and the lens allows it.
            bedStatuses = data.optJSONArray("bed_statuses").objects().map(::parseFlowBedStatus),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
        )
    }

    private fun parseFlowBedStatus(o: JSONObject): FlowBedStatus = FlowBedStatus(
        bedId = o.optInt("bed_id"),
        unitId = o.optIntOrNull("unit_id"),
        label = o.optStringOrNull("label") ?: "Bed ${o.optInt("bed_id")}",
        status = o.optStringOrNull("status") ?: "occupied",
    )

    private fun parseFlowFloorRollup(o: JSONObject): FlowFloorRollup = FlowFloorRollup(
        floor = o.optInt("floor"),
        label = o.optStringOrNull("label") ?: "Floor ${o.optInt("floor")}",
        staffed = o.optInt("staffed"),
        occupied = o.optInt("occupied"),
        occupancyPct = o.optInt("occupancy_pct"),
        units = o.optJSONArray("units").objects().map(::parseFlowUnitRollup),
    )

    private fun parseFlowUnitRollup(o: JSONObject): FlowUnitRollup = FlowUnitRollup(
        unitId = o.optInt("unit_id"),
        abbr = o.optStringOrNull("abbr") ?: "",
        name = o.optStringOrNull("name") ?: "Unit",
        staffed = o.optInt("staffed"),
        occupied = o.optInt("occupied"),
        available = o.optInt("available"),
        blocked = o.optInt("blocked"),
        occupancyPct = o.optInt("occupancy_pct"),
    )

    private fun parseFlowSnapshot(o: JSONObject): FlowSnapshot = FlowSnapshot(
        t = o.optString("t"),
        unitId = o.optInt("unit_id"),
        staffed = o.optInt("staffed"),
        occupied = o.optInt("occupied"),
        available = o.optInt("available"),
        blocked = o.optInt("blocked"),
    )

    private fun parseFlowTimelineEvent(o: JSONObject): FlowTimelineEvent = FlowTimelineEvent(
        t = o.optString("t"),
        kind = o.optString("kind"),
        label = o.optStringOrNull("label") ?: humanize(o.optString("kind", "event")),
        tier = o.optStringOrNull("tier") ?: "info",
        unitId = o.optIntOrNull("unit_id"),
        fromSpace = o.optStringOrNull("from_space"),
        toSpace = o.optStringOrNull("to_space"),
        patientContextRef = o.optStringOrNull("patient_context_ref"),
        provenanceSource = o.optJSONObject("provenance")?.optStringOrNull("source") ?: "",
        entityRef = o.optJSONObject("entity")?.optStringOrNull("ref"),
    )

    private fun parseFlowProjection(o: JSONObject): FlowProjection {
        val band = o.optJSONObject("band")
        val provenance = o.optJSONObject("provenance")
        return FlowProjection(
            t = o.optString("t"),
            kind = o.optString("kind"),
            confidence = o.optStringOrNull("confidence") ?: "possible",
            label = o.optStringOrNull("label") ?: humanize(o.optString("kind", "projection")),
            unitId = o.optIntOrNull("unit_id"),
            bedId = o.optIntOrNull("bed_id"),
            room = o.optStringOrNull("room"),
            value = o.optIntOrNull("value"),
            bandLower = band?.optIntOrNull("lower"),
            bandUpper = band?.optIntOrNull("upper"),
            endsAt = o.optStringOrNull("ends_at"),
            derived = o.optBoolean("derived", false),
            patientContextRef = o.optStringOrNull("patient_context_ref"),
            provenanceService = provenance?.optStringOrNull("service") ?: "",
            provenanceReliability = provenance?.optDoubleOrNull("reliability"),
        )
    }

    internal fun parseFlowFloors(root: JSONObject): FlowFloorsDocument {
        val data = root.getJSONObject("data")
        return FlowFloorsDocument(
            version = data.optStringOrNull("version")
                ?: root.optJSONObject("meta")?.optStringOrNull("version")
                ?: "",
            floors = data.optJSONArray("floors").objects().map(::parseFlowFloor),
        )
    }

    private fun parseFlowFloor(o: JSONObject): FlowFloor = FlowFloor(
        floor = o.optInt("floor"),
        label = o.optStringOrNull("label") ?: "Floor ${o.optInt("floor")}",
        bounds = o.optJSONArray("bounds").doubles(),
        spaces = o.optJSONArray("spaces").objects().map(::parseFlowPlate),
    )

    private fun parseFlowPlate(o: JSONObject): FlowPlate = FlowPlate(
        id = o.optInt("id"),
        code = o.optString("code"),
        category = o.optStringOrNull("category") ?: "room",
        label = o.optStringOrNull("label") ?: o.optString("code"),
        rect = o.optJSONArray("rect").doubles(),
        unitId = o.optIntOrNull("unit_id"),
        bedId = o.optIntOrNull("bed_id"),
    )

    internal fun parseHouseRollup(root: JSONObject): HouseRollup {
        val data = root.getJSONObject("data")
        val occupancy = data.optJSONObject("occupancy") ?: JSONObject()

        return HouseRollup(
            occupancy = HouseOccupancy(
                occupied = occupancy.optInt("occupied"),
                staffed = occupancy.optInt("staffed"),
                percent = occupancy.optInt("percent"),
            ),
            netBedNeed = data.optInt("net_bed_need"),
            pendingPlacements = data.optInt("pending_placements"),
            edBoarding = data.optInt("ed_boarding"),
            units = data.optJSONArray("units").objects().map(::parseCensusUnit),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
            stale = root.optJSONObject("meta")?.optBoolean("stale", false) ?: false,
        )
    }

    internal fun parsePlacement(o: JSONObject): Placement {
        val visualStatus = normalizeStatus(
            o.optStringOrNull("visual_status")
                ?: o.optStringOrNull("tier")
                ?: o.optStringOrNull("status"),
        )

        return Placement(
            id = o.optInt("id"),
            source = o.optStringOrNull("source"),
            service = o.optStringOrNull("service"),
            acuityTier = o.optIntOrNull("acuity_tier"),
            tier = normalizeStatus(o.optStringOrNull("tier")),
            visualStatus = visualStatus,
            isolationRequired = o.optIsolation("isolation_required"),
            requiredUnitType = o.optStringOrNull("required_unit_type"),
            at = o.optStringOrNull("at"),
            patientContextRef = o.optStringOrNull("patient_context_ref"),
        )
    }

    internal fun parsePlacementRecommendations(root: JSONObject): PlacementRecommendations {
        val data = root.getJSONObject("data")

        return PlacementRecommendations(
            recommendations = data.optJSONArray("recommendations").objects().map(::parsePlacementRecommendation),
            runnerUpDelta = data.optIntOrNull("runner_up_delta"),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
        )
    }

    private fun parsePlacementRecommendation(o: JSONObject): PlacementRecommendation = PlacementRecommendation(
        bedId = o.optInt("bed_id"),
        bedLabel = o.optStringOrNull("bed_label") ?: "Bed",
        unitName = o.optStringOrNull("unit_name") ?: "Unit",
        score = o.optDouble("score", 0.0).toInt(),
        chips = o.optJSONArray("chips").objects().map { chip ->
            PlacementChip(
                label = chip.optStringOrNull("label") ?: "Constraint",
                ok = chip.optBoolean("ok", false),
            )
        },
    )

    private fun parseWorkspaceItem(domain: String, o: JSONObject): AltitudeWorkspaceItem {
        val id = o.optStringOrNull("id") ?: o.optStringOrNull("approval_uuid") ?: o.toString().hashCode().toString()
        val status = normalizeStatus(
            o.optStringOrNull("visual_status")
                ?: o.optStringOrNull("tier")
                ?: o.optStringOrNull("priority")
                ?: o.optStringOrNull("status"),
        )
        return AltitudeWorkspaceItem(
            id = id,
            title = workspaceTitle(domain, o),
            subtitle = workspaceSubtitle(o),
            domain = domain,
            status = status,
            patientContextRef = o.optStringOrNull("patient_context_ref"),
            drillItemId = drillIdForWorkspace(domain, id, o),
            fields = safeFields(
                o,
                exclude = setOf("id", "title", "subtitle", "visual_status", "patient_context_ref", "patient_ref"),
            ),
        )
    }

    internal fun parseActivityEvent(o: JSONObject): ActivityEvent {
        val status = o.optJSONObject("status")
        val statusValue = normalizeStatus(
            status?.optStringOrNull("value")
                ?: status?.optStringOrNull("severity")
                ?: status?.optStringOrNull("status_after")
                ?: status?.optStringOrNull("current")
                ?: status?.optStringOrNull("status"),
        )
        return ActivityEvent(
            eventUuid = o.optString("event_uuid"),
            eventType = o.optString("event_type"),
            occurredAt = o.optStringOrNull("occurred_at"),
            actorRole = o.optStringOrNull("actor_role"),
            sourceSurface = o.optStringOrNull("source_surface"),
            domain = o.optString("domain", "ops"),
            patientContextRef = o.optStringOrNull("patient_context_ref"),
            statusValue = statusValue,
            statusLabel = status?.optStringOrNull("label") ?: humanize(statusValue),
        )
    }

    private fun parseAction(o: JSONObject): GenericAction {
        val kind = o.optString("kind", "view")
        return GenericAction(
            kind = kind,
            label = o.optStringOrNull("label") ?: humanize(kind),
            endpoint = o.optStringOrNull("endpoint"),
            requiresOnline = o.optBoolean("requires_online", true),
        )
    }

    private fun parseStatusRow(o: JSONObject): PatientListRow = PatientListRow(
        title = o.optStringOrNull("label") ?: humanize(o.optString("domain", "status")),
        subtitle = o.optStringOrNull("domain")?.let(::humanize),
        status = normalizeStatus(o.optStringOrNull("status")),
        at = o.optStringOrNull("at"),
        fields = safeFields(o, exclude = setOf("label", "domain", "status", "at")),
    )

    private fun parseTimelineRow(o: JSONObject): PatientListRow = PatientListRow(
        title = humanize(o.optString("event_type", "event")),
        subtitle = listOfNotNull(o.optStringOrNull("domain")?.let(::humanize), o.optStringOrNull("actor_role")?.let(::humanize))
            .joinToString(" / ")
            .ifBlank { null },
        status = normalizeStatus(o.optStringOrNull("status_after")),
        at = o.optStringOrNull("occurred_at"),
        fields = safeFields(o, exclude = setOf("event_type", "domain", "actor_role", "status_after", "occurred_at")),
    )

    private fun parseDependencyRow(o: JSONObject): PatientListRow = PatientListRow(
        title = o.optStringOrNull("label") ?: humanize(o.optString("dependency_type", "dependency")),
        subtitle = o.optStringOrNull("owner_role")?.let { "Owner: ${humanize(it)}" },
        status = normalizeStatus(o.optStringOrNull("status")),
        at = null,
        fields = safeFields(o, exclude = setOf("label", "dependency_type", "owner_role", "status")),
    )

    private fun parseRecommendationRow(o: JSONObject): PatientListRow = PatientListRow(
        title = o.optStringOrNull("title") ?: "Recommendation",
        subtitle = o.optStringOrNull("rationale") ?: o.optStringOrNull("source"),
        status = normalizeStatus(o.optStringOrNull("risk_level") ?: o.optStringOrNull("status")),
        at = null,
        fields = safeFields(o, exclude = setOf("title", "rationale", "source", "risk_level", "status")),
    )

    private fun parseWeb(o: JSONObject?): WebLink? {
        if (o == null) return null
        return WebLink(
            href = o.optStringOrNull("href"),
            label = o.optStringOrNull("label"),
            altitude = o.optStringOrNull("altitude"),
        )
    }

    private fun workspaceTitle(domain: String, o: JSONObject): String =
        o.optStringOrNull("title") ?: when (domain) {
            "transport" -> listOfNotNull(o.optStringOrNull("origin"), o.optStringOrNull("destination"))
                .joinToString(" to ")
                .ifBlank { "Transport job" }
            "evs" -> o.optStringOrNull("location_label") ?: "EVS turn"
            "staffing" -> listOfNotNull(o.optStringOrNull("role")?.let(::humanize), o.optStringOrNull("unit_label"))
                .joinToString(" / ")
                .ifBlank { "Staffing request" }
            "ops", "approvals" -> o.optStringOrNull("title") ?: "Operational approval"
            else -> o.optStringOrNull("type")?.let(::humanize) ?: "${humanize(domain)} item"
        }

    private fun workspaceSubtitle(o: JSONObject): String? =
        listOfNotNull(
            o.optStringOrNull("priority")?.let(::humanize),
            o.optStringOrNull("status")?.let(::humanize),
            o.optStringOrNull("turn_type")?.let(::humanize),
            o.optStringOrNull("needed_by"),
            o.optStringOrNull("requested_at"),
        ).joinToString(" / ").ifBlank { null }

    private fun drillIdForWorkspace(domain: String, id: String, o: JSONObject): String? {
        if (supportedDrillId(id)) return id
        return when (domain) {
            "transport" -> "transport-$id"
            "evs" -> "evs-$id"
            "rtdc", "capacity", "bed-management" -> when (o.optStringOrNull("type")) {
                "bed_request" -> "bedreq-$id"
                "barrier" -> "barrier-$id"
                else -> null
            }
            else -> null
        }
    }

    private fun supportedDrillId(id: String): Boolean =
        id.startsWith("bedreq-") ||
            id.startsWith("barrier-") ||
            id.startsWith("transport-") ||
            id.startsWith("evs-") ||
            Regex("^[0-9a-fA-F-]{36}$").matches(id)

    private fun summarizeContext(o: JSONObject?): List<DisplayField> {
        if (o == null) return emptyList()
        val fields = mutableListOf<DisplayField>()
        o.optJSONObject("event")?.let {
            fields += DisplayField("Event", humanize(it.optString("event_type", "event")))
            fields += DisplayField("Domain", humanize(it.optString("domain", "ops")))
        }
        o.optStringOrNull("patient_context_ref")?.let {
            fields += DisplayField("Patient context", it)
        }
        o.optJSONArray("dependencies")?.let {
            fields += DisplayField("Dependencies", "${it.length()}")
        }
        o.optJSONArray("activity")?.let {
            fields += DisplayField("Activity events", "${it.length()}")
        }
        if (fields.isEmpty()) fields += safeFields(o)
        return fields
    }

    private fun safeFields(
        o: JSONObject?,
        exclude: Set<String> = emptySet(),
    ): List<DisplayField> {
        if (o == null) return emptyList()
        val fields = mutableListOf<DisplayField>()
        val keys = o.keys()
        while (keys.hasNext()) {
            val key = keys.next()
            if (key in exclude) continue
            if (key.contains("patient_ref")) continue
            if (key == "encounter_ref") continue
            val value = o.opt(key)
            val text = displayValue(value)
            if (!text.isNullOrBlank()) fields += DisplayField(humanize(key), text)
        }
        return fields
    }

    private fun displayValue(value: Any?): String? = when (value) {
        null, JSONObject.NULL -> null
        is JSONObject -> {
            val count = value.length()
            if (count == 0) null else "$count field${if (count == 1) "" else "s"}"
        }
        is JSONArray -> "${value.length()} item${if (value.length() == 1) "" else "s"}"
        is Boolean -> if (value) "Yes" else "No"
        else -> value.toString()
    }

    private fun normalizeStatus(value: String?): String {
        return when (value?.lowercase()) {
            "critical", "critical_gap", "t1", "stat", "overdue", "failed", "blocked" -> "critical"
            "warning", "gap", "t2", "urgent", "high", "pending", "active", "boarding" -> "warning"
            "success", "complete", "completed", "placed", "resolved", "filled" -> "success"
            "info", "t3", "t4", "low", "normal" -> "info"
            else -> "info"
        }
    }

    private fun humanize(value: String): String =
        value.replace('.', ' ')
            .replace('_', ' ')
            .replace('-', ' ')
            .split(' ')
            .filter { it.isNotBlank() }
            .joinToString(" ") { part -> part.replaceFirstChar { if (it.isLowerCase()) it.titlecase() else it.toString() } }
}

private fun JSONObject.optStringOrNull(key: String): String? =
    if (isNull(key) || !has(key)) null else optString(key)

private fun JSONObject.optIntOrNull(key: String): Int? =
    if (isNull(key) || !has(key)) null else optInt(key)

private fun JSONObject.optDoubleOrNull(key: String): Double? =
    if (isNull(key) || !has(key)) null else optDouble(key)

private fun JSONObject.optIsolation(key: String): String? {
    if (isNull(key) || !has(key)) return null
    val raw = opt(key)
    return when (raw) {
        is Boolean -> if (raw) "isolation" else null
        JSONObject.NULL -> null
        else -> raw.toString().takeIf { it.isNotBlank() }
    }
}

private fun JSONArray?.objects(): List<JSONObject> {
    if (this == null) return emptyList()
    return List(length()) { i -> optJSONObject(i) }.filterNotNull()
}

private fun JSONArray?.strings(): List<String> {
    if (this == null) return emptyList()
    return List(length()) { i -> optString(i) }.filter { it.isNotBlank() }
}

private fun JSONArray?.doubles(): List<Double> {
    if (this == null) return emptyList()
    return List(length()) { i -> optDouble(i, 0.0) }
}
