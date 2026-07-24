package net.acumenus.hummingbird.data

import android.content.SharedPreferences
import android.os.Build
import net.acumenus.hummingbird.BuildConfig
import org.json.JSONObject
import java.util.UUID

class StaffDeviceIdentityUnavailableException :
    IllegalStateException("Hummingbird could not establish an identity for this app installation.")

/**
 * Reinstall-scoped metadata that lets a clinician recognize a signed-in device.
 *
 * The installation UUID is neither a credential nor an attestation claim. It is kept
 * in the same fail-closed encrypted preference store already required for staff auth,
 * persists across sign-out, and is removed by uninstall. The server never returns it.
 */
data class StaffAuthDevice(
    val installationUuid: String,
    val platform: String,
    val name: String?,
    val appVersion: String?,
    val osVersion: String?,
) {
    fun toJson(): JSONObject = JSONObject()
        .put("installation_uuid", installationUuid)
        .put("platform", platform)
        .apply {
            StaffDeviceIdentity.boundedMetadata(name, 120)?.let { put("name", it) }
            StaffDeviceIdentity.boundedMetadata(appVersion, 80)?.let {
                put("app_version", it)
            }
            StaffDeviceIdentity.boundedMetadata(osVersion, 80)?.let {
                put("os_version", it)
            }
        }
}

object StaffDeviceIdentity {
    private const val INSTALLATION_UUID_KEY = "staff_installation_uuid_v1"

    fun current(prefs: SharedPreferences): StaffAuthDevice {
        val installationUuid = stableInstallationUuid(prefs)
        return StaffAuthDevice(
            installationUuid = installationUuid,
            platform = "android",
            name = Build.MODEL?.takeIf(String::isNotBlank),
            appVersion = BuildConfig.VERSION_NAME.takeIf(String::isNotBlank),
            osVersion = Build.VERSION.RELEASE
                ?.takeIf(String::isNotBlank)
                ?.let { "Android $it" },
        )
    }

    internal fun stableInstallationUuid(
        prefs: SharedPreferences,
        generate: () -> UUID = UUID::randomUUID,
    ): String {
        prefs.getString(INSTALLATION_UUID_KEY, null)
            ?.let(::canonicalUuidOrNull)
            ?.let { return it }

        val generated = generate().toString().lowercase()
        val committed = prefs.edit()
            .putString(INSTALLATION_UUID_KEY, generated)
            .commit()
        val readBack = prefs.getString(INSTALLATION_UUID_KEY, null)
            ?.let(::canonicalUuidOrNull)

        if (!committed || readBack != generated) {
            throw StaffDeviceIdentityUnavailableException()
        }
        return generated
    }

    internal fun boundedMetadata(value: String?, maxCodePoints: Int): String? {
        if (maxCodePoints <= 0) return null
        val normalized = value?.trim()?.takeIf(String::isNotEmpty) ?: return null
        val count = normalized.codePointCount(0, normalized.length)
        if (count <= maxCodePoints) return normalized
        val end = normalized.offsetByCodePoints(0, maxCodePoints)
        return normalized.substring(0, end)
    }

    private fun canonicalUuidOrNull(value: String): String? = runCatching {
        UUID.fromString(value).toString().lowercase()
    }.getOrNull()?.takeIf { it == value }
}
