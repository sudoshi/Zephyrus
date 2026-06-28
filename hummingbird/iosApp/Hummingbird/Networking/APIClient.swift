import Foundation

/// Where the BFF lives, resolved per build so the same source runs against the right host:
/// - Simulator: the Mac's loopback (Dockerized `php artisan serve` reachable at localhost).
/// - Debug on a physical device: the Mac's LAN IP — a device can't see the Mac's loopback,
///   so both the phone and Mac must be on the same Wi-Fi. Override at launch with an
///   `HB_HOST` environment variable in the Xcode scheme when the Mac's IP changes.
/// - Release (TestFlight / App Store): the public BFF over HTTPS/WSS. ATS forbids cleartext
///   to a public host, so set `publicHost` to a real TLS-terminated domain before archiving.
enum AppConfig {
#if targetEnvironment(simulator)
    static let baseURL = "http://localhost:8001"
    static let reverbScheme = "ws"
    static let reverbHost = "localhost"
    static let reverbPort = 8080
#elseif DEBUG
    static let devHost = ProcessInfo.processInfo.environment["HB_HOST"] ?? "192.168.1.35"
    static let baseURL = "http://\(devHost):8001"
    static let reverbScheme = "ws"
    static let reverbHost = devHost
    static let reverbPort = 8080
#else
    // TODO: point at the deployed BFF before TestFlight (e.g. "hummingbird.acumenus.net").
    static let publicHost = "REPLACE_WITH_PUBLIC_HOST"
    static let baseURL = "https://\(publicHost)"
    static let reverbScheme = "wss"
    static let reverbHost = publicHost
    static let reverbPort = 443
#endif
    static let reverbKey = "zephyrus-key"
}

/// Thin async URLSession client for the Hummingbird BFF. This is the seam the KMP shared
/// `data` module will replace later — keep the surface small and DTO-driven.
struct APIClient {
    let baseURL: URL

    init(baseURL: URL) { self.baseURL = baseURL }

    private static let decoder: JSONDecoder = {
        let d = JSONDecoder()
        d.keyDecodingStrategy = .convertFromSnakeCase
        return d
    }()

    // MARK: Endpoints

    func token(username: String, password: String) async throws -> TokenResponse {
        let data = try await send(path: "/api/auth/token", method: "POST",
                                  body: ["username": username, "password": password], bearer: nil)
        return try Self.decoder.decode(TokenResponse.self, from: data)
    }

    /// POST /api/auth/change-password — exchange a scoped change token + temp password
    /// for a new password and a full session token pair. The caller guarantees the
    /// new password is confirmed client-side, so confirmation mirrors `newPassword`.
    func changePassword(currentPassword: String, newPassword: String, bearer: String) async throws -> TokenResponse {
        let data = try await send(path: "/api/auth/change-password", method: "POST",
                                  body: ["current_password": currentPassword,
                                         "new_password": newPassword,
                                         "new_password_confirmation": newPassword],
                                  bearer: bearer)
        return try Self.decoder.decode(TokenResponse.self, from: data)
    }

    func me(bearer: String) async throws -> MeData {
        try await getEnvelope(path: "/api/mobile/v1/me", bearer: bearer, as: MeData.self).data
    }

    func census(bearer: String) async throws -> Envelope<[CensusUnit]> {
        try await getEnvelope(path: "/api/mobile/v1/rtdc/census", bearer: bearer, as: [CensusUnit].self)
    }

    func forYou(bearer: String) async throws -> [ForYouItem] {
        try await getEnvelope(path: "/api/mobile/v1/for-you", bearer: bearer, as: [ForYouItem].self).data
    }

    /// POST /api/mobile/v1/devices — register this device's APNs token for push.
    func registerDevice(pushToken: String, appVersion: String?, osVersion: String?,
                        deviceName: String?, bearer: String) async throws {
        var body = ["platform": "ios", "push_token": pushToken]
        if let appVersion { body["app_version"] = appVersion }
        if let osVersion { body["os_version"] = osVersion }
        if let deviceName { body["device_name"] = deviceName }
        _ = try await send(path: "/api/mobile/v1/devices", method: "POST", body: body, bearer: bearer)
    }

    func revoke(bearer: String) async {
        _ = try? await send(path: "/api/auth/token/revoke", method: "POST", body: [:], bearer: bearer)
    }

    // MARK: Plumbing

    private func getEnvelope<T: Decodable>(path: String, bearer: String, as: T.Type) async throws -> Envelope<T> {
        let data = try await send(path: path, method: "GET", body: nil, bearer: bearer)
        return try Self.decoder.decode(Envelope<T>.self, from: data)
    }

    private func send(path: String, method: String, body: [String: String]?, bearer: String?) async throws -> Data {
        guard let url = URL(string: path, relativeTo: baseURL) else {
            throw APIError(message: "Bad URL", statusCode: nil)
        }
        var req = URLRequest(url: url)
        req.httpMethod = method
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        if let bearer { req.setValue("Bearer \(bearer)", forHTTPHeaderField: "Authorization") }
        if let body {
            req.setValue("application/json", forHTTPHeaderField: "Content-Type")
            req.httpBody = try JSONSerialization.data(withJSONObject: body)
        }
        req.timeoutInterval = 15

        let (data, response): (Data, URLResponse)
        do {
            (data, response) = try await URLSession.shared.data(for: req)
        } catch {
            throw APIError(message: "Can't reach the server. Is it running at \(baseURL.absoluteString)?", statusCode: nil)
        }

        guard let http = response as? HTTPURLResponse else {
            throw APIError(message: "Invalid response", statusCode: nil)
        }
        guard (200..<300).contains(http.statusCode) else {
            throw APIError(message: Self.errorMessage(from: data, status: http.statusCode), statusCode: http.statusCode)
        }
        return data
    }

    private static func errorMessage(from data: Data, status: Int) -> String {
        if let obj = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
            if let err = obj["error"] as? [String: Any], let m = err["message"] as? String { return m }
            if let m = obj["message"] as? String, !m.isEmpty { return m }
        }
        if status == 401 { return "Your session has expired. Please sign in again." }
        return "Request failed (HTTP \(status))."
    }
}
