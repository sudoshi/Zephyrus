import Foundation

/// Minimal Pusher-protocol client over URLSessionWebSocketTask, talking to Laravel Reverb.
/// Subscribes to a public (PHI-free) channel and invokes `onEvent` when a data event arrives;
/// the caller re-snapshots (Reverb does not replay). Auto-reconnects with a small backoff.
/// This is the foreground real-time tier — the app still polls as a fallback.
@MainActor
final class RealtimeClient {
    private let url: URL
    private let channel: String
    private let onEvent: () -> Void
    private let onState: (Bool) -> Void

    private var task: URLSessionWebSocketTask?
    private var running = false

    init(host: String, port: Int, key: String, channel: String,
         onEvent: @escaping () -> Void, onState: @escaping (Bool) -> Void) {
        self.url = URL(string: "ws://\(host):\(port)/app/\(key)?protocol=7&client=hummingbird-ios&version=1.0")!
        self.channel = channel
        self.onEvent = onEvent
        self.onState = onState
    }

    func start() {
        guard !running else { return }
        running = true
        connect()
    }

    func stop() {
        running = false
        task?.cancel(with: .goingAway, reason: nil)
        task = nil
        onState(false)
    }

    private func connect() {
        guard running else { return }
        let t = URLSession.shared.webSocketTask(with: url)
        task = t
        t.resume()
        receive()
    }

    private func receive() {
        task?.receive { [weak self] result in
            Task { @MainActor in
                guard let self, self.running else { return }
                switch result {
                case .failure:
                    self.onState(false)
                    self.scheduleReconnect()
                case .success(let message):
                    if case .string(let text) = message { self.handle(text) }
                    self.receive()
                }
            }
        }
    }

    private func handle(_ text: String) {
        guard let data = text.data(using: .utf8),
              let obj = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let event = obj["event"] as? String else { return }
        switch event {
        case "pusher:connection_established":
            onState(true)
            send(["event": "pusher:subscribe", "data": ["channel": channel]])
        case "pusher:ping":
            send(["event": "pusher:pong", "data": [:]])
        case "pusher:error":
            onState(false)
        default:
            // Any app data event on our channel means "state changed" — re-snapshot.
            if event.hasPrefix("pusher") == false { onEvent() }
        }
    }

    private func send(_ obj: [String: Any]) {
        guard let data = try? JSONSerialization.data(withJSONObject: obj),
              let text = String(data: data, encoding: .utf8) else { return }
        task?.send(.string(text)) { _ in }
    }

    private func scheduleReconnect() {
        task = nil
        Task { @MainActor in
            try? await Task.sleep(for: .seconds(3))
            if self.running { self.connect() }
        }
    }
}
