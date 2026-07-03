package net.acumenus.hummingbird.ui.components

import android.os.Build
import android.view.HapticFeedbackConstants
import android.view.View

// State changes deserve a pulse: success-tier feedback on primary operational actions
// (claim, advance, complete, place, approve), reject-tier on destructive decisions.
// View-based constants respect the system's touch-feedback setting automatically.

fun View.hbConfirmHaptic() {
    performHapticFeedback(
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) HapticFeedbackConstants.CONFIRM
        else HapticFeedbackConstants.KEYBOARD_TAP,
    )
}

fun View.hbRejectHaptic() {
    performHapticFeedback(
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) HapticFeedbackConstants.REJECT
        else HapticFeedbackConstants.LONG_PRESS,
    )
}
