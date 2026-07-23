package net.acumenus.hummingbird.data

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch
import net.acumenus.hummingbird.widget.HouseGlanceStore
import org.json.JSONObject

enum class AuthPhase { LOADING, LOGGED_OUT, NEEDS_PASSWORD_CHANGE, LOGGED_IN }

/** One encrypted SharedPreferences value owns the complete rotating credential pair. */
class EncryptedStaffTokenStore(
    private val prefs: android.content.SharedPreferences,
) : StaffTokenStore {
    override fun load(): StaffTokenSession? {
        prefs.getString(SESSION_KEY, null)?.let { encoded ->
            runCatching {
                val json = JSONObject(encoded)
                StaffTokenSession(
                    accessToken = json.getString("access_token"),
                    refreshToken = json.getString("refresh_token"),
                    accessExpiresAtEpochMs = json.getLong("access_expires_at_epoch_ms"),
                )
            }.getOrNull()?.takeIf {
                it.accessToken.isNotBlank() && it.refreshToken.isNotBlank()
            }?.let { return it }
        }

        val access = prefs.getString("access", null)?.takeIf(String::isNotBlank)
        val refresh = prefs.getString("refresh", null)?.takeIf(String::isNotBlank)
        if (access == null || refresh == null) {
            clear()
            return null
        }

        val migrated = StaffTokenSession(
            accessToken = access,
            refreshToken = refresh,
            accessExpiresAtEpochMs = 0L,
        )
        return if (save(migrated)) migrated else null
    }

    override fun save(session: StaffTokenSession): Boolean {
        val encoded = JSONObject()
            .put("access_token", session.accessToken)
            .put("refresh_token", session.refreshToken)
            .put("access_expires_at_epoch_ms", session.accessExpiresAtEpochMs)
            .toString()
        return prefs.edit()
            .putString(SESSION_KEY, encoded)
            .remove("access")
            .remove("refresh")
            .commit()
    }

    override fun clear() {
        prefs.edit()
            .remove(SESSION_KEY)
            .remove("access")
            .remove("refresh")
            .commit()
    }

    private companion object {
        const val SESSION_KEY = "staff_token_session_v2"
    }
}

/**
 * Owns auth state + tokens. Token-based, honoring the backend's must_change_password
 * challenge. Full-session tokens persist only in Keystore-backed
 * EncryptedSharedPreferences. The short-lived password-change token stays in memory and is
 * discarded on process death, cancel, success, or logout.
 */
class AuthViewModel(app: Application) : AndroidViewModel(app) {
    private val prefsResult = runCatching { SecurePrefs.get(app) }
    private val prefs = prefsResult.getOrNull()
    private val tokenCoordinator = StaffTokenCoordinator.shared
    private val api = ApiClient(tokenCoordinator = tokenCoordinator)

    var phase by mutableStateOf(AuthPhase.LOADING); private set
    var me by mutableStateOf<MeData?>(null); private set
    var error by mutableStateOf<String?>(null); private set
    var busy by mutableStateOf(false); private set
    var accessToken: String? = null; private set
    private var changeToken: String? = null

    init {
        val securePrefs = prefs
        if (securePrefs == null) {
            tokenCoordinator.clear()
        } else {
            tokenCoordinator.configure(EncryptedStaffTokenStore(securePrefs))
        }
        tokenCoordinator.setSessionListener { session ->
            viewModelScope.launch {
                accessToken = session?.accessToken
            }
        }
    }

    fun bootstrap() {
        if (prefs == null) {
            error = SECURE_STORAGE_ERROR
            phase = AuthPhase.LOGGED_OUT
            return
        }
        val stored = tokenCoordinator.snapshot()
        if (stored == null) { phase = AuthPhase.LOGGED_OUT; return }
        accessToken = stored.accessToken
        viewModelScope.launch {
            try {
                me = api.me(stored.accessToken)
                accessToken = tokenCoordinator.snapshot()?.accessToken
                phase = AuthPhase.LOGGED_IN
            } catch (exception: ApiException) {
                if (exception.statusCode in setOf(401, 403)) {
                    clearTokens()
                } else {
                    // A transport/5xx/contract failure does not prove the protected
                    // refresh credential is invalid. Retain it for a later bootstrap,
                    // but expose no authenticated UI until /me succeeds.
                    accessToken = null
                    me = null
                    error = exception.message
                }
                phase = AuthPhase.LOGGED_OUT
            } catch (exception: Exception) {
                accessToken = null
                me = null
                error = exception.message ?: "Unable to verify this session."
                phase = AuthPhase.LOGGED_OUT
            }
        }
    }

