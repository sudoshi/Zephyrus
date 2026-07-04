package net.acumenus.hummingbird.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Pure offline-cache keying + LRU rules: user/persona/scope keys, newest-wins eviction, and
 * the cross-user isolation guarantee (one user's cache is never located for another).
 */
class FlowCacheLogicTest {

    private fun entry(userId: Int, persona: String, scope: String?, at: Long) =
        FlowCacheEntry(FlowCacheLogic.cacheKey(userId, persona, scope), userId, persona, scope, at)

    @Test
    fun keyEmbedsUserPersonaAndScope() {
        assertEquals("u7|bed_manager|house", FlowCacheLogic.cacheKey(7, "bed_manager", "house"))
        assertEquals("u7|bed_manager|", FlowCacheLogic.cacheKey(7, "bed_manager", null))
        // Different users ⇒ different keys, even for the same persona/scope.
        assertTrue(FlowCacheLogic.cacheKey(1, "evs", "floor:3") != FlowCacheLogic.cacheKey(2, "evs", "floor:3"))
    }

    @Test
    fun upsertEvictsOldestBeyondCapacity() {
        var entries = emptyList<FlowCacheEntry>()
        var evictedAll = emptyList<FlowCacheEntry>()
        listOf(
            entry(1, "a", null, 1L),
            entry(1, "b", null, 2L),
            entry(1, "c", null, 3L),
            entry(1, "d", null, 4L),
        ).forEach {
            val (kept, evicted) = FlowCacheLogic.upsert(entries, it, maxEntries = 3)
            entries = kept
            evictedAll = evictedAll + evicted
        }

        assertEquals(3, entries.size)
        // Oldest (persona "a", ts 1) is evicted; the newest three survive.
        assertEquals(listOf("a"), evictedAll.map { it.persona })
        assertEquals(setOf("b", "c", "d"), entries.map { it.persona }.toSet())
    }

    @Test
    fun upsertRefreshesSameKeyInPlace() {
        val (afterFirst, _) = FlowCacheLogic.upsert(emptyList(), entry(1, "bed_manager", "house", 1L), maxEntries = 3)
        val (afterRefresh, evicted) = FlowCacheLogic.upsert(afterFirst, entry(1, "bed_manager", "house", 9L), maxEntries = 3)

        assertEquals(1, afterRefresh.size)
        assertEquals(9L, afterRefresh.first().updatedAtMs)
        assertTrue(evicted.isEmpty())
    }

    @Test
    fun findNeverServesAnotherUsersCache() {
        val entries = listOf(
            entry(1, "bed_manager", "house", 1L),
            entry(2, "bed_manager", "house", 2L),
        )

        val forUser1 = FlowCacheLogic.find(entries, 1, "bed_manager", "house")
        assertEquals(1, forUser1?.userId)

        // User 3 has nothing cached — must return null, not user 1's or user 2's record.
        assertNull(FlowCacheLogic.find(entries, 3, "bed_manager", "house"))
    }

    @Test
    fun findMatchesScopeExactly() {
        val entries = listOf(entry(1, "evs", "floor:3", 1L))
        assertEquals("floor:3", FlowCacheLogic.find(entries, 1, "evs", "floor:3")?.scope)
        assertNull(FlowCacheLogic.find(entries, 1, "evs", null))
        assertNull(FlowCacheLogic.find(entries, 1, "evs", "floor:4"))
    }
}
