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
    // Production BFF — globally reachable, valid TLS, serves the mobile routes (verified 2026-06-28).
    static let publicHost = "zephyrus.acumenus.net"
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

    /// POST …/rtdc/barriers/{id}/resolve — clear an open discharge barrier (mobile:act).
    func resolveBarrier(id: Int, bearer: String) async throws {
        _ = try await send(path: "/api/mobile/v1/rtdc/barriers/\(id)/resolve", method: "POST", body: [:], bearer: bearer)
    }

    // MARK: RTDC house + bed placement (P5)

    func rtdcHouse(bearer: String) async throws -> Envelope<HouseRollup> {
        try await getEnvelope(path: "/api/mobile/v1/rtdc/house", bearer: bearer, as: HouseRollup.self)
    }

    func placements(bearer: String) async throws -> Envelope<[Placement]> {
        try await getEnvelope(path: "/api/mobile/v1/rtdc/bed-requests", bearer: bearer, as: [Placement].self)
    }

    func placementRecommendations(id: Int, bearer: String) async throws -> PlacementRecs {
        try await getEnvelope(path: "/api/mobile/v1/rtdc/bed-requests/\(id)/recommendations", bearer: bearer, as: PlacementRecs.self).data
    }

    /// POST …/rtdc/bed-requests/{id}/decision — place (accept a chosen bed) or reject (mobile:act).
    func placeBed(id: Int, action: String, chosenBedId: Int?, bearer: String) async throws {
        var body = ["action": action]
        if let chosenBedId { body["chosen_bed_id"] = String(chosenBedId) }
        _ = try await send(path: "/api/mobile/v1/rtdc/bed-requests/\(id)/decision", method: "POST", body: body, bearer: bearer)
    }

    // MARK: Executive / Capacity / OR / Staffing / Improvement (P9 / P6 / P4 / P7 / P10 / P8)

    func commandHouse(bearer: String) async throws -> Envelope<HouseBrief> {
        try await getEnvelope(path: "/api/mobile/v1/command/house", bearer: bearer, as: HouseBrief.self)
    }

    func orBoard(bearer: String) async throws -> Envelope<ORBoard> {
        try await getEnvelope(path: "/api/mobile/v1/or/board", bearer: bearer, as: ORBoard.self)
    }

    func opsInbox(bearer: String) async throws -> Envelope<[OpsApproval]> {
        try await getEnvelope(path: "/api/mobile/v1/ops/inbox", bearer: bearer, as: [OpsApproval].self)
    }

    /// POST …/ops/approvals/{uuid}/decision — approve/reject a governed action (mobile:act).
    func opsDecide(uuid: String, decision: String, bearer: String) async throws {
        _ = try await send(path: "/api/mobile/v1/ops/approvals/\(uuid)/decision", method: "POST",
                           body: ["decision": decision], bearer: bearer)
    }

    func staffingOverview(bearer: String) async throws -> Envelope<StaffingOverview> {
        try await getEnvelope(path: "/api/mobile/v1/staffing/overview", bearer: bearer, as: StaffingOverview.self)
    }

    /// POST …/staffing/requests/{id}/fill — assign a source and mark filled (mobile:act).
    func staffingFill(id: Int, source: String, bearer: String) async throws {
        _ = try await send(path: "/api/mobile/v1/staffing/requests/\(id)/fill", method: "POST",
                           body: ["assigned_source": source], bearer: bearer)
    }

    func improvementPdsa(bearer: String) async throws -> [PdsaCycle] {
        try await getEnvelope(path: "/api/mobile/v1/improvement/pdsa", bearer: bearer, as: [PdsaCycle].self).data
    }

    func improvementOpportunities(bearer: String) async throws -> [Opportunity] {
        try await getEnvelope(path: "/api/mobile/v1/improvement/opportunities", bearer: bearer, as: [Opportunity].self).data
    }

    // MARK: Transport (P1)

    func transportQueue(bearer: String) async throws -> Envelope<TransportQueue> {
        try await getEnvelope(path: "/api/mobile/v1/transport/queue", bearer: bearer, as: TransportQueue.self)
    }

    /// POST …/transport/requests/{id}/status — advance a job (Claim → … → Completed).
    func transportStatus(id: Int, status: String, bearer: String) async throws {
        _ = try await send(path: "/api/mobile/v1/transport/requests/\(id)/status", method: "POST",
                           body: ["status": status], bearer: bearer)
    }

    /// POST …/transport/requests/{id}/handoff — structured handoff at the destination.
    func transportHandoff(id: Int, handoffTo: String, summary: String?, bearer: String) async throws {
        var body = ["handoff_to": handoffTo]
        if let summary, !summary.isEmpty { body["handoff_summary"] = summary }
        _ = try await send(path: "/api/mobile/v1/transport/requests/\(id)/handoff", method: "POST",
                           body: body, bearer: bearer)
    }

    // MARK: EVS / bed-turns (P2)

    func evsQueue(bearer: String) async throws -> Envelope<EvsQueue> {
        try await getEnvelope(path: "/api/mobile/v1/evs/queue", bearer: bearer, as: EvsQueue.self)
    }

    /// POST …/evs/requests/{id}/status — advance a turn (Claim → Start → Complete).
    func evsStatus(id: Int, status: String, bearer: String) async throws {
        _ = try await send(path: "/api/mobile/v1/evs/requests/\(id)/status", method: "POST",
                           body: ["status": status], bearer: bearer)
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
