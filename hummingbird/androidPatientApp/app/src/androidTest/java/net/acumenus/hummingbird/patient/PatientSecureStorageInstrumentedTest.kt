package net.acumenus.hummingbird.patient

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import net.acumenus.hummingbird.patient.data.EncryptedPatientCredentialStore
import net.acumenus.hummingbird.patient.data.PatientStoredCredentials
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TestName
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class PatientSecureStorageInstrumentedTest {
    @get:Rule
    val testName = TestName()

    @Test
    fun encryptedPatientCredentialsRoundTripAndClearWithoutRotatingDeviceIdentity() {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val store = EncryptedPatientCredentialStore(context)
        store.clear()

        val deviceUuid = store.getOrCreateDeviceUuid()
        val credentials = PatientStoredCredentials(
            accessToken = "instrumentation-access-${testName.methodName}",
            refreshToken = "instrumentation-refresh-${testName.methodName}",
            sessionUuid = "instrumentation-session-${testName.methodName}",
        )

        try {
            store.write(credentials)
            assertEquals(credentials, store.read())

            store.clear()
            assertNull(store.read())
            assertEquals(deviceUuid, store.getOrCreateDeviceUuid())
        } finally {
            store.clear()
        }
    }
}
