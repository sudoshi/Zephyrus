package net.acumenus.hummingbird.data

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class ApiClientAuthParsingTest {
    private val api = ApiClient("https://zephyrus.example.test")

    @Test
    fun `forced password response retains only scoped change token`() {
        val result = api.parseTokenResult(
            JSONObject(
                """
                {
                  "password_change_required": true,
                  "change_token": "scoped-change-token"
                }
                """.trimIndent(),
            ),
        )

        assertTrue(result.passwordChangeRequired)
        assertEquals("scoped-change-token", result.changeToken)
        assertNull(result.accessToken)
        assertNull(result.refreshToken)
        assertNull(result.expiresIn)
        assertTrue(result.abilities.isEmpty())
    }

    @Test
    fun `full token pair cannot be mistaken for password challenge`() {
        val result = api.parseTokenResult(
            JSONObject(
                """
                {
                  "access_token": "access",
                  "refresh_token": "refresh",
                  "expires_in": 1800,
                  "abilities": ["mobile:read", "mobile:act"],
                  "password_change_required": false
                }
                """.trimIndent(),
            ),
        )

        assertFalse(result.passwordChangeRequired)
        assertNull(result.changeToken)
        assertEquals("access", result.accessToken)
        assertEquals("refresh", result.refreshToken)
        assertEquals(1800, result.expiresIn)
        assertEquals(listOf("mobile:read", "mobile:act"), result.abilities)
    }
}
