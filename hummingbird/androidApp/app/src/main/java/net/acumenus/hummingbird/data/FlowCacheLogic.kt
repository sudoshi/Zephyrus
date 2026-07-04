package net.acumenus.hummingbird.data

/** One offline-cache index record: which (user, persona, scope) window is on disk, and when. */
data class FlowCacheEntry(
    val key: String,
    val userId: Int,
    val persona: String,
    val scope: String?,
    val updatedAtMs: Long,
)

/**
 * Pure LRU + keying rules for the offline window cache (no Android deps → unit-testable).
 * The key embeds the authenticated user id so one user's cached window is never located
 * for another; [find] additionally re-checks the user id as a belt-and-braces guard.
 */
object FlowCacheLogic {
    const val MAX_ENTRIES = 20

    fun cacheKey(userId: Int, persona: String, scope: String?): String =
        "u$userId|$persona|${scope.orEmpty()}"

    /**
     * Upsert [entry] into the index. A same-key record is replaced (refreshed to the new
     * timestamp); when the index would exceed [maxEntries] the oldest by [FlowCacheEntry.updatedAtMs]
     * are dropped. Returns (kept newest-first, evicted) so the I/O layer can delete evicted files.
     */
    fun upsert(
        entries: List<FlowCacheEntry>,
        entry: FlowCacheEntry,
        maxEntries: Int = MAX_ENTRIES,
    ): Pair<List<FlowCacheEntry>, List<FlowCacheEntry>> {
        val combined = (entries.filterNot { it.key == entry.key } + entry)
            .sortedByDescending { it.updatedAtMs }
        if (combined.size <= maxEntries) return combined to emptyList()
        return combined.take(maxEntries) to combined.drop(maxEntries)
    }

    /** Locate the cached entry for this exact user+persona+scope (never cross-user). */
    fun find(entries: List<FlowCacheEntry>, userId: Int, persona: String, scope: String?): FlowCacheEntry? {
        val key = cacheKey(userId, persona, scope)
        return entries.firstOrNull { it.key == key && it.userId == userId }
    }
}
