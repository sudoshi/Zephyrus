package net.acumenus.hummingbird.data

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.nio.file.Files
import java.nio.file.StandardCopyOption

/**
 * Offline cache of the last FULL (non-delta) flow window + floors payloads, keyed by
 * (user id, persona, scope), living under context.filesDir/flow-cache. Atomic writes
 * (temp file + rename), ~20-entry LRU. Window entries carry ptoks so they are user-keyed
 * and never served across users; the floors payload is facility geometry (PHI-free) and is
 * shared. The whole directory is cleared on the logout path.
 */
class FlowWindowCache(context: Context) {
    private val dir = File(context.filesDir, DIR_NAME)
    private val indexFile = File(dir, "index.json")
    private val floorsFile = File(dir, "floors.json")

    /** A recovered window: the raw envelopes to re-parse plus when it was cached. */
    data class Cached(val windowRaw: String, val floorsRaw: String?, val updatedAtMs: Long)

    @Synchronized
    fun putWindow(userId: Int, persona: String, scope: String?, windowRaw: String, floorsRaw: String?) {
        dir.mkdirs()
        val now = System.currentTimeMillis()
        val key = FlowCacheLogic.cacheKey(userId, persona, scope)
        atomicWrite(
            File(dir, fileNameFor(key)),
            JSONObject()
                .put("key", key)
                .put("user_id", userId)
                .put("persona", persona)
                .put("scope", scope ?: JSONObject.NULL)
                .put("updated_at_ms", now)
                .put("window", windowRaw)
                .toString(),
        )
        floorsRaw?.let {
            atomicWrite(floorsFile, JSONObject().put("updated_at_ms", now).put("floors", it).toString())
        }

        val (kept, evicted) = FlowCacheLogic.upsert(readIndex(), FlowCacheEntry(key, userId, persona, scope, now))
        evicted.forEach { File(dir, fileNameFor(it.key)).delete() }
        writeIndex(kept)
    }

    @Synchronized
    fun readWindow(userId: Int, persona: String, scope: String?): Cached? {
        val entry = FlowCacheLogic.find(readIndex(), userId, persona, scope) ?: return null
        val obj = runCatching { JSONObject(File(dir, fileNameFor(entry.key)).readText()) }.getOrNull() ?: return null
        // Re-check identity to defend against a filename-hash collision serving a wrong record.
        if (obj.optString("key") != entry.key || obj.optInt("user_id", -1) != userId) return null
        val windowRaw = obj.optString("window").takeIf { it.isNotBlank() } ?: return null
        val floorsRaw = runCatching { JSONObject(floorsFile.readText()).optString("floors") }
            .getOrNull()?.takeIf { it.isNotBlank() }
        return Cached(windowRaw, floorsRaw, obj.optLong("updated_at_ms", entry.updatedAtMs))
    }

    @Synchronized
    fun clearAll() {
        dir.deleteRecursively()
    }

    private fun readIndex(): List<FlowCacheEntry> {
        val arr = runCatching { JSONArray(indexFile.readText()) }.getOrNull() ?: return emptyList()
        return (0 until arr.length()).mapNotNull { i ->
            val o = arr.optJSONObject(i) ?: return@mapNotNull null
            val key = o.optString("key").takeIf { it.isNotBlank() } ?: return@mapNotNull null
            FlowCacheEntry(
                key = key,
                userId = o.optInt("user_id"),
                persona = o.optString("persona"),
                scope = if (o.isNull("scope")) null else o.optString("scope").takeIf { it.isNotBlank() },
                updatedAtMs = o.optLong("updated_at_ms"),
            )
        }
    }

    private fun writeIndex(entries: List<FlowCacheEntry>) {
        val arr = JSONArray()
        entries.forEach { e ->
            arr.put(
                JSONObject()
                    .put("key", e.key)
                    .put("user_id", e.userId)
                    .put("persona", e.persona)
                    .put("scope", e.scope ?: JSONObject.NULL)
                    .put("updated_at_ms", e.updatedAtMs),
            )
        }
        atomicWrite(indexFile, arr.toString())
    }

    private fun fileNameFor(key: String): String = "win-${Integer.toHexString(key.hashCode())}.json"

    private fun atomicWrite(target: File, text: String) {
        val tmp = File(target.parentFile, "${target.name}.tmp")
        tmp.writeText(text)
        runCatching { Files.move(tmp.toPath(), target.toPath(), StandardCopyOption.ATOMIC_MOVE) }
            .onFailure {
                Files.move(tmp.toPath(), target.toPath(), StandardCopyOption.REPLACE_EXISTING)
            }
    }

    companion object {
        const val DIR_NAME = "flow-cache"
    }
}
