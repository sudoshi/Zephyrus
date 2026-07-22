package net.acumenus.hummingbird.data

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch
import net.acumenus.hummingbird.widget.HouseGlanceStore

enum class AuthPhase { LOADING, LOGGED_OUT, NEEDS_PASSWORD_CHANGE, LOGGED_IN }

/**
 * Owns auth state + tokens. Token-based, honoring the backend's must_change_password
 * challenge. Full-session tokens persist only in Keystore-backed
 * EncryptedSharedPreferences. The short-lived password-change token stays in memory and is
 * discarded on process death, cancel, success, or logout.
 */
class AuthViewModel(app: Application) : AndroidViewModel(app) {
    private val prefsResult = runCatching { SecurePrefs.get(app) }
    private val prefs = prefsResult.getOrNull()
    private val api = ApiClient()

    var phase by mutableStateOf(AuthPhase.LOADING); private set
    var me by mutableStateOf<MeData?>(null); private set
    var error by mutableStateOf<String?>(null); private set
    var busy by mutableStateOf(false); private set
    var accessToken: String? = null; private set
    private var changeToken: String? = null

    fun bootstrap() {
        val securePrefs = prefs
        if (securePrefs == null) {
            error = SECURE_STORAGE_ERROR
            phase = AuthPhase.LOGGED_OUT
            return
        }
        val stored = securePrefs.getString("access", null)
        if (stored == null) { phase = AuthPhase.LOGGED_OUT; return }
        accessToken = stored
        viewModelScope.launch {
            try {
                me = api.me(stored); phase = AuthPhase.LOGGED_IN
            } catch (e: Exception) {
                clearTokens(); phase = AuthPhase.LOGGED_OUT
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
                acceptSession(r, securePrefs)
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
                if (!acceptSession(result, securePrefs)) {
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
        val t = accessToken ?: changeToken
        viewModelScope.launch {
            if (t != null) api.revoke(t)
            // Reset the widget to its placeholder once this session's data is gone.
            runCatching { HouseGlanceStore.clear(getApplication<android.app.Application>()) }
        }
        clearTokens(); me = null; error = null; phase = AuthPhase.LOGGED_OUT
    }

    private fun clearTokens() {
        accessToken = null
        changeToken = null
        prefs?.edit()?.remove("access")?.remove("refresh")?.apply()
        // Never let one user's cached flow window survive into another session.
        runCatching { FlowWindowCache(getApplication<android.app.Application>()).clearAll() }
    }

    private suspend fun acceptSession(result: TokenResult, securePrefs: android.content.SharedPreferences): Boolean {
        val access = result.accessToken?.takeIf(String::isNotBlank)
        val refresh = result.refreshToken?.takeIf(String::isNotBlank)
        if (access == null || refresh == null || result.passwordChangeRequired) {
            error = "Unexpected response from the server."
            return false
        }

        if (!securePrefs.edit().putString("access", access).putString("refresh", refresh).commit()) {
            api.revoke(access)
            error = SECURE_STORAGE_ERROR
            return false
        }

        accessToken = access
        return try {
            me = api.me(access)
            phase = AuthPhase.LOGGED_IN
            true
        } catch (exception: Exception) {
            api.revoke(access)
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
