package net.acumenus.hummingbird.ui

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class PasswordChangePolicyTest {
    @Test
    fun `requires current password minimum length difference and confirmation`() {
        assertFalse(PasswordChangePolicy.evaluate("", "", "").isReady)
        assertFalse(PasswordChangePolicy.evaluate("Temporary1!", "Short1!", "Short1!").isReady)
        assertFalse(
            PasswordChangePolicy.evaluate("Temporary1!", "Temporary1!", "Temporary1!").isReady,
        )
        assertFalse(
            PasswordChangePolicy.evaluate("Temporary1!", "Replacement2!", "Mismatch2!").isReady,
        )
        assertTrue(
            PasswordChangePolicy.evaluate("Temporary1!", "Replacement2!", "Replacement2!").isReady,
        )
    }

    @Test
    fun `confirmation is exact and case sensitive`() {
        val validation = PasswordChangePolicy.evaluate(
            "Temporary1!",
            "Replacement2!",
            "replacement2!",
        )

        assertTrue(validation.hasCurrentPassword)
        assertTrue(validation.hasMinimumLength)
        assertTrue(validation.differsFromCurrent)
        assertFalse(validation.confirmationMatches)
        assertFalse(validation.isReady)
    }
}
