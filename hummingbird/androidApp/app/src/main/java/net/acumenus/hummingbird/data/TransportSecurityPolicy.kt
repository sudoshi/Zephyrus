package net.acumenus.hummingbird.data

import net.acumenus.hummingbird.BuildConfig
import java.net.URI

enum class StaffTransportEnvironment {
    DEVELOPMENT,
    PRODUCTION;

    companion object {
        fun fromBuild(): StaffTransportEnvironment =
            if (BuildConfig.DEBUG) DEVELOPMENT else PRODUCTION
    }
}

/**
 * Fail-closed transport boundary for the staff product.
 *
 * Release builds have one HTTPS/WSS origin. Development builds may use a
 * system-trusted HTTPS origin or a tightly bounded loopback/emulator cleartext
 * origin. TLS trust remains owned by Android Network Security Configuration and
 * the platform trust store; this policy never installs a custom TrustManager.
 */
object StaffTransportSecurityPolicy {
    const val PRODUCTION_HOST = "zephyrus.acumenus.net"

    private val developmentCleartextHosts = setOf(
        "localhost",
        "127.0.0.1",
        "10.0.2.2",
    )

    fun permitsHttpBaseUrl(
        rawUrl: String,
        environment: StaffTransportEnvironment,
    ): Boolean {
        val uri = runCatching { URI(rawUrl) }.getOrNull() ?: return false
        val scheme = uri.scheme?.lowercase() ?: return false
        val host = uri.host?.lowercase() ?: return false
        if (
            uri.rawUserInfo != null ||
            uri.rawQuery != null ||
            uri.rawFragment != null ||
            (uri.port != -1 && uri.port !in 1..65_535) ||
            !(uri.rawPath.isNullOrEmpty() || uri.rawPath == "/")
        ) {
            return false
        }

        return when (environment) {
            StaffTransportEnvironment.PRODUCTION ->
                scheme == "https" &&
                    host == PRODUCTION_HOST &&
                    (uri.port == -1 || uri.port == 443)
            StaffTransportEnvironment.DEVELOPMENT ->
                scheme == "https" ||
                    (scheme == "http" && host in developmentCleartextHosts)
        }
    }

    fun permitsWebSocket(
        scheme: String,
        host: String,
        port: Int,
        environment: StaffTransportEnvironment,
    ): Boolean {
        if (port !in 1..65_535) return false

        val normalizedScheme = scheme.lowercase()
        val normalizedHost = host.lowercase()

        return when (environment) {
            StaffTransportEnvironment.PRODUCTION ->
                normalizedScheme == "wss" &&
                    normalizedHost == PRODUCTION_HOST &&
                    port == 443
            StaffTransportEnvironment.DEVELOPMENT ->
                (normalizedScheme == "wss" && port == 443) ||
                    (normalizedScheme == "ws" && normalizedHost in developmentCleartextHosts)
        }
    }

    fun requireBuildConfiguration() {
        val environment = StaffTransportEnvironment.fromBuild()
        require(permitsHttpBaseUrl(BuildConfig.ZEPHYRUS_BASE_URL, environment)) {
            "Hummingbird staff API transport configuration is unsafe."
        }
        require(
            permitsWebSocket(
                scheme = BuildConfig.ZEPHYRUS_REVERB_SCHEME,
                host = BuildConfig.ZEPHYRUS_REVERB_HOST,
                port = BuildConfig.ZEPHYRUS_REVERB_PORT,
                environment = environment,
            ),
        ) {
            "Hummingbird staff realtime transport configuration is unsafe."
        }
    }
}
