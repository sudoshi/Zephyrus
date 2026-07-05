package net.acumenus.hummingbird.data

import android.app.Application
import android.content.Context
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
 * challenge. Tokens persist in Keystore-backed EncryptedSharedPreferences (see SecurePrefs;
 * legacy plain-prefs tokens migrate on first read) — parity with the iOS Keychain posture.
 */
class AuthViewModel(app: Application) : AndroidViewModel(app) {
    private val prefs = SecurePrefs.get(app)
    private val api = ApiClient()

    var phase by mutableStateOf(AuthPhase.LOADING); private set
    var me by mutableStateOf<MeData?>(null); private set
    var error by mutableStateOf<String?>(null); private set
    var busy by mutableStateOf(false); private set
    var accessToken: String? = null; private set

    fun bootstrap() {
        val stored = prefs.getString("access", null)
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
        busy = true; error = null
        viewModelScope.launch {
            try {
                val r = api.token(username, password)
                if (r.passwordChangeRequired) { phase = AuthPhase.NEEDS_PASSWORD_CHANGE; busy = false; return@launch }
                val access = r.accessToken
                if (access == null) { error = "Unexpected response from the server."; busy = false; return@launch }
                accessToken = access
                prefs.edit().putString("access", access).putString("refresh", r.refreshToken).apply()
                me = api.me(access)
                phase = AuthPhase.LOGGED_IN
            } catch (e: ApiException) {
                error = e.message
            } catch (e: Exception) {
                error = e.message ?: "Sign in failed."
            }
            busy = false
        }
    }

    fun logout() {
        val t = accessToken
        viewModelScope.launch {
            if (t != null) api.revoke(t)
            // Reset the widget to its placeholder once this session's data is gone.
            runCatching { HouseGlanceStore.clear(getApplication<android.app.Application>()) }
        }
        clearTokens(); me = null; error = null; phase = AuthPhase.LOGGED_OUT
    }

    private fun clearTokens() {
        accessToken = null
        prefs.edit().remove("access").remove("refresh").apply()
        // Never let one user's cached flow window survive into another session.
        runCatching { FlowWindowCache(getApplication<android.app.Application>()).clearAll() }
    }
}
