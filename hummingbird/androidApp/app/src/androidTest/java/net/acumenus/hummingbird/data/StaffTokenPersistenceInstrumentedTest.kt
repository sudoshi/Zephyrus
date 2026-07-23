package net.acumenus.hummingbird.data

import androidx.test.core.app.ApplicationProvider
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * Runs against Android Keystore-backed EncryptedSharedPreferences on an emulator.
 * The complete rotating pair must survive as one encrypted preference generation;
 * legacy split keys are migrated without ever falling back to ordinary preferences.
 */
class StaffTokenPersistenceInstrumentedTest {
    private lateinit var store: EncryptedStaffTokenStore
    private lateinit var prefs: android.content.SharedPreferences

    @Before
    fun setUp() {
        prefs = SecurePrefs.get(ApplicationProvider.getApplicationContext())
        store = EncryptedStaffTokenStore(prefs)
        store.clear()
    }

    @After
    fun tearDown() {
        store.clear()
    }

    @Test
    fun completeTokenGenerationRoundTripsAsOneProtectedValue() {
        val expected = StaffTokenSession(
            accessToken = "instrumented-access",
            refreshToken = "instrumented-refresh",
            accessExpiresAtEpochMs = 1_800_000L,
        )

        assertTrue(store.save(expected))
        assertEquals(expected, store.load())
        assertEquals(setOf("staff_token_session_v2"), prefs.all.keys)
        assertFalse(prefs.contains("access"))
        assertFalse(prefs.contains("refresh"))
    }

    @Test
    fun legacySplitPairMigratesAtomicallyAndIncompleteMaterialIsErased() {
        assertTrue(
            prefs.edit()
                .putString("access", "legacy-access")
                .putString("refresh", "legacy-refresh")
                .commit(),
        )

        val migrated = store.load()

        assertNotNull(migrated)
        assertEquals("legacy-access", migrated?.accessToken)
        assertEquals("legacy-refresh", migrated?.refreshToken)
        assertEquals(0L, migrated?.accessExpiresAtEpochMs)
        assertEquals(setOf("staff_token_session_v2"), prefs.all.keys)

        store.clear()
        assertTrue(prefs.edit().putString("access", "orphaned-access").commit())
        assertNull(store.load())
        assertTrue(prefs.all.isEmpty())
    }
}
