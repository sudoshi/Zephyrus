package net.acumenus.hummingbird.ui.theme

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Error
import androidx.compose.material.icons.filled.RemoveCircle
import androidx.compose.material.icons.filled.Warning
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector

/** The rationed status vocabulary, shared with the BFF (success|warning|critical|info). */
enum class CapacityStatus {
    SUCCESS, WARNING, CRITICAL, INFO;

    val color: Color
        get() = when (this) {
            SUCCESS -> Z.statusSuccess
            WARNING -> Z.statusWarning
            CRITICAL -> Z.statusCritical
            INFO -> Z.statusInfo
        }

    /** A short label so status is never communicated by color alone. */
    val label: String
        get() = when (this) {
            CRITICAL -> "At capacity"
            WARNING -> "Near capacity"
            SUCCESS -> "Within capacity"
            INFO -> "No data"
        }

    val icon: ImageVector
        get() = when (this) {
            CRITICAL -> Icons.Filled.Error
            WARNING -> Icons.Filled.Warning
            SUCCESS -> Icons.Filled.CheckCircle
            INFO -> Icons.Filled.RemoveCircle
        }

    val severity: Int
        get() = when (this) {
            INFO -> 0; SUCCESS -> 1; WARNING -> 2; CRITICAL -> 3
        }

    companion object {
        fun from(apiValue: String): CapacityStatus = when (apiValue) {
            "success" -> SUCCESS
            "warning" -> WARNING
            "critical" -> CRITICAL
            else -> INFO
        }
    }
}
