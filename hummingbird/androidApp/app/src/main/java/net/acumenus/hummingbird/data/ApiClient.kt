package net.acumenus.hummingbird.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.BufferedReader
import java.net.HttpURLConnection
import java.net.URL

/**
 * Thin coroutine API client for the Hummingbird BFF. The Android emulator reaches the Mac
 * host via 10.0.2.2, so the Dockerized `php artisan serve` on :8001 is at 10.0.2.2:8001.
 * This is the seam the KMP shared `data` module (Ktor) will replace later.
 */
class ApiClient(private val baseUrl: String = BASE_URL) {

    companion object {
        // The Android emulator reaches the Mac host loopback via 10.0.2.2.
        const val BASE_URL = "http://10.0.2.2:8001"
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
                safeCapacity = o.optInt("safe_capacity"),
                bedNeed = o.optInt("bed_need"),
                status = o.optString("status", "info"),
            )
        }
        val meta = root.optJSONObject("meta")
        val web = root.optJSONObject("links")?.optStringOrNull("web")
        CensusResult(units, meta?.optStringOrNull("as_of"), meta?.optBoolean("stale", false) ?: false, web)
    }

    suspend fun revoke(bearer: String) = withContext(Dispatchers.IO) {
        runCatching { send("POST", "/api/auth/token/revoke", "{}", bearer) }
        Unit
    }

    // MARK: plumbing

    private fun getData(path: String, bearer: String): JSONObject {
        val (code, text) = send("GET", path, null, bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        return JSONObject(text).getJSONObject("data")
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
}

private fun JSONObject.optStringOrNull(key: String): String? =
    if (isNull(key) || !has(key)) null else optString(key)
