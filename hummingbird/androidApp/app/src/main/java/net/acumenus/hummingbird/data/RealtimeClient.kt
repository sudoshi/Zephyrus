package net.acumenus.hummingbird.data

import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import org.json.JSONObject

/**
 * Minimal Pusher-protocol client over an OkHttp WebSocket, talking to Laravel Reverb.
 * Subscribes to a public (PHI-free) channel and invokes [onEvent] on a data event so the
 * caller can re-snapshot (Reverb does not replay). Auto-reconnects with a small backoff.
 * Foreground real-time tier — the app still polls as a fallback.
 */
class RealtimeClient(
    private val scheme: String,
    private val host: String,
    private val port: Int,
    private val key: String,
    private val channel: String,
    private val onEvent: () -> Unit,
    private val onState: (Boolean) -> Unit,
) {
    private val client = OkHttpClient()
    private var ws: WebSocket? = null
    @Volatile private var running = false

    fun start() {
        if (running) return
        running = true
        connect()
    }

    fun stop() {
        running = false
        ws?.close(1000, null)
        ws = null
        onState(false)
    }

    private fun connect() {
        if (!running) return
        val url = "$scheme://$host:$port/app/$key?protocol=7&client=hummingbird-android&version=1.0"
        ws = client.newWebSocket(Request.Builder().url(url).build(), object : WebSocketListener() {
            override fun onMessage(webSocket: WebSocket, text: String) {
                val obj = runCatching { JSONObject(text) }.getOrNull() ?: return
                when (val event = obj.optString("event")) {
                    "pusher:connection_established" -> {
                        onState(true)
                        val sub = JSONObject()
                            .put("event", "pusher:subscribe")
                            .put("data", JSONObject().put("channel", channel))
                        webSocket.send(sub.toString())
                    }
                    "pusher:ping" -> webSocket.send("{\"event\":\"pusher:pong\",\"data\":{}}")
                    "pusher:error" -> onState(false)
                    else -> if (!event.startsWith("pusher")) onEvent()
                }
            }

            override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
                onState(false)
                scheduleReconnect()
            }

            override fun onClosed(webSocket: WebSocket, code: Int, reason: String) {
                onState(false)
            }
        })
    }

    private fun scheduleReconnect() {
        ws = null
        if (!running) return
        Thread {
            try { Thread.sleep(3000) } catch (_: InterruptedException) {}
            if (running) connect()
        }.start()
    }
}
