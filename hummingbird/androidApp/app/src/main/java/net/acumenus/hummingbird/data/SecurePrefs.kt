package net.acumenus.hummingbird.data

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/**
 * Keystore-backed storage for auth tokens (parity with iOS Keychain
 * kSecAttrAccessibleWhenUnlockedThisDeviceOnly). Falls back to plain prefs only if the
 * Keystore is unusable (corrupt keyset after a backup-restore, etc.) so sign-in never
 * hard-fails; tokens are short-lived and revocable either way.
 */
object SecurePrefs {
    private const val SECURE_FILE = "hb_secure"
    private const val LEGACY_FILE = "hb"

    @Volatile private var cached: SharedPreferences? = null

    fun get(context: Context): SharedPreferences {
        cached?.let { return it }
        synchronized(this) {
            cached?.let { return it }
            val prefs = create(context.applicationContext)
            migrateLegacyTokens(context.applicationContext, prefs)
            cached = prefs
            return prefs
        }
    }

    private fun create(context: Context): SharedPreferences = try {
        val key = MasterKey.Builder(context)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()
        EncryptedSharedPreferences.create(
            context,
            SECURE_FILE,
            key,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    } catch (_: Exception) {
        context.getSharedPreferences(SECURE_FILE, Context.MODE_PRIVATE)
    }

    /** One-time move of v1 plain-prefs tokens into encrypted storage. */
    private fun migrateLegacyTokens(context: Context, secure: SharedPreferences) {
        val legacy = context.getSharedPreferences(LEGACY_FILE, Context.MODE_PRIVATE)
        val access = legacy.getString("access", null) ?: return
        secure.edit()
            .putString("access", access)
            .putString("refresh", legacy.getString("refresh", null))
            .apply()
        legacy.edit().remove("access").remove("refresh").apply()
    }
}
