package net.acumenus.hummingbird.patient

import android.view.WindowManager
import org.junit.Assert.assertEquals
import org.junit.Test

class PatientPrivacyPolicyTest {
    @Test
    fun secureWindowFlagIsMandatory() {
        assertEquals(WindowManager.LayoutParams.FLAG_SECURE, PatientPrivacyPolicy.SECURE_WINDOW_FLAG)
    }
}
