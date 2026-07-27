package net.acumenus.hummingbird.patient

import android.view.Window
import android.view.WindowManager

object PatientPrivacyPolicy {
    const val SECURE_WINDOW_FLAG: Int = WindowManager.LayoutParams.FLAG_SECURE

    fun protect(window: Window) {
        window.addFlags(SECURE_WINDOW_FLAG)
    }
}
