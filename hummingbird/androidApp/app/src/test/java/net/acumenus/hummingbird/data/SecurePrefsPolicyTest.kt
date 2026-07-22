package net.acumenus.hummingbird.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertSame
import org.junit.Assert.fail
import org.junit.Test

class SecurePrefsPolicyTest {
    @Test
    fun `secure value is returned when creation succeeds`() {
        assertEquals("encrypted", requireEncryptedStorage { "encrypted" })
    }

    @Test
    fun `secure creation failure is wrapped and never replaced by a fallback`() {
        val cause = IllegalArgumentException("keystore unavailable")

        try {
            requireEncryptedStorage<String> { throw cause }
            fail("Expected secure storage failure")
        } catch (error: SecureStorageUnavailableException) {
            assertSame(cause, error.cause)
        }
    }
}
