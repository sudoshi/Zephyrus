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
            workflowPreference = data.optStringOrNull("workflow_preference"),
            isAdmin = data.optBoolean("is_admin", false),
        )
    }

    suspend fun census(bearer: String): CensusResult = withContext(Dispatchers.IO) {
        val (code, text) = send("GET", "/api/mobile/v1/rtdc/census", null, bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        val root = JSONObject(text)
        val arr = root.getJSONArray("data")
        val units = List(arr.length()) { i ->
            val o = arr.getJSONObject(i)
            CensusUnit(
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
        }
        val meta = root.optJSONObject("meta")
        val web = root.optJSONObject("links")?.optStringOrNull("web")
        CensusResult(units, meta?.optStringOrNull("as_of"), meta?.optBoolean("stale", false) ?: false, web)
    }

    suspend fun forYou(bearer: String): List<ForYouItem> = withContext(Dispatchers.IO) {
        val (code, text) = send("GET", "/api/mobile/v1/for-you", null, bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        val arr = JSONObject(text).getJSONArray("data")
        arr.objects().map(::parseForYouItem)
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

    suspend fun revoke(bearer: String) = withContext(Dispatchers.IO) {
        runCatching { send("POST", "/api/auth/token/revoke", "{}", bearer) }
        Unit
    }

    // MARK: plumbing

    private fun getEnvelope(path: String, bearer: String): JSONObject {
        val (code, text) = send("GET", path, null, bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        return JSONObject(text)
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

    private fun errorMessage(text: String, code: Int): String {
        runCatching {
            val o = JSONObject(text)
            o.optJSONObject("error")?.optStringOrNull("message")?.let { return it }
            o.optStringOrNull("message")?.let { if (it.isNotEmpty()) return it }
        }
        if (code == 401) return "Your session has expired. Please sign in again."
        return "Request failed (HTTP $code)."
    }

    private fun withPersona(path: String, persona: String): String =
        path + if (path.contains("?")) "&persona=${urlPart(persona)}" else "?persona=${urlPart(persona)}"

    private fun urlPart(value: String): String = URLEncoder.encode(value, "UTF-8")

    private fun parseAltitudeHome(data: JSONObject): AltitudeHome = AltitudeHome(
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

    private fun parsePatientContext(data: JSONObject): PatientOperationalContext {
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

    private fun parseForYouItem(o: JSONObject): ForYouItem {
        val status = normalizeStatus(
            o.optStringOrNull("tier")
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

    private fun parseWorkspaceItem(domain: String, o: JSONObject): AltitudeWorkspaceItem {
        val id = o.optStringOrNull("id") ?: o.optStringOrNull("approval_uuid") ?: o.toString().hashCode().toString()
        val status = normalizeStatus(
            o.optStringOrNull("tier")
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
                exclude = setOf("id", "title", "subtitle", "patient_context_ref", "patient_ref"),
            ),
        )
    }

    private fun parseActivityEvent(o: JSONObject): ActivityEvent {
        val status = o.optJSONObject("status")
        val statusValue = normalizeStatus(
            status?.optStringOrNull("value")
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
            "critical", "t1", "stat", "overdue", "failed", "blocked" -> "critical"
            "warning", "t2", "urgent", "high", "pending", "active", "boarding" -> "warning"
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

private fun JSONArray?.objects(): List<JSONObject> {
    if (this == null) return emptyList()
    return List(length()) { i -> optJSONObject(i) }.filterNotNull()
}

private fun JSONArray?.strings(): List<String> {
    if (this == null) return emptyList()
    return List(length()) { i -> optString(i) }.filter { it.isNotBlank() }
}
