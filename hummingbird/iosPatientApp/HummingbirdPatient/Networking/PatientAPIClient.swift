import Foundation

enum PatientAPIEndpoint: Equatable {
    case enroll
    case token
    case refresh
    case revoke
    case profile
    case preferences
    case sessions
    case revokeSession(sessionUUID: String)
    case encounters
    case today(encounterUUID: String)
    case pathway(encounterUUID: String)
    case pathwayEvents(encounterUUID: String)
    case dischargeReadiness(encounterUUID: String)
    case roundsSummary(encounterUUID: String)
    case careTeam(encounterUUID: String)
    case messageTopics(encounterUUID: String)
    case messageThreads(encounterUUID: String)
    case createMessageThread(encounterUUID: String)
    case educationClarification(encounterUUID: String, educationItemUUID: String)
    case messageThread(threadUUID: String)
    case sendMessage(threadUUID: String)
    case amendMessage(threadUUID: String, messageUUID: String)
    case closeMessageThread(threadUUID: String)

    static func inventory(referenceEncounterUUID: String) -> [PatientAPIEndpoint] {
        [
            .enroll,
            .token,
            .refresh,
            .revoke,
            .profile,
            .preferences,
            .sessions,
            .revokeSession(sessionUUID: referenceEncounterUUID),
            .encounters,
            .today(encounterUUID: referenceEncounterUUID),
            .pathway(encounterUUID: referenceEncounterUUID),
            .pathwayEvents(encounterUUID: referenceEncounterUUID),
            .dischargeReadiness(encounterUUID: referenceEncounterUUID),
            .roundsSummary(encounterUUID: referenceEncounterUUID),
            .careTeam(encounterUUID: referenceEncounterUUID),
            .messageTopics(encounterUUID: referenceEncounterUUID),
            .messageThreads(encounterUUID: referenceEncounterUUID),
            .createMessageThread(encounterUUID: referenceEncounterUUID),
            .educationClarification(
                encounterUUID: referenceEncounterUUID,
                educationItemUUID: referenceEncounterUUID
            ),
            .messageThread(threadUUID: referenceEncounterUUID),
            .sendMessage(threadUUID: referenceEncounterUUID),
            .amendMessage(threadUUID: referenceEncounterUUID, messageUUID: referenceEncounterUUID),
            .closeMessageThread(threadUUID: referenceEncounterUUID),
        ]
    }

    var method: String {
        switch self {
        case .enroll, .token, .refresh, .revoke,
             .createMessageThread, .educationClarification, .sendMessage, .amendMessage, .closeMessageThread: "POST"
        case .preferences: "PUT"
        case .revokeSession: "DELETE"
        case .profile, .encounters, .today, .pathway, .pathwayEvents, .dischargeReadiness, .roundsSummary, .careTeam,
             .messageTopics, .messageThreads, .messageThread, .sessions: "GET"
        }
    }

    var path: String {
        PatientAPIBoundary.path + "/" + relativePath
    }

