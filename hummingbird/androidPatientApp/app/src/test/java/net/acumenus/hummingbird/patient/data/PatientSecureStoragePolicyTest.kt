package net.acumenus.hummingbird.patient.data

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class PatientSecureStoragePolicyTest {
    @Test
    fun patientStorageUsesAnExplicitPatientOnlyNamespace() {
        assertTrue(PatientStoragePolicy.namespace.contains("patient"))
        assertFalse(PatientStoragePolicy.namespace.endsWith("_staff"))
        assertTrue(PatientStoragePolicy.MASTER_KEY_ALIAS.contains("patient"))
        assertTrue(PatientStoragePolicy.ACCESS_TOKEN_KEY.startsWith("patient_"))
        assertTrue(PatientStoragePolicy.REFRESH_TOKEN_KEY.startsWith("patient_"))
    }

    @Test
    fun encryptedStorageFailureNeverFallsBack() {
        val result = runCatching {
            requirePatientEncryptedStorage<Nothing> { throw IllegalStateException("unavailable") }
        }
        assertTrue(result.exceptionOrNull() is PatientSecureStorageUnavailableException)
    }
}
