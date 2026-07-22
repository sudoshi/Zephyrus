package net.acumenus.hummingbird

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class HummingbirdLaunchHooksTest {
    @Test
    fun `autologin is off unless explicitly requested with both credentials`() {
        assertNull(HummingbirdLaunchHooks.fromExtras(emptyMap<String, String>()::get).autologin)
        assertNull(
            HummingbirdLaunchHooks.fromExtras(
                mapOf("HB_AUTOLOGIN" to "1", "HB_USER" to "qa-user")::get,
            ).autologin,
        )
        assertNull(
            HummingbirdLaunchHooks.fromExtras(
                mapOf("HB_AUTOLOGIN" to "1", "HB_USER" to "", "HB_PASS" to "secret")::get,
            ).autologin,
        )
    }

    @Test
    fun `explicit debug credentials and navigation extras are parsed`() {
        val state = HummingbirdLaunchHooks.fromExtras(
            mapOf(
                "HB_AUTOLOGIN" to "1",
                "HB_USER" to "qa-user",
                "HB_PASS" to "test-only-secret",
                "HB_ROLE" to "bed_manager",
                "HB_TAB" to "foryou",
                "HB_OPEN_UNIT" to "42",
                "HB_OPEN_TARGET" to "patient:ptok_test",
                "HB_FORCE_ERROR" to "1",
                "HB_DEBUG_EXPLORER" to "1",
            )::get,
        )

        assertEquals("qa-user", state.autologin?.username)
        assertEquals("test-only-secret", state.autologin?.password)
        assertEquals("bed_manager", state.config.roleId)
        assertEquals("foryou", state.config.tab)
        assertEquals(42, state.config.openUnitId)
        assertEquals("patient:ptok_test", state.config.openTarget)
        assertTrue(state.config.forceError)
        assertTrue(state.config.debugExplorer)
    }

    @Test
    fun `invalid optional values stay inert`() {
        val state = HummingbirdLaunchHooks.fromExtras(
            mapOf("HB_OPEN_UNIT" to "not-a-number", "HB_FORCE_ERROR" to "0")::get,
        )

        assertNull(state.config.openUnitId)
        assertFalse(state.config.forceError)
    }
}