    func url(relativeTo baseURL: URL) throws -> URL {
        guard PatientAPIBoundary.validatedBaseURL(baseURL.absoluteString) != nil,
              hasValidParameters,
              !relativePath.contains(".."),
              !relativePath.hasPrefix("/"),
              !relativePath.contains("?"),
              !relativePath.contains("#")
        else { throw PatientAPIError.invalidBoundary }

        var components = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)
        components?.path = path
        components?.query = nil
        components?.fragment = nil
        guard let url = components?.url,
              url.path == path,
              url.path.hasPrefix(PatientAPIBoundary.path + "/")
        else { throw PatientAPIError.invalidBoundary }
        return url
    }

    private var relativePath: String {
        switch self {
        case .enroll: "auth/enroll/challenge/verify"
        case .token: "auth/token"
        case .refresh: "auth/token/refresh"
        case .revoke: "auth/token/revoke"
        case .profile: "me"
        case .preferences: "me/preferences"
        case .sessions: "me/sessions"
        case .revokeSession(let sessionUUID): sessionPath(sessionUUID)
        case .encounters: "encounters"
        case .today(let encounterUUID): projectionPath(encounterUUID, suffix: "today")
        case .pathway(let encounterUUID): projectionPath(encounterUUID, suffix: "pathway")
        case .pathwayEvents(let encounterUUID): projectionPath(encounterUUID, suffix: "pathway/events")
        case .dischargeReadiness(let encounterUUID): projectionPath(encounterUUID, suffix: "discharge-readiness")
        case .roundsSummary(let encounterUUID): projectionPath(encounterUUID, suffix: "rounds/summary")
        case .careTeam(let encounterUUID): projectionPath(encounterUUID, suffix: "care-team")
        case .messageTopics(let encounterUUID): projectionPath(encounterUUID, suffix: "message-topics")
        case .messageThreads(let encounterUUID), .createMessageThread(let encounterUUID):
            projectionPath(encounterUUID, suffix: "threads")
        case .educationClarification(let encounterUUID, let educationItemUUID):
            educationClarificationPath(encounterUUID, educationItemUUID: educationItemUUID)
        case .messageThread(let threadUUID): threadPath(threadUUID)
        case .sendMessage(let threadUUID): threadPath(threadUUID, suffix: "messages")
        case .amendMessage(let threadUUID, let messageUUID): amendMessagePath(threadUUID, messageUUID: messageUUID)
        case .closeMessageThread(let threadUUID): threadPath(threadUUID, suffix: "close")
        }
    }

    private var hasValidParameters: Bool {
        switch self {
        case .today(let encounterUUID), .pathway(let encounterUUID), .pathwayEvents(let encounterUUID), .dischargeReadiness(let encounterUUID), .roundsSummary(let encounterUUID), .careTeam(let encounterUUID),
             .messageTopics(let encounterUUID), .messageThreads(let encounterUUID),
             .createMessageThread(let encounterUUID):
            return UUID(uuidString: encounterUUID) != nil
        case .educationClarification(let encounterUUID, let educationItemUUID):
            return UUID(uuidString: encounterUUID) != nil && UUID(uuidString: educationItemUUID) != nil
        case .messageThread(let threadUUID), .sendMessage(let threadUUID),
             .closeMessageThread(let threadUUID):
            return UUID(uuidString: threadUUID) != nil
        case .amendMessage(let threadUUID, let messageUUID):
            return UUID(uuidString: threadUUID) != nil && UUID(uuidString: messageUUID) != nil
        case .revokeSession(let sessionUUID):
            return UUID(uuidString: sessionUUID) != nil
        default:
            return true
        }
    }

    private func projectionPath(_ encounterUUID: String, suffix: String) -> String {
        guard UUID(uuidString: encounterUUID) != nil else { return "invalid-encounter-identifier" }
        return "encounters/\(encounterUUID.lowercased())/\(suffix)"
    }

    private func threadPath(_ threadUUID: String, suffix: String? = nil) -> String {
        guard UUID(uuidString: threadUUID) != nil else { return "invalid-thread-identifier" }
        let base = "threads/\(threadUUID.lowercased())"
        return suffix.map { base + "/" + $0 } ?? base
    }

    private func amendMessagePath(_ threadUUID: String, messageUUID: String) -> String {
        guard UUID(uuidString: threadUUID) != nil,
              UUID(uuidString: messageUUID) != nil else {
            return "invalid-message-identifier"
        }
        return threadPath(
            threadUUID,
            suffix: "messages/\(messageUUID.lowercased())/amend"
        )
    }

    private func educationClarificationPath(_ encounterUUID: String, educationItemUUID: String) -> String {
        guard UUID(uuidString: encounterUUID) != nil,
              UUID(uuidString: educationItemUUID) != nil else {
            return "invalid-education-identifier"
        }
        return "encounters/\(encounterUUID.lowercased())/education/\(educationItemUUID.lowercased())/clarifications"
    }

    private func sessionPath(_ sessionUUID: String) -> String {
        guard UUID(uuidString: sessionUUID) != nil else { return "invalid-session-identifier" }
        return "me/sessions/\(sessionUUID.lowercased())"
    }
}

