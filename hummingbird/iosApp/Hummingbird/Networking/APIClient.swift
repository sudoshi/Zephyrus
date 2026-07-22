import Foundation
import CryptoKit

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
    let session: URLSession

    init(baseURL: URL, session: URLSession = .shared) {
        self.baseURL = baseURL
        self.session = session
    }

    /// Restricted and NO_CACHE mobile surfaces use a cookie-free,
    /// credential-storage-free ephemeral session with no URL cache. Callers also
    /// set explicit no-store request headers on each classified endpoint below.
    static func noCache(baseURL: URL) -> APIClient {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.urlCache = nil
        configuration.requestCachePolicy = .reloadIgnoringLocalCacheData
        configuration.httpCookieStorage = nil
        configuration.httpShouldSetCookies = false
        configuration.urlCredentialStorage = nil

        return APIClient(baseURL: baseURL, session: URLSession(configuration: configuration))
    }

    static func patientCommunications(baseURL: URL) -> APIClient {
        noCache(baseURL: baseURL)
    }

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
        try await getEnvelope(
            path: "/api/mobile/v1/for-you",
            bearer: bearer,
            as: [ForYouItem].self,
            noStore: true
        ).data
    }

    // MARK: Accountable patient communications

    func patientCommunicationsInbox(bearer: String) async throws -> PatientCommunicationInboxData {
        try await getEnvelope(
            path: "/api/mobile/v1/patient-communications/inbox",
            bearer: bearer,
            as: PatientCommunicationInboxData.self,
            noStore: true
        ).data
    }

    func patientCommunicationThread(workItemUUID: String, bearer: String) async throws -> PatientCommunicationWorkItem {
        let encodedUUID = Self.pathComponent(workItemUUID)
        return try await getEnvelope(
            path: "/api/mobile/v1/patient-communications/threads/\(encodedUUID)",
            bearer: bearer,
            as: PatientCommunicationWorkItem.self,
            noStore: true
        ).data
    }

    func claimPatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        let encodedUUID = Self.pathComponent(workItemUUID)
        let data = try await send(
            path: "/api/mobile/v1/patient-communications/threads/\(encodedUUID)/claim",
            method: "POST",
            body: [
                "work_item_version": workItemVersion,
                "thread_version": threadVersion,
            ],
            bearer: bearer,
            explicitIdempotencyKey: idempotencyKey,
            noStore: true
        )
        return try Self.decoder.decode(Envelope<PatientCommunicationMutationData>.self, from: data).data
    }

    func replyToPatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        message: String,
        clientMessageUUID: UUID,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        let encodedUUID = Self.pathComponent(workItemUUID)
        let data = try await send(
            path: "/api/mobile/v1/patient-communications/threads/\(encodedUUID)/reply",
            method: "POST",
            body: [
                "work_item_version": workItemVersion,
                "thread_version": threadVersion,
                "message": message,
                "client_message_uuid": clientMessageUUID.uuidString.lowercased(),
            ],
            bearer: bearer,
            explicitIdempotencyKey: idempotencyKey,
            noStore: true
        )
        return try Self.decoder.decode(Envelope<PatientCommunicationMutationData>.self, from: data).data
    }

    func closePatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        reasonCode: PatientCommunicationCloseReason,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        let encodedUUID = Self.pathComponent(workItemUUID)
        let data = try await send(
            path: "/api/mobile/v1/patient-communications/threads/\(encodedUUID)/close",
            method: "POST",
            body: [
                "work_item_version": workItemVersion,
                "thread_version": threadVersion,
                "reason_code": reasonCode.rawValue,
            ],
            bearer: bearer,
            explicitIdempotencyKey: idempotencyKey,
            noStore: true
        )
        return try Self.decoder.decode(Envelope<PatientCommunicationMutationData>.self, from: data).data
    }

    func patientCommunicationRouteCandidates(
        workItemUUID: String,
        bearer: String
    ) async throws -> PatientCommunicationRouteCandidatesData {
        let canonicalWorkItemUUID = try Self.canonicalRoutingUUID(workItemUUID)
        let encodedUUID = Self.pathComponent(canonicalWorkItemUUID)
        let result = try await getEnvelope(
            path: "/api/mobile/v1/patient-communications/threads/\(encodedUUID)/route-candidates",
            bearer: bearer,
            as: PatientCommunicationRouteCandidatesData.self,
            noStore: true
        ).data
        guard result.workItemUuid == canonicalWorkItemUUID else {
            throw APIError(message: "Routing options could not be verified.", statusCode: nil)
        }
        return result
    }

    func releasePatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        reasonCode: String,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        try Self.requireRoutingReason(reasonCode, action: .release)
        return try await patientCommunicationRoutingMutation(
            workItemUUID: workItemUUID,
            action: .release,
            workItemVersion: workItemVersion,
            threadVersion: threadVersion,
            targetKey: nil,
            targetUUID: nil,
            reasonCode: reasonCode,
            idempotencyKey: idempotencyKey,
            bearer: bearer
        )
    }

    func reassignPatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        targetMembershipUUID: String,
        reasonCode: String,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        try Self.requireRoutingReason(reasonCode, action: .reassign)
        return try await patientCommunicationRoutingMutation(
            workItemUUID: workItemUUID,
            action: .reassign,
            workItemVersion: workItemVersion,
            threadVersion: threadVersion,
            targetKey: "target_membership_uuid",
            targetUUID: targetMembershipUUID,
            reasonCode: reasonCode,
            idempotencyKey: idempotencyKey,
            bearer: bearer
        )
    }

    func reroutePatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        targetPoolUUID: String,
        reasonCode: String,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        try Self.requireRoutingReason(reasonCode, action: .reroute)
        return try await patientCommunicationRoutingMutation(
            workItemUUID: workItemUUID,
            action: .reroute,
            workItemVersion: workItemVersion,
            threadVersion: threadVersion,
            targetKey: "target_pool_uuid",
            targetUUID: targetPoolUUID,
            reasonCode: reasonCode,
            idempotencyKey: idempotencyKey,
            bearer: bearer
        )
    }

    private func patientCommunicationRoutingMutation(
        workItemUUID: String,
        action: PatientCommunicationRoutingAction,
        workItemVersion: Int,
        threadVersion: Int,
        targetKey: String?,
        targetUUID: String?,
        reasonCode: String,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        let canonicalWorkItemUUID = try Self.canonicalRoutingUUID(workItemUUID)
        let encodedUUID = Self.pathComponent(canonicalWorkItemUUID)
        var body: [String: Any] = [
            "work_item_version": workItemVersion,
            "thread_version": threadVersion,
            "reason_code": reasonCode,
        ]
        if let targetKey {
            guard let targetUUID else {
                throw APIError(message: "A routing target is required.", statusCode: nil)
            }
            body[targetKey] = try Self.canonicalRoutingUUID(targetUUID)
        } else if targetUUID != nil {
            throw APIError(message: "This routing action does not accept a target.", statusCode: nil)
        }

        let data = try await send(
            path: "/api/mobile/v1/patient-communications/threads/\(encodedUUID)/\(action.rawValue)",
            method: "POST",
            body: body,
            bearer: bearer,
            explicitIdempotencyKey: idempotencyKey,
            noStore: true
        )
        return try Self.decoder.decode(Envelope<PatientCommunicationMutationData>.self, from: data).data
    }

    // MARK: Altitude 2.0 common contract (A0/A1/A2/A2P + relay/Eddy)

    func altitudeHome(persona: String?, bearer: String) async throws -> Envelope<MobileAltitudeHome> {
        try await getEnvelope(path: withPersona("/api/mobile/v1/altitude/home", persona), bearer: bearer, as: MobileAltitudeHome.self)
    }

    func altitudeWorkspace(domain: String, persona: String?, bearer: String) async throws -> Envelope<MobileAltitudeWorkspace> {
        let encodedDomain = Self.pathComponent(domain)
        return try await getEnvelope(path: withPersona("/api/mobile/v1/altitude/workspace/\(encodedDomain)", persona), bearer: bearer, as: MobileAltitudeWorkspace.self)
    }

    func altitudeDrill(itemUuid: String, persona: String?, bearer: String) async throws -> Envelope<MobileAltitudeDrill> {
        let encodedUuid = Self.pathComponent(itemUuid)
        return try await getEnvelope(path: withPersona("/api/mobile/v1/drills/\(encodedUuid)", persona), bearer: bearer, as: MobileAltitudeDrill.self)
    }

    func patientOperationalContext(contextRef: String, persona: String?, bearer: String) async throws -> Envelope<PatientOperationalContext> {
        let encodedRef = Self.pathComponent(contextRef)
        return try await getEnvelope(path: withPersona("/api/mobile/v1/patients/\(encodedRef)/operational-context", persona), bearer: bearer, as: PatientOperationalContext.self)
    }

    func activity(persona: String?, bearer: String) async throws -> Envelope<[ActivityEvent]> {
        try await getEnvelope(path: withPersona("/api/mobile/v1/activity", persona), bearer: bearer, as: [ActivityEvent].self)
    }

    func acknowledgeActivity(eventUuid: String, persona: String?, bearer: String) async throws -> ActivityAck {
        let encodedUuid = Self.pathComponent(eventUuid)
        let data = try await send(path: withPersona("/api/mobile/v1/activity/\(encodedUuid)/ack", persona),
                                  method: "POST", body: [:], bearer: bearer)
        return try Self.decoder.decode(Envelope<ActivityAck>.self, from: data).data
    }

    func eddyContext(scopeRef: String, persona: String?, bearer: String) async throws -> Envelope<EddyContextPacket> {
        let encodedRef = Self.pathComponent(scopeRef)
        return try await getEnvelope(path: withPersona("/api/mobile/v1/eddy/context/\(encodedRef)", persona), bearer: bearer, as: EddyContextPacket.self)
    }

    /// Send a chat turn to Eddy (persona-aware, grounded in live operations + the RAG
    /// knowledge base). Uses a longer timeout than the shared `send` because a model turn
    /// can take several seconds; a 503 hard-failure still carries a usable reply envelope.
    func eddyChat(message: String, conversationId: String?, persona: String?,
                  surface: String? = "hummingbird", pageContext: String? = nil,
                  pageComponent: String? = nil, pageData: [String: String]? = nil,
                  bearer: String) async throws -> Envelope<EddyChatReply> {
        guard let url = URL(string: withPersona("/api/mobile/v1/eddy/chat", persona), relativeTo: baseURL) else {
            throw APIError(message: "Bad URL", statusCode: nil)
        }
        var payload: [String: Any] = ["message": message]
        if let conversationId { payload["conversation_id"] = conversationId }
        if let surface { payload["surface"] = surface }
        if let pageContext { payload["page_context"] = pageContext }
        if let pageComponent { payload["page_component"] = pageComponent }
        if let pageData, !pageData.isEmpty { payload["page_data"] = pageData }

        var req = URLRequest(url: url)
        req.httpMethod = "POST"
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.setValue("Bearer \(bearer)", forHTTPHeaderField: "Authorization")
        req.httpBody = try JSONSerialization.data(withJSONObject: payload)
        req.timeoutInterval = 60

        let (data, response): (Data, URLResponse)
        do {
            (data, response) = try await session.data(for: req)
        } catch {
            throw APIError(message: "Can't reach Eddy. Is the server running at \(baseURL.absoluteString)?", statusCode: nil)
        }
        guard let http = response as? HTTPURLResponse else {
            throw APIError(message: "Invalid response", statusCode: nil)
        }
        // The 503 unavailable envelope still decodes (data.message is a friendly string).
        if http.statusCode == 503, let envelope = try? Self.decoder.decode(Envelope<EddyChatReply>.self, from: data) {
            return envelope
        }
        guard (200..<300).contains(http.statusCode) else {
            throw APIError(message: Self.errorMessage(from: data, status: http.statusCode), statusCode: http.statusCode)
        }
        return try Self.decoder.decode(Envelope<EddyChatReply>.self, from: data)
    }

    /// POST an action endpoint supplied by an A2 drill payload. Use only for endpoints whose
    /// server-side contract needs no additional body (for example barrier resolve).
    func performMobileAction(endpoint: String, bearer: String) async throws {
        _ = try await send(path: endpoint, method: "POST", body: [:], bearer: bearer)
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
        var body: [String: Any] = ["action": action]
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

    func staffingCandidates(id: Int, bearer: String) async throws -> StaffingCandidatePage {
        try await getEnvelope(
            path: "/api/mobile/v1/staffing/requests/\(id)/candidates?persona=staffing_coordinator&per_page=100",
            bearer: bearer,
            as: StaffingCandidatePage.self
        ).data
    }

    /// POST …/staffing/requests/{id}/fill — fill with a server-validated canonical person (mobile:act).
    func staffingFill(id: Int, staffMemberId: Int, source: String, bearer: String) async throws {
        _ = try await send(path: "/api/mobile/v1/staffing/requests/\(id)/fill", method: "POST",
                           body: ["staff_member_id": "\(staffMemberId)", "assigned_source": source], bearer: bearer)
    }

    func improvementPdsa(bearer: String) async throws -> [PdsaCycle] {
        try await getEnvelope(path: "/api/mobile/v1/improvement/pdsa", bearer: bearer, as: [PdsaCycle].self).data
    }

    func improvementOpportunities(bearer: String) async throws -> [Opportunity] {
        try await getEnvelope(path: "/api/mobile/v1/improvement/opportunities", bearer: bearer, as: [Opportunity].self).data
    }

    // MARK: Transport (P1)

    func transportQueue(bearer: String, cursor: String? = nil) async throws -> Envelope<TransportQueue> {
        var path = "/api/mobile/v1/transport/queue?persona=transport"
        if let cursor, !cursor.isEmpty {
            path += "&cursor=\(Self.queryValue(cursor))"
        }
        return try await getEnvelope(path: path, bearer: bearer, as: TransportQueue.self)
    }

    /// POST …/transport/requests/{id}/status — advance a job (Claim → … → Completed).
    func transportStatus(id: Int, status: String, lifecycleVersion: Int,
                         bearer: String) async throws -> TransportJob {
        let data = try await send(path: "/api/mobile/v1/transport/requests/\(id)/status", method: "POST",
                                  body: ["status": status, "lifecycle_version": lifecycleVersion], bearer: bearer)
        return try Self.decoder.decode(Envelope<TransportJob>.self, from: data).data
    }

    /// POST …/transport/requests/{id}/handoff — structured handoff at the destination.
    func transportHandoff(id: Int, handoffTo: String, receiverRole: String,
                          acceptanceStatus: String, outstandingRisk: String?,
                          summary: String?, lifecycleVersion: Int,
                          bearer: String) async throws -> TransportJob {
        var body: [String: Any] = [
            "handoff_to": handoffTo,
            "receiver_role": receiverRole,
            "acceptance_status": acceptanceStatus,
            "lifecycle_version": lifecycleVersion,
        ]
        if let outstandingRisk, !outstandingRisk.isEmpty {
            body["outstanding_risks"] = [outstandingRisk]
        }
        if let summary, !summary.isEmpty { body["handoff_summary"] = summary }
        let data = try await send(path: "/api/mobile/v1/transport/requests/\(id)/handoff", method: "POST",
                                  body: body, bearer: bearer)
        return try Self.decoder.decode(Envelope<TransportJob>.self, from: data).data
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

    // MARK: Flow Window (48h spatiotemporal lens)

    /// GET /api/mobile/v1/flow/window?persona=&scope=&since= — the persona-lensed 48h window
    /// (snapshots + events + projections + per-floor rollups) for a scope the lens allows.
    /// `since` (ISO-8601, within [window.from, window.to]) requests a delta: only events and
    /// snapshots with t > since come back; projections/spaces/bed_statuses stay full. An
    /// out-of-range/malformed `since` is a 422 (invalid_since) — callers fall back to a full load.
    func flowWindow(persona: String?, scope: String?, since: String? = nil, bearer: String) async throws -> Envelope<FlowWindowData> {
        try await getEnvelope(path: flowWindowPath(persona: persona, scope: scope, since: since),
                              bearer: bearer, as: FlowWindowData.self)
    }

    /// As `flowWindow`, but also returns the raw envelope bytes so the caller can persist the
    /// last FULL window to the offline cache verbatim (no round-trip through Encodable DTOs).
    func flowWindowRaw(persona: String?, scope: String?, since: String? = nil, bearer: String) async throws -> (Envelope<FlowWindowData>, Data) {
        let data = try await send(path: flowWindowPath(persona: persona, scope: scope, since: since),
                                  method: "GET", body: nil, bearer: bearer)
        return (try Self.decoder.decode(Envelope<FlowWindowData>.self, from: data), data)
    }

    /// GET /api/mobile/v1/flow/demo-scenarios — discover demo/history handoff scenarios.
    func flowDemoScenarios(persona: String?, bearer: String) async throws -> Envelope<[FlowDemoScenario]> {
        try await getEnvelope(path: withPersona("/api/mobile/v1/flow/demo-scenarios", persona),
                              bearer: bearer, as: [FlowDemoScenario].self)
    }

    /// GET /api/mobile/v1/flow/occupancy/history — disk-ready occupancy history, persona-redacted.
    func flowOccupancyHistory(persona: String?, from: String? = nil, to: String? = nil,
                              asOf: String? = nil, serviceLine: String? = nil,
                              floor: Int? = nil, demo: String? = nil,
                              scenario: String? = nil, limit: Int? = nil,
                              bearer: String) async throws -> Envelope<FlowOccupancyHistoryData> {
        try await getEnvelope(path: flowOccupancyHistoryPath(persona: persona, from: from, to: to,
                                                             asOf: asOf, serviceLine: serviceLine,
                                                             floor: floor, demo: demo,
                                                             scenario: scenario, limit: limit),
                              bearer: bearer, as: FlowOccupancyHistoryData.self)
    }

    private func flowWindowPath(persona: String?, scope: String?, since: String?) -> String {
        var path = withPersona("/api/mobile/v1/flow/window", persona)
        if let scope, !scope.isEmpty {
            path += "\(path.contains("?") ? "&" : "?")scope=\(Self.queryValue(scope))"
        }
        if let since, !since.isEmpty {
            path += "\(path.contains("?") ? "&" : "?")since=\(Self.queryValue(since))"
        }
        return path
    }

    private func flowOccupancyHistoryPath(persona: String?, from: String?, to: String?,
                                          asOf: String?, serviceLine: String?,
                                          floor: Int?, demo: String?,
                                          scenario: String?, limit: Int?) -> String {
        var path = withPersona("/api/mobile/v1/flow/occupancy/history", persona)
        if let from, !from.isEmpty {
            path += "\(path.contains("?") ? "&" : "?")from=\(Self.queryValue(from))"
        }
        if let to, !to.isEmpty {
            path += "\(path.contains("?") ? "&" : "?")to=\(Self.queryValue(to))"
        }
        if let asOf, !asOf.isEmpty {
            path += "\(path.contains("?") ? "&" : "?")asOf=\(Self.queryValue(asOf))"
        }
        if let serviceLine, !serviceLine.isEmpty {
            path += "\(path.contains("?") ? "&" : "?")service_line=\(Self.queryValue(serviceLine))"
        }
        if let floor {
            path += "\(path.contains("?") ? "&" : "?")floor=\(floor)"
        }
        if let demo, !demo.isEmpty {
            path += "\(path.contains("?") ? "&" : "?")demo=\(Self.queryValue(demo))"
        }
        if let scenario, !scenario.isEmpty {
            path += "\(path.contains("?") ? "&" : "?")scenario=\(Self.queryValue(scenario))"
        }
        if let limit {
            path += "\(path.contains("?") ? "&" : "?")limit=\(limit)"
        }
        return path
    }

    /// GET /api/mobile/v1/flow/floors — the versioned floor-plates asset (plan-view rects).
    /// Server-side it is ETag-cacheable; the client re-fetches per session, which is cheap
    /// enough at < 60 KB gzipped per floor.
    func flowFloors(bearer: String) async throws -> Envelope<FlowFloorsDocument> {
        try await getEnvelope(path: "/api/mobile/v1/flow/floors", bearer: bearer, as: FlowFloorsDocument.self)
    }

    /// As `flowFloors`, but returns the raw bytes too (persisted alongside the window in the
    /// offline cache so the plates render offline).
    func flowFloorsRaw(bearer: String) async throws -> (Envelope<FlowFloorsDocument>, Data) {
        let data = try await send(path: "/api/mobile/v1/flow/floors", method: "GET", body: nil, bearer: bearer)
        return (try Self.decoder.decode(Envelope<FlowFloorsDocument>.self, from: data), data)
    }

    /// GET /api/mobile/v1/flow/spaces3d — the versioned 3D space-anchor asset (per-space
    /// metre centroids + unit/bed bridges) the native SceneKit scene places segments and
    /// tokens by. ETag-cacheable; fetched once per session.
    func flowSpaces3d(bearer: String) async throws -> Envelope<FlowSpaces3dDocument> {
        try await getEnvelope(path: "/api/mobile/v1/flow/spaces3d", bearer: bearer, as: FlowSpaces3dDocument.self)
    }

    /// POST /api/mobile/v1/devices — register this device's APNs token for push.
    func registerDevice(pushToken: String, appVersion: String?, osVersion: String?,
                        deviceName: String?, bearer: String) async throws {
        var body: [String: Any] = ["platform": "ios", "push_token": pushToken]
        if let appVersion { body["app_version"] = appVersion }
        if let osVersion { body["os_version"] = osVersion }
        if let deviceName { body["device_name"] = deviceName }
        _ = try await send(path: "/api/mobile/v1/devices", method: "POST", body: body, bearer: bearer)
    }

    func revoke(bearer: String) async {
        _ = try? await send(path: "/api/auth/token/revoke", method: "POST", body: [:], bearer: bearer)
    }

    // MARK: Plumbing

    private func withPersona(_ path: String, _ persona: String?) -> String {
        guard let persona, !persona.isEmpty else { return path }
        let separator = path.contains("?") ? "&" : "?"
        return "\(path)\(separator)persona=\(Self.queryValue(persona))"
    }

    private static func pathComponent(_ raw: String) -> String {
        raw.addingPercentEncoding(withAllowedCharacters: .urlPathAllowed) ?? raw
    }

    private static func canonicalRoutingUUID(_ raw: String) throws -> String {
        let pattern = "^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"
        guard raw.range(of: pattern, options: .regularExpression) != nil,
              let uuid = UUID(uuidString: raw),
              raw == uuid.uuidString.lowercased() else {
            throw APIError(message: "The routing identifier is invalid.", statusCode: nil)
        }
        return raw
    }

    private static func requireRoutingReason(
        _ reasonCode: String,
        action: PatientCommunicationRoutingAction
    ) throws {
        guard action.allows(reasonCode: reasonCode) else {
            throw APIError(message: "The selected routing reason is unavailable.", statusCode: 422)
        }
    }

    private static func queryValue(_ raw: String) -> String {
        let allowed = CharacterSet(charactersIn: "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~")
        return raw.addingPercentEncoding(withAllowedCharacters: allowed) ?? raw
    }

    private func getEnvelope<T: Decodable>(
        path: String,
        bearer: String,
        as: T.Type,
        noStore: Bool = false
    ) async throws -> Envelope<T> {
        let data = try await send(path: path, method: "GET", body: nil, bearer: bearer, noStore: noStore)
        return try Self.decoder.decode(Envelope<T>.self, from: data)
    }

    private func send(
        path: String,
        method: String,
        body: [String: Any]?,
        bearer: String?,
        explicitIdempotencyKey: UUID? = nil,
        noStore: Bool = false
    ) async throws -> Data {
        guard let url = URL(string: path, relativeTo: baseURL) else {
            throw APIError(message: "Bad URL", statusCode: nil)
        }
        var req = URLRequest(url: url)
        req.httpMethod = method
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        if let bearer { req.setValue("Bearer \(bearer)", forHTTPHeaderField: "Authorization") }
        let bodyData = try body.map { try JSONSerialization.data(withJSONObject: $0, options: [.sortedKeys]) }
        if let explicitIdempotencyKey {
            req.setValue(explicitIdempotencyKey.uuidString.lowercased(), forHTTPHeaderField: "Idempotency-Key")
        } else if let idempotencyKey = Self.mobileIdempotencyKey(method: method, path: path, bodyData: bodyData) {
            req.setValue(idempotencyKey, forHTTPHeaderField: "Idempotency-Key")
        }
        if noStore {
            req.cachePolicy = .reloadIgnoringLocalCacheData
            req.setValue("no-store, no-cache, max-age=0", forHTTPHeaderField: "Cache-Control")
            req.setValue("no-cache", forHTTPHeaderField: "Pragma")
        }
        if body != nil {
            req.setValue("application/json", forHTTPHeaderField: "Content-Type")
            req.httpBody = bodyData
        }
        req.timeoutInterval = 15

        let (data, response): (Data, URLResponse)
        do {
            (data, response) = try await session.data(for: req)
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

    internal static func mobileIdempotencyKey(method: String, path: String, bodyData: Data?) -> String? {
        guard method.uppercased() == "POST", path.hasPrefix("/api/mobile/v1/") else { return nil }
        let bodyString = bodyData.flatMap { String(data: $0, encoding: .utf8) } ?? ""
        let material = "\(method.uppercased())\n\(path)\n\(bodyString)"
        let digest = SHA256.hash(data: Data(material.utf8))
            .map { String(format: "%02x", $0) }
            .joined()

        return "hb-\(digest)"
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
