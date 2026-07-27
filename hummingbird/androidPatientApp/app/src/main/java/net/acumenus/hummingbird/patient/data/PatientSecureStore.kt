package net.acumenus.hummingbird.patient.data

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import java.util.UUID

/** Patient credentials never share a file, key alias, or migration with staff Hummingbird. */
object PatientStoragePolicy {
    internal const val PREFERENCES_FILE = "hummingbird_patient_secure_v1"
    internal const val MASTER_KEY_ALIAS = "hummingbird_patient_master_key_v1"
    internal const val ACCESS_TOKEN_KEY = "patient_access_token"
    internal const val REFRESH_TOKEN_KEY = "patient_refresh_token"

    val namespace: String
        get() = PREFERENCES_FILE
}

interface PatientCredentialStore {
    fun read(): PatientStoredCredentials?
    fun write(credentials: PatientStoredCredentials)
    fun clear()
    fun getOrCreateDeviceUuid(): String
}

data class PatientStoredCredentials(
    val accessToken: String,
    val refreshToken: String,
    val sessionUuid: String,
)

class EncryptedPatientCredentialStore(context: Context) : PatientCredentialStore {
    private val preferences: SharedPreferences = createEncryptedPreferences(context.applicationContext)

    override fun read(): PatientStoredCredentials? {
        val access = preferences.getString(PatientStoragePolicy.ACCESS_TOKEN_KEY, null) ?: return null
        val refresh = preferences.getString(PatientStoragePolicy.REFRESH_TOKEN_KEY, null) ?: return null
        val session = preferences.getString(SESSION_KEY, null) ?: return null
        return PatientStoredCredentials(access, refresh, session)
    }

    override fun write(credentials: PatientStoredCredentials) {
        val persisted = preferences.edit()
            .putString(PatientStoragePolicy.ACCESS_TOKEN_KEY, credentials.accessToken)
            .putString(PatientStoragePolicy.REFRESH_TOKEN_KEY, credentials.refreshToken)
            .putString(SESSION_KEY, credentials.sessionUuid)
            .commit()
        check(persisted) { "Patient credentials could not be persisted securely." }
    }

    override fun clear() {
        val cleared = preferences.edit()
            .remove(PatientStoragePolicy.ACCESS_TOKEN_KEY)
            .remove(PatientStoragePolicy.REFRESH_TOKEN_KEY)
            .remove(SESSION_KEY)
            .commit()
        check(cleared) { "Patient credentials could not be cleared securely." }
    }

    override fun getOrCreateDeviceUuid(): String {
        preferences.getString(DEVICE_UUID_KEY, null)?.let { return it }
        val generated = UUID.randomUUID().toString()
        val persisted = preferences.edit().putString(DEVICE_UUID_KEY, generated).commit()
        check(persisted) { "Patient device identity could not be persisted securely." }
        return generated
    }

    private fun createEncryptedPreferences(context: Context): SharedPreferences =
        requirePatientEncryptedStorage {
            val key = MasterKey.Builder(context, PatientStoragePolicy.MASTER_KEY_ALIAS)
                .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
                .build()
            EncryptedSharedPreferences.create(
                context,
                PatientStoragePolicy.PREFERENCES_FILE,
                key,
                EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
            )
        }

    private companion object {
        const val SESSION_KEY = "patient_session_uuid"
        const val DEVICE_UUID_KEY = "patient_device_uuid"
    }
}

class PatientSecureStorageUnavailableException(cause: Exception) :
    IllegalStateException("Protected patient credential storage is unavailable.", cause)

internal inline fun <T> requirePatientEncryptedStorage(create: () -> T): T = try {
    create()
} catch (error: Exception) {
    throw PatientSecureStorageUnavailableException(error)
}