protocol PatientAPIService: Sendable {
    func signIn(email: String, password: String, device: PatientDeviceDescriptor) async throws -> PatientTokenPair
    func enroll(_ input: PatientEnrollmentInput, device: PatientDeviceDescriptor) async throws -> PatientTokenPair
    func refresh(refreshToken: String) async throws -> PatientTokenPair
    func revoke(token: String) async throws
    func profile(accessToken: String) async throws -> PatientEnvelope<PatientProfile>
    func updatePreferences(
        _ input: PatientPreferencesInput,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientProfile>
    func sessions(accessToken: String) async throws -> PatientEnvelope<PatientSessionCollection>
    func revokeSession(
        sessionUUID: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientSessionRevocationResult>
    func encounters(accessToken: String) async throws -> PatientEnvelope<PatientEncounterCollection>
    func today(encounterUUID: String, accessToken: String) async throws -> PatientEnvelope<PatientProjectionData<PatientTodayContent>>
    func pathway(encounterUUID: String, accessToken: String) async throws -> PatientEnvelope<PatientProjectionData<PatientPathwayContent>>
    func pathwayEvents(encounterUUID: String, accessToken: String) async throws -> PatientEnvelope<PatientProjectionData<PatientPathwayEventsContent>>
    func dischargeReadiness(encounterUUID: String, accessToken: String) async throws -> PatientEnvelope<PatientProjectionData<PatientDischargeReadinessContent>>
    func roundsSummary(encounterUUID: String, accessToken: String) async throws -> PatientEnvelope<PatientProjectionData<PatientRoundsSummaryContent>>
    func careTeam(encounterUUID: String, accessToken: String) async throws -> PatientEnvelope<PatientProjectionData<PatientCareTeamContent>>
    func messageTopics(encounterUUID: String, accessToken: String) async throws -> PatientEnvelope<PatientMessageTopicsResult>
    func messageThreads(encounterUUID: String, accessToken: String) async throws -> PatientEnvelope<PatientMessageThreadListResult>
    func createMessageThread(
        encounterUUID: String,
        input: PatientMessageThreadCreateInput,
        idempotencyKey: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageThreadMutationResult>
    func requestEducationClarification(
        encounterUUID: String,
        educationItemUUID: String,
        input: PatientEducationClarificationInput,
        idempotencyKey: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageThreadMutationResult>
    func messageThread(threadUUID: String, accessToken: String) async throws -> PatientEnvelope<PatientMessageThreadDetailResult>
    func sendMessage(
        threadUUID: String,
        input: PatientMessageCreateInput,
        idempotencyKey: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageMutationResult>
    func amendMessage(
        threadUUID: String,
        messageUUID: String,
        input: PatientMessageAmendmentInput,
        idempotencyKey: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageMutationResult>
    func closeMessageThread(
        threadUUID: String,
        input: PatientMessageThreadCloseInput,
        idempotencyKey: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageThreadMutationResult>
}

extension PatientAPIService {
    func requestEducationClarification(
        encounterUUID: String,
        educationItemUUID: String,
        input: PatientEducationClarificationInput,
        idempotencyKey: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageThreadMutationResult> {
        throw PatientAPIError.notConfigured
    }
}

enum PatientAPIError: Error, Equatable {
    case notConfigured
    case invalidBoundary
    case invalidResponse
    case transport
    case unauthorized(code: String, message: String)
    case notFound
    case server(statusCode: Int, code: String, message: String)
}

final class PatientAPIClient: PatientAPIService, @unchecked Sendable {
    private let baseURL: URL
    private let session: URLSession
    private let encoder: JSONEncoder
    private let decoder: JSONDecoder

    init(baseURL: URL) {
        precondition(
            PatientAPIBoundary.validatedBaseURL(baseURL.absoluteString) != nil,
            "Hummingbird Patient rejected an unsafe API origin."
        )
        self.baseURL = baseURL
        self.session = PatientURLSessionFactory.ephemeral()
        self.encoder = JSONEncoder()
        self.decoder = JSONDecoder()
    }

#if DEBUG
    /// Debug-only injection seam for deterministic protocol tests. Release app
    /// code cannot replace the governed no-redirect URLSession.
    init(baseURL: URL, session: URLSession) {
        precondition(
            PatientAPIBoundary.validatedBaseURL(baseURL.absoluteString) != nil,
            "Hummingbird Patient rejected an unsafe API origin."
        )
        self.baseURL = baseURL
        self.session = session
        self.encoder = JSONEncoder()
        self.decoder = JSONDecoder()
    }
#endif

    func signIn(
        email: String,
        password: String,
        device: PatientDeviceDescriptor
    ) async throws -> PatientTokenPair {
        let body = SignInRequest(email: email, password: password, device: device)
        let envelope: PatientEnvelope<PatientTokenPair> = try await send(
            .token,
            body: encoder.encode(body)
        )
        return try validatedPatientTokenPair(envelope.data)
    }

    func enroll(
        _ input: PatientEnrollmentInput,
        device: PatientDeviceDescriptor
    ) async throws -> PatientTokenPair {
        let body = EnrollmentRequest(
            challengeUUID: input.challengeUUID,
            challengeToken: input.challengeToken,
            verificationCode: input.verificationCode,
            displayName: input.displayName,
            email: input.email.trimmingCharacters(in: .whitespacesAndNewlines).lowercased(),
            password: input.password,
            passwordConfirmation: input.passwordConfirmation,
            device: device
        )
        let envelope: PatientEnvelope<PatientTokenPair> = try await send(
            .enroll,
            body: encoder.encode(body)
        )
        return try validatedPatientTokenPair(envelope.data)
    }

    func refresh(refreshToken: String) async throws -> PatientTokenPair {
        let envelope: PatientEnvelope<PatientTokenPair> = try await send(
            .refresh,
            bearerToken: refreshToken
        )
        return try validatedPatientTokenPair(envelope.data)
    }

    func revoke(token: String) async throws {
        let _: PatientEnvelope<PatientRevocationResult> = try await send(
            .revoke,
            bearerToken: token
        )
    }

    func profile(accessToken: String) async throws -> PatientEnvelope<PatientProfile> {
        try await send(.profile, bearerToken: accessToken)
    }

    func updatePreferences(
        _ input: PatientPreferencesInput,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientProfile> {
        try await send(
            .preferences,
            body: encoder.encode(input),
            bearerToken: accessToken
        )
    }

    func encounters(accessToken: String) async throws -> PatientEnvelope<PatientEncounterCollection> {
        try await send(.encounters, bearerToken: accessToken)
    }

    func sessions(accessToken: String) async throws -> PatientEnvelope<PatientSessionCollection> {
        let response: PatientEnvelope<PatientSessionCollection> = try await send(
            .sessions,
            bearerToken: accessToken
        )
        try validateSessions(response.data.sessions)
        return response
    }

    func revokeSession(
        sessionUUID: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientSessionRevocationResult> {
        let response: PatientEnvelope<PatientSessionRevocationResult> = try await send(
            .revokeSession(sessionUUID: sessionUUID),
            bearerToken: accessToken
        )
        guard response.data.revoked,
              UUID(uuidString: response.data.sessionUUID) != nil,
              response.data.sessionUUID.caseInsensitiveCompare(sessionUUID) == .orderedSame
        else { throw PatientAPIError.invalidResponse }
        return response
    }

    func today(
        encounterUUID: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientProjectionData<PatientTodayContent>> {
        try await send(.today(encounterUUID: encounterUUID), bearerToken: accessToken)
    }

    func pathway(
        encounterUUID: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientProjectionData<PatientPathwayContent>> {
        try await send(.pathway(encounterUUID: encounterUUID), bearerToken: accessToken)
    }

    func pathwayEvents(
        encounterUUID: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientProjectionData<PatientPathwayEventsContent>> {
        try await send(.pathwayEvents(encounterUUID: encounterUUID), bearerToken: accessToken)
    }

    func dischargeReadiness(
        encounterUUID: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientProjectionData<PatientDischargeReadinessContent>> {
        try await send(.dischargeReadiness(encounterUUID: encounterUUID), bearerToken: accessToken)
    }

    func roundsSummary(
        encounterUUID: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientProjectionData<PatientRoundsSummaryContent>> {
        try await send(.roundsSummary(encounterUUID: encounterUUID), bearerToken: accessToken)
    }

    func careTeam(
        encounterUUID: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientProjectionData<PatientCareTeamContent>> {
        try await send(.careTeam(encounterUUID: encounterUUID), bearerToken: accessToken)
    }

    func messageTopics(
        encounterUUID: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageTopicsResult> {
        try await send(.messageTopics(encounterUUID: encounterUUID), bearerToken: accessToken)
    }

    func messageThreads(
        encounterUUID: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageThreadListResult> {
        try await send(.messageThreads(encounterUUID: encounterUUID), bearerToken: accessToken)
    }

    func createMessageThread(
        encounterUUID: String,
        input: PatientMessageThreadCreateInput,
        idempotencyKey: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageThreadMutationResult> {
        guard isValidMessage(input.message),
              UUID(uuidString: input.clientMessageUUID) != nil,
              !input.topicCode.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty,
              !input.urgentGuidanceVersion.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        else { throw PatientAPIError.invalidBoundary }
        return try await send(
            .createMessageThread(encounterUUID: encounterUUID),
            body: encoder.encode(input),
            bearerToken: accessToken,
            idempotencyKey: idempotencyKey
        )
    }

    func requestEducationClarification(
        encounterUUID: String,
        educationItemUUID: String,
        input: PatientEducationClarificationInput,
        idempotencyKey: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageThreadMutationResult> {
        guard UUID(uuidString: educationItemUUID) != nil,
              isValidMessage(input.message),
              UUID(uuidString: input.clientMessageUUID) != nil,
              !input.urgentGuidanceVersion.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        else { throw PatientAPIError.invalidBoundary }
        return try await send(
            .educationClarification(
                encounterUUID: encounterUUID,
                educationItemUUID: educationItemUUID
            ),
            body: encoder.encode(input),
            bearerToken: accessToken,
            idempotencyKey: idempotencyKey
        )
    }

    func messageThread(
        threadUUID: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageThreadDetailResult> {
        try await send(.messageThread(threadUUID: threadUUID), bearerToken: accessToken)
    }

    func sendMessage(
        threadUUID: String,
        input: PatientMessageCreateInput,
        idempotencyKey: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageMutationResult> {
        guard isValidMessage(input.message),
              UUID(uuidString: input.clientMessageUUID) != nil,
              input.threadVersion >= 1,
              !input.urgentGuidanceVersion.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        else { throw PatientAPIError.invalidBoundary }
        return try await send(
            .sendMessage(threadUUID: threadUUID),
            body: encoder.encode(input),
            bearerToken: accessToken,
            idempotencyKey: idempotencyKey
        )
    }

    func amendMessage(
        threadUUID: String,
        messageUUID: String,
        input: PatientMessageAmendmentInput,
        idempotencyKey: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageMutationResult> {
        guard UUID(uuidString: messageUUID) != nil,
              UUID(uuidString: input.clientMessageUUID) != nil,
              input.threadVersion >= 1,
              !input.urgentGuidanceVersion.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        else { throw PatientAPIError.invalidBoundary }

        switch input.action {
        case .correction:
            guard let message = input.message, isValidMessage(message) else {
                throw PatientAPIError.invalidBoundary
            }
        case .retraction:
            guard input.message == nil else { throw PatientAPIError.invalidBoundary }
        }

        return try await send(
            .amendMessage(threadUUID: threadUUID, messageUUID: messageUUID),
            body: encoder.encode(input),
            bearerToken: accessToken,
            idempotencyKey: idempotencyKey
        )
    }

    func closeMessageThread(
        threadUUID: String,
        input: PatientMessageThreadCloseInput,
        idempotencyKey: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageThreadMutationResult> {
        guard input.threadVersion >= 1 else { throw PatientAPIError.invalidBoundary }
        return try await send(
            .closeMessageThread(threadUUID: threadUUID),
            body: encoder.encode(input),
            bearerToken: accessToken,
            idempotencyKey: idempotencyKey
        )
    }

    private func send<Response: Decodable>(
        _ endpoint: PatientAPIEndpoint,
        body: Data? = nil,
        bearerToken: String? = nil,
        idempotencyKey: String? = nil
    ) async throws -> Response {
        let url = try endpoint.url(relativeTo: baseURL)
        var request = URLRequest(url: url)
        request.httpMethod = endpoint.method
        request.httpBody = body
        request.cachePolicy = .reloadIgnoringLocalAndRemoteCacheData
        request.timeoutInterval = 20
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("no-store", forHTTPHeaderField: "Cache-Control")
        request.setValue("no-cache", forHTTPHeaderField: "Pragma")
        if let bearerToken {
            request.setValue("Bearer \(bearerToken)", forHTTPHeaderField: "Authorization")
        }
        if let idempotencyKey {
            guard UUID(uuidString: idempotencyKey) != nil else {
                throw PatientAPIError.invalidBoundary
            }
            request.setValue(idempotencyKey.lowercased(), forHTTPHeaderField: "Idempotency-Key")
        }

        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await session.data(for: request)
        } catch {
            throw PatientAPIError.transport
        }

        guard let http = response as? HTTPURLResponse else {
            throw PatientAPIError.invalidResponse
        }
        guard (200 ... 299).contains(http.statusCode) else {
            let failure = try? decoder.decode(PatientAPIErrorEnvelope.self, from: data)
            let code = failure?.error.code ?? "patient_service_unavailable"
            let message = failure?.error.message ?? "The patient service is temporarily unavailable."

            switch http.statusCode {
            case 401:
                throw PatientAPIError.unauthorized(code: code, message: message)
            case 404:
                throw PatientAPIError.notFound
            default:
                throw PatientAPIError.server(statusCode: http.statusCode, code: code, message: message)
            }
        }

        do {
            return try decoder.decode(Response.self, from: data)
        } catch {
            throw PatientAPIError.invalidResponse
        }
    }

    private func validatedPatientTokenPair(_ pair: PatientTokenPair) throws -> PatientTokenPair {
        guard pair.tokenType.caseInsensitiveCompare("Bearer") == .orderedSame,
              !pair.accessToken.isEmpty,
              !pair.refreshToken.isEmpty,
              pair.expiresIn > 0,
              UUID(uuidString: pair.sessionUUID) != nil,
              pair.abilities == ["patient:access"]
        else { throw PatientAPIError.invalidResponse }
        return pair
    }

    private func isValidMessage(_ message: String) -> Bool {
        let trimmed = message.trimmingCharacters(in: .whitespacesAndNewlines)
        return (1 ... 2_000).contains(trimmed.count)
    }

    private func validateSessions(_ sessions: [PatientSessionSummary]) throws {
        guard sessions.count <= 100,
              sessions.filter(\.current).count == 1,
              Set(sessions.map { $0.sessionUUID.lowercased() }).count == sessions.count,
              sessions.allSatisfy({ session in
                  UUID(uuidString: session.sessionUUID) != nil
                      && session.status == .active
                      && session.assuranceLevel.map { $0.count <= 32 } ?? true
                      && session.device.uuid.map { UUID(uuidString: $0) != nil } ?? true
                      && session.device.name.map { $0.count <= 190 } ?? true
                      && session.device.appVersion.map { $0.count <= 80 } ?? true
                      && session.device.osVersion.map { $0.count <= 80 } ?? true
                      && session.lastSeenDate != nil
                      && session.expiresDate != nil
                      && session.createdDate != nil
              })
        else { throw PatientAPIError.invalidResponse }
    }
}

enum PatientURLSessionFactory {
    static func ephemeral() -> URLSession {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.requestCachePolicy = .reloadIgnoringLocalAndRemoteCacheData
        configuration.urlCache = nil
        configuration.urlCredentialStorage = nil
        configuration.httpCookieStorage = nil
        configuration.httpShouldSetCookies = false
        return URLSession(
            configuration: configuration,
            delegate: PatientNoRedirectDelegate(),
            delegateQueue: nil
        )
    }
}

/// Patient credentials and identifiers must never be replayed at a redirect
/// target, even when that target shares the configured origin.
final class PatientNoRedirectDelegate: NSObject, URLSessionTaskDelegate, @unchecked Sendable {
    func urlSession(
        _ session: URLSession,
        task: URLSessionTask,
        willPerformHTTPRedirection response: HTTPURLResponse,
        newRequest request: URLRequest,
        completionHandler: @escaping (URLRequest?) -> Void
    ) {
        completionHandler(nil)
    }
}

private struct SignInRequest: Encodable {
    let email: String
    let password: String
    let device: PatientDeviceDescriptor
}

private struct EnrollmentRequest: Encodable {
    let challengeUUID: String
    let challengeToken: String
    let verificationCode: String
    let displayName: String
    let email: String
    let password: String
    let passwordConfirmation: String
    let device: PatientDeviceDescriptor

    enum CodingKeys: String, CodingKey {
        case challengeUUID = "challenge_uuid"
        case challengeToken = "challenge_token"
        case verificationCode = "verification_code"
        case displayName = "display_name"
        case email
        case password
        case passwordConfirmation = "password_confirmation"
        case device
    }
}

private struct PatientAPIErrorEnvelope: Decodable {
    let error: Detail

    struct Detail: Decodable {
        let code: String
        let message: String
    }
}
