package net.acumenus.hummingbird.data

import android.content.Context
import android.content.SharedPreferences
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity

/**
 * Opt-in biometric app lock, mirroring iOS AppLock semantics: OFF by default (demos are
 * not gated), engages when the app leaves the foreground, unlocks via biometric or the
 * device credential, and always offers a sign-out escape hatch. The flag itself is not a
 * secret, so it lives in plain prefs.
 */
object AppLock {
    private const val FLAG = "app_lock_enabled"
    private var prefs: SharedPreferences? = null

    var enabled by mutableStateOf(false); private set
    var locked by mutableStateOf(false); private set

    private val authenticators =
        BiometricManager.Authenticators.BIOMETRIC_WEAK or BiometricManager.Authenticators.DEVICE_CREDENTIAL

    fun init(context: Context) {
        if (prefs == null) {
            prefs = context.applicationContext.getSharedPreferences("hb", Context.MODE_PRIVATE)
            enabled = prefs?.getBoolean(FLAG, false) ?: false
        }
    }

    fun setLockEnabled(on: Boolean) {
        enabled = on
        prefs?.edit()?.putBoolean(FLAG, on)?.apply()
        if (!on) locked = false
    }

    /** Called when the app leaves the foreground. */
    fun engage() {
        if (enabled) locked = true
    }

    fun unlock() {
        locked = false
    }

    fun isAvailable(context: Context): Boolean =
        BiometricManager.from(context).canAuthenticate(authenticators) == BiometricManager.BIOMETRIC_SUCCESS

    fun prompt(activity: FragmentActivity, onResult: (Boolean) -> Unit) {
        val callback = object : BiometricPrompt.AuthenticationCallback() {
            override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                unlock()
                onResult(true)
            }

            override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
                onResult(false)
            }
        }
        val info = BiometricPrompt.PromptInfo.Builder()
            .setTitle("Unlock Hummingbird")
            .setSubtitle("Operational data stays private on shared devices")
            .setAllowedAuthenticators(authenticators)
            .build()
        BiometricPrompt(activity, ContextCompat.getMainExecutor(activity), callback).authenticate(info)
    }
}