    fun login(username: String, password: String) {
        val securePrefs = prefs
        if (securePrefs == null) {
            error = SECURE_STORAGE_ERROR
            phase = AuthPhase.LOGGED_OUT
            return
        }
        busy = true; error = null
        viewModelScope.launch {
            try {
                val r = api.token(username, password)
                if (r.passwordChangeRequired) {
                    val scopedToken = r.changeToken?.takeIf(String::isNotBlank)
                    if (scopedToken == null) {
                        error = "The password-change session was not issued. Please sign in again."
                    } else {
                        changeToken = scopedToken
                        phase = AuthPhase.NEEDS_PASSWORD_CHANGE
                    }
                    busy = false
                    return@launch
                }
                acceptSession(r)
            } catch (e: ApiException) {
                error = e.message
            } catch (e: Exception) {
                error = e.message ?: "Sign in failed."
            }
            busy = false
        }
    }

    fun changePassword(currentPassword: String, newPassword: String) {
        val scopedToken = changeToken
        val securePrefs = prefs
        if (scopedToken == null) {
            error = "Your password-change session expired. Please sign in again."
            phase = AuthPhase.LOGGED_OUT
            return
        }
        if (securePrefs == null) {
            error = SECURE_STORAGE_ERROR
            phase = AuthPhase.LOGGED_OUT
            return
        }

        busy = true; error = null
        viewModelScope.launch {
            try {
                val result = api.changePassword(currentPassword, newPassword, scopedToken)
                // A successful server response has consumed/revoked the scoped
                // challenge even if local secure persistence subsequently fails.
                changeToken = null
                if (!acceptSession(result)) {
                    phase = AuthPhase.LOGGED_OUT
                }
            } catch (e: ApiException) {
                error = e.message
            } catch (e: Exception) {
                error = e.message ?: "Password update failed."
            }
            busy = false
        }
    }

    fun cancelPasswordChange() {
        val scopedToken = changeToken
        changeToken = null
        error = null
        phase = AuthPhase.LOGGED_OUT
        if (scopedToken != null) {
            viewModelScope.launch { api.revoke(scopedToken) }
        }
    }

    fun logout() {
        val session = tokenCoordinator.snapshot()
        val scopedToken = changeToken
        viewModelScope.launch {
            if (session != null) {
                api.revoke(session.accessToken)
                api.revoke(session.refreshToken)
            } else if (scopedToken != null) {
                api.revoke(scopedToken)
            }
            // Reset the widget to its placeholder once this session's data is gone.
            runCatching { HouseGlanceStore.clear(getApplication<android.app.Application>()) }
        }
        clearTokens(); me = null; error = null; phase = AuthPhase.LOGGED_OUT
    }

    private fun clearTokens() {
        tokenCoordinator.clear()
        accessToken = null
        changeToken = null
        // Never let one user's cached flow window survive into another session.
        runCatching { FlowWindowCache(getApplication<android.app.Application>()).clearAll() }
    }

    private suspend fun acceptSession(result: TokenResult): Boolean {
        if (result.passwordChangeRequired) {
            error = "Unexpected response from the server."
            return false
        }

        val session = try {
            tokenCoordinator.install(result)
        } catch (exception: Exception) {
            result.accessToken?.let { api.revoke(it) }
            result.refreshToken?.let { api.revoke(it) }
            error = exception.message ?: SECURE_STORAGE_ERROR
            return false
        }

        accessToken = session.accessToken
        return try {
            me = api.me(session.accessToken)
            accessToken = tokenCoordinator.snapshot()?.accessToken
            phase = AuthPhase.LOGGED_IN
            true
        } catch (exception: Exception) {
            tokenCoordinator.snapshot()?.let {
                api.revoke(it.accessToken)
                api.revoke(it.refreshToken)
            }
            clearTokens()
            error = exception.message ?: "Unable to verify this session. Please sign in again."
            phase = AuthPhase.LOGGED_OUT
            false
        }
    }

    private companion object {
        const val SECURE_STORAGE_ERROR =
            "Secure credential storage is unavailable on this device. Sign-in has been disabled."
    }
}
