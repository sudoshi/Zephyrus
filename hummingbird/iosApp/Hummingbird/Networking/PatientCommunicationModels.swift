import Foundation

// DTOs for accountable staff patient-communications routes in
// hummingbird-bff.v1.yaml. Routing-policy plumbing remains outside the primary
// work-item DTO. Its opaque pool UUID is retained only long enough to bind a
// successful reroute projection to the exact explicitly selected destination;
// it is never rendered, logged, or persisted.

struct PatientCommunicationInboxData: Decodable, Equatable {
    let items: [PatientCommunicationWorkItem]
    let count: Int
}

struct PatientCommunicationWorkItem: Decodable, Equatable, Identifiable {
    let workItemUuid: String
    let threadUuid: String
    let patientContextRef: String?
    let topic: PatientCommunicationTopic
    let unit: PatientCommunicationUnit?
    let pool: PatientCommunicationPool
    let status: String
    let ownershipState: String
    let assignedToMe: Bool
    let workItemVersion: Int
    let threadVersion: Int
    let lastMessageAt: String
    let dueAt: String
    let escalateAt: String
    let isResponseDue: Bool
    let isEscalationDue: Bool
    let closedAt: String?
    let messages: [PatientCommunicationMessage]?
    let hasEarlierMessages: Bool?

    var id: String { workItemUuid }
    var isOpen: Bool { status == "open" }
    var canClaim: Bool {
        isOpen && !assignedToMe && ["pool_owned", "rerouted", "escalated"].contains(ownershipState)
    }
    var canReply: Bool { isOpen && assignedToMe }
    var canClose: Bool { canReply && ownershipState == "responded" }
}

struct PatientCommunicationTopic: Decodable, Equatable {
    let code: String
    let label: String
}

struct PatientCommunicationUnit: Decodable, Equatable {
    let id: Int
    let label: String
}

struct PatientCommunicationPool: Decodable, Equatable {
    // Used only to bind a confirmed reroute projection to the exact selected
    // opaque target. It is never rendered, logged, or persisted.
    let poolUuid: String?
    let label: String

    init(poolUuid: String? = nil, label: String) {
        self.poolUuid = poolUuid
        self.label = label
    }
}

struct PatientCommunicationMessage: Decodable, Equatable, Identifiable {
    let messageUuid: String
    let senderDisplayRole: String
    let visibility: String
    let messageKind: String
    let body: String?
    let deliveryState: String
    let sentAt: String?

    var id: String { messageUuid }
    var isPatientVisible: Bool { visibility == "patient_visible" }
    var isFromPatient: Bool {
        senderDisplayRole == "Patient" || senderDisplayRole == "Representative"
    }
}

struct PatientCommunicationMutationData: Decodable, Equatable {
    // Normal mutations return a verified projection. A content-minimized exact
    // replay of an already-committed cross-pool reroute returns null because the
    // original actor may no longer be authorized to read the destination state.
    let workItem: PatientCommunicationWorkItem?
    let message: PatientCommunicationMessage?
    let eventUuid: String?
    let replayed: Bool
}

enum PatientCommunicationRoutingAction: String, CaseIterable, Identifiable {
    case release
    case reassign
    case reroute

    var id: String { rawValue }

    var label: String {
        switch self {
        case .release: return "Release to team"
        case .reassign: return "Reassign owner"
        case .reroute: return "Reroute team"
        }
    }

    fileprivate var allowedReasonCodes: Set<String> {
        switch self {
        case .release:
            return ["return_to_team", "shift_handoff", "responder_unavailable", "incorrect_assignment"]
        case .reassign:
            return ["supervisor_assignment", "shift_handoff", "coverage_change", "workload_balance"]
        case .reroute:
            return ["wrong_team", "unit_transfer", "service_change", "specialty_needed"]
        }
    }

    func allows(reasonCode: String) -> Bool {
        allowedReasonCodes.contains(reasonCode)
    }
}

struct PatientCommunicationRoutingActions: Decodable, Equatable {
    let canRelease: Bool
    let canReassign: Bool
    let canReroute: Bool

    func allows(_ action: PatientCommunicationRoutingAction) -> Bool {
        switch action {
        case .release: return canRelease
        case .reassign: return canReassign
        case .reroute: return canReroute
        }
    }
}

struct PatientCommunicationRoutingReasonOption: Equatable, Identifiable {
    let action: PatientCommunicationRoutingAction
    let code: String
    let label: String

    var id: String { "\(action.rawValue):\(code)" }
}

struct PatientCommunicationRoutingReasonOptions: Equatable {
    let release: [PatientCommunicationRoutingReasonOption]
    let reassign: [PatientCommunicationRoutingReasonOption]
    let reroute: [PatientCommunicationRoutingReasonOption]

    func options(for action: PatientCommunicationRoutingAction) -> [PatientCommunicationRoutingReasonOption] {
        switch action {
        case .release: return release
        case .reassign: return reassign
        case .reroute: return reroute
        }
    }
}

enum PatientCommunicationMembershipRole: String, Equatable {
    case responder
    case triage
    case supervisor

    var label: String {
        switch self {
        case .responder: return "Responder"
        case .triage: return "Triage"
        case .supervisor: return "Supervisor"
        }
    }
}

struct PatientCommunicationReassignCandidate: Equatable, Identifiable {
    let membershipUuid: String
    let label: String
    let membershipRole: PatientCommunicationMembershipRole

    var id: String { membershipUuid }
}

enum PatientCommunicationPoolScope: String, Equatable {
    case unit
    case facility
    case enterprise

    var label: String {
        switch self {
        case .unit: return "Unit team"
        case .facility: return "Facility team"
        case .enterprise: return "Enterprise team"
        }
    }
}

struct PatientCommunicationRoutingUnit: Equatable {
    // The numeric unit id is intentionally not retained. Candidate selection is
    // always by the opaque pool UUID; this bounded label is display copy only.
    let label: String
}

struct PatientCommunicationRerouteCandidate: Equatable, Identifiable {
    let poolUuid: String
    let label: String
    let scopeType: PatientCommunicationPoolScope
    let unit: PatientCommunicationRoutingUnit?

    var id: String { poolUuid }
}

/// A short-lived, server-authorized selector surface. Its custom decoder applies
/// strict bounds and allowlists before any option can become actionable.
struct PatientCommunicationRouteCandidatesData: Decodable, Equatable {
    let workItemUuid: String
    let workItemVersion: Int
    let threadVersion: Int
    let actions: PatientCommunicationRoutingActions
    let reasonOptions: PatientCommunicationRoutingReasonOptions
    let reassignCandidates: [PatientCommunicationReassignCandidate]
    let rerouteCandidates: [PatientCommunicationRerouteCandidate]

    private enum CodingKeys: String, CodingKey {
        case workItemUuid
        case workItemVersion
        case threadVersion
        case actions
        case reasonOptions
        case reassignCandidates
        case rerouteCandidates
    }

    private struct RawActions: Decodable {
        let canRelease: Bool
        let canReassign: Bool
        let canReroute: Bool

        private enum CodingKeys: String, CodingKey, CaseIterable {
            case canRelease
            case canReassign
            case canReroute
        }

        init(from decoder: Decoder) throws {
            try patientCommunicationRequireExactKeys(decoder, CodingKeys.allCases.map(\.rawValue))
            let container = try decoder.container(keyedBy: CodingKeys.self)
            canRelease = try container.decode(Bool.self, forKey: .canRelease)
            canReassign = try container.decode(Bool.self, forKey: .canReassign)
            canReroute = try container.decode(Bool.self, forKey: .canReroute)
        }
    }

    private struct RawReasonOptions: Decodable {
        let release: [RawReason]
        let reassign: [RawReason]
        let reroute: [RawReason]

        private enum CodingKeys: String, CodingKey, CaseIterable {
            case release
            case reassign
            case reroute
        }

        init(from decoder: Decoder) throws {
            try patientCommunicationRequireExactKeys(decoder, CodingKeys.allCases.map(\.rawValue))
            let container = try decoder.container(keyedBy: CodingKeys.self)
            release = try container.decode([RawReason].self, forKey: .release)
            reassign = try container.decode([RawReason].self, forKey: .reassign)
            reroute = try container.decode([RawReason].self, forKey: .reroute)
        }
    }

    private struct RawReason: Decodable {
        let code: String
        let label: String

        private enum CodingKeys: String, CodingKey, CaseIterable {
            case code
            case label
        }

        init(from decoder: Decoder) throws {
            try patientCommunicationRequireExactKeys(decoder, CodingKeys.allCases.map(\.rawValue))
            let container = try decoder.container(keyedBy: CodingKeys.self)
            code = try container.decode(String.self, forKey: .code)
            label = try container.decode(String.self, forKey: .label)
        }
    }

    private struct RawReassignCandidate: Decodable {
        let membershipUuid: String
        let label: String
        let membershipRole: String

        private enum CodingKeys: String, CodingKey, CaseIterable {
            case membershipUuid
            case label
            case membershipRole
        }

        init(from decoder: Decoder) throws {
            try patientCommunicationRequireExactKeys(decoder, CodingKeys.allCases.map(\.rawValue))
            let container = try decoder.container(keyedBy: CodingKeys.self)
            membershipUuid = try container.decode(String.self, forKey: .membershipUuid)
            label = try container.decode(String.self, forKey: .label)
            membershipRole = try container.decode(String.self, forKey: .membershipRole)
        }
    }

    private struct RawRerouteCandidate: Decodable {
        let poolUuid: String
        let label: String
        let scopeType: String
        let unit: RawUnit?

        private enum CodingKeys: String, CodingKey, CaseIterable {
            case poolUuid
            case label
            case scopeType
            case unit
        }

        init(from decoder: Decoder) throws {
            try patientCommunicationRequireExactKeys(decoder, CodingKeys.allCases.map(\.rawValue))
            let container = try decoder.container(keyedBy: CodingKeys.self)
            poolUuid = try container.decode(String.self, forKey: .poolUuid)
            label = try container.decode(String.self, forKey: .label)
            scopeType = try container.decode(String.self, forKey: .scopeType)
            unit = try container.decodeIfPresent(RawUnit.self, forKey: .unit)
        }
    }

    private struct RawUnit: Decodable {
        // Decode the governed shape but deliberately discard the numeric id.
        let id: Int
        let label: String

        private enum CodingKeys: String, CodingKey, CaseIterable {
            case id
            case label
        }

        init(from decoder: Decoder) throws {
            try patientCommunicationRequireExactKeys(decoder, CodingKeys.allCases.map(\.rawValue))
            let container = try decoder.container(keyedBy: CodingKeys.self)
            id = try container.decode(Int.self, forKey: .id)
            label = try container.decode(String.self, forKey: .label)
        }
    }

    init(from decoder: Decoder) throws {
        try patientCommunicationRequireExactKeys(decoder, [
            "workItemUuid",
            "workItemVersion",
            "threadVersion",
            "actions",
            "reasonOptions",
            "reassignCandidates",
            "rerouteCandidates",
        ])
        let container = try decoder.container(keyedBy: CodingKeys.self)
        let rawWorkItemUUID = try container.decode(String.self, forKey: .workItemUuid)
        guard let canonicalWorkItemUUID = Self.canonicalUUID(rawWorkItemUUID) else {
            throw DecodingError.dataCorruptedError(
                forKey: .workItemUuid,
                in: container,
                debugDescription: "work_item_uuid must be a canonical lowercase UUID"
            )
        }

        let workItemVersion = try container.decode(Int.self, forKey: .workItemVersion)
        let threadVersion = try container.decode(Int.self, forKey: .threadVersion)
        guard workItemVersion > 0, threadVersion > 0 else {
            throw DecodingError.dataCorruptedError(
                forKey: .workItemVersion,
                in: container,
                debugDescription: "Routing versions must be positive"
            )
        }

        let rawActions = try container.decode(RawActions.self, forKey: .actions)
        let rawReasons = try container.decode(RawReasonOptions.self, forKey: .reasonOptions)
        let rawReassign = try container.decode([RawReassignCandidate].self, forKey: .reassignCandidates)
        let rawReroute = try container.decode([RawRerouteCandidate].self, forKey: .rerouteCandidates)

        guard rawReasons.release.count <= 12,
              rawReasons.reassign.count <= 12,
              rawReasons.reroute.count <= 12,
              rawReassign.count <= 50,
              rawReroute.count <= 50 else {
            throw DecodingError.dataCorruptedError(
                forKey: .reasonOptions,
                in: container,
                debugDescription: "Routing option bounds exceeded"
            )
        }

        let releaseReasons = try Self.validReasons(
            rawReasons.release,
            action: .release,
            codingPath: decoder.codingPath
        )
        let reassignReasons = try Self.validReasons(
            rawReasons.reassign,
            action: .reassign,
            codingPath: decoder.codingPath
        )
        let rerouteReasons = try Self.validReasons(
            rawReasons.reroute,
            action: .reroute,
            codingPath: decoder.codingPath
        )

        let reassignCandidates = try rawReassign.map { raw in
            guard let membershipUuid = Self.canonicalUUID(raw.membershipUuid),
                  let label = Self.boundedLabel(raw.label),
                  let role = PatientCommunicationMembershipRole(rawValue: raw.membershipRole) else {
                throw Self.corrupted("Invalid reassign candidate", codingPath: decoder.codingPath)
            }
            return PatientCommunicationReassignCandidate(
                membershipUuid: membershipUuid,
                label: label,
                membershipRole: role
            )
        }
        try Self.requireUnique(
            reassignCandidates,
            id: \PatientCommunicationReassignCandidate.membershipUuid,
            codingPath: decoder.codingPath
        )

        let rerouteCandidates = try rawReroute.map { raw in
            guard let poolUuid = Self.canonicalUUID(raw.poolUuid),
                  let label = Self.boundedLabel(raw.label),
                  let scope = PatientCommunicationPoolScope(rawValue: raw.scopeType) else {
                throw Self.corrupted("Invalid reroute candidate", codingPath: decoder.codingPath)
            }
            let unit: PatientCommunicationRoutingUnit?
            if let rawUnit = raw.unit {
                guard rawUnit.id > 0, let unitLabel = Self.boundedLabel(rawUnit.label) else {
                    throw Self.corrupted("Invalid reroute unit", codingPath: decoder.codingPath)
                }
                unit = PatientCommunicationRoutingUnit(label: unitLabel)
            } else {
                unit = nil
            }
            guard (scope == .unit && unit != nil) || (scope != .unit && unit == nil) else {
                throw Self.corrupted("Reroute scope and unit are inconsistent", codingPath: decoder.codingPath)
            }
            return PatientCommunicationRerouteCandidate(
                poolUuid: poolUuid,
                label: label,
                scopeType: scope,
                unit: unit
            )
        }
        try Self.requireUnique(
            rerouteCandidates,
            id: \PatientCommunicationRerouteCandidate.poolUuid,
            codingPath: decoder.codingPath
        )

        guard (!rawActions.canRelease || !releaseReasons.isEmpty),
              (!rawActions.canReassign || (!reassignReasons.isEmpty && !reassignCandidates.isEmpty)),
              (!rawActions.canReroute || (!rerouteReasons.isEmpty && !rerouteCandidates.isEmpty)),
              (rawActions.canReassign || reassignCandidates.isEmpty),
              (rawActions.canReroute || rerouteCandidates.isEmpty) else {
            throw Self.corrupted("Routing actions and candidate options are inconsistent", codingPath: decoder.codingPath)
        }

        self.workItemUuid = canonicalWorkItemUUID
        self.workItemVersion = workItemVersion
        self.threadVersion = threadVersion
        self.reasonOptions = PatientCommunicationRoutingReasonOptions(
            release: releaseReasons,
            reassign: reassignReasons,
            reroute: rerouteReasons
        )
        self.reassignCandidates = reassignCandidates
        self.rerouteCandidates = rerouteCandidates
        self.actions = PatientCommunicationRoutingActions(
            canRelease: rawActions.canRelease,
            canReassign: rawActions.canReassign,
            canReroute: rawActions.canReroute
        )
    }

    func matches(_ item: PatientCommunicationWorkItem) -> Bool {
        workItemUuid == item.workItemUuid
            && workItemVersion == item.workItemVersion
            && threadVersion == item.threadVersion
    }

    func reasons(for action: PatientCommunicationRoutingAction) -> [PatientCommunicationRoutingReasonOption] {
        reasonOptions.options(for: action)
    }

    func containsTarget(_ targetUUID: String?, for action: PatientCommunicationRoutingAction) -> Bool {
        switch action {
        case .release:
            return targetUUID == nil
        case .reassign:
            guard let targetUUID else { return false }
            return reassignCandidates.contains { $0.membershipUuid == targetUUID }
        case .reroute:
            guard let targetUUID else { return false }
            return rerouteCandidates.contains { $0.poolUuid == targetUUID }
        }
    }

    private static func validReasons(
        _ rawReasons: [RawReason],
        action: PatientCommunicationRoutingAction,
        codingPath: [CodingKey]
    ) throws -> [PatientCommunicationRoutingReasonOption] {
        let options = try rawReasons.map { raw -> PatientCommunicationRoutingReasonOption in
            guard action.allows(reasonCode: raw.code), let label = boundedLabel(raw.label) else {
                throw corrupted("Invalid or unknown \(action.rawValue) reason", codingPath: codingPath)
            }
            return PatientCommunicationRoutingReasonOption(action: action, code: raw.code, label: label)
        }
        try requireUnique(options, id: \PatientCommunicationRoutingReasonOption.code, codingPath: codingPath)
        return options
    }

    private static func canonicalUUID(_ raw: String) -> String? {
        let pattern = "^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"
        guard raw.range(of: pattern, options: .regularExpression) != nil,
              let uuid = UUID(uuidString: raw),
              raw == uuid.uuidString.lowercased() else {
            return nil
        }
        return raw
    }

    private static func boundedLabel(_ raw: String) -> String? {
        let label = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !label.isEmpty,
              label.count <= 120,
              label.unicodeScalars.allSatisfy({ !CharacterSet.controlCharacters.contains($0) }) else {
            return nil
        }
        return label
    }

    private static func requireUnique<T, ID: Hashable>(
        _ values: [T],
        id: KeyPath<T, ID>,
        codingPath: [CodingKey]
    ) throws {
        var seen: Set<ID> = []
        guard values.allSatisfy({ seen.insert($0[keyPath: id]).inserted }) else {
            throw corrupted("Duplicate routing option", codingPath: codingPath)
        }
    }

    private static func corrupted(_ description: String, codingPath: [CodingKey]) -> DecodingError {
        DecodingError.dataCorrupted(.init(codingPath: codingPath, debugDescription: description))
    }
}

private struct PatientCommunicationStrictCodingKey: CodingKey {
    let stringValue: String
    let intValue: Int?

    init?(stringValue: String) {
        self.stringValue = stringValue
        intValue = nil
    }

    init?(intValue: Int) {
        stringValue = String(intValue)
        self.intValue = intValue
    }
}

private func patientCommunicationRequireExactKeys(
    _ decoder: Decoder,
    _ expected: [String]
) throws {
    let container = try decoder.container(keyedBy: PatientCommunicationStrictCodingKey.self)
    let actual = Set(container.allKeys.map(\.stringValue))
    let allowed = Set(expected)
    guard actual == allowed else {
        throw DecodingError.dataCorrupted(.init(
            codingPath: decoder.codingPath,
            debugDescription: "Unexpected or missing patient-communication routing fields"
        ))
    }
}

enum PatientCommunicationCloseReason: String, CaseIterable, Identifiable {
    case questionAnswered = "question_answered"
    case patientRequested = "patient_requested"
    case duplicate
    case transferred
    case other

    var id: String { rawValue }

    var label: String {
        switch self {
        case .questionAnswered: return "Question answered"
        case .patientRequested: return "Patient requested closure"
        case .duplicate: return "Duplicate thread"
        case .transferred: return "Transferred to another workflow"
        case .other: return "Other"
        }
    }
}

/// Testable seam for an in-memory-only communications surface. Implementations
/// must not persist responses or drafts and must never enqueue failed writes.
@MainActor
protocol PatientCommunicationsRepository {
    func patientCommunicationsInbox(bearer: String) async throws -> PatientCommunicationInboxData
    func patientCommunicationThread(workItemUUID: String, bearer: String) async throws -> PatientCommunicationWorkItem
    func claimPatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData
    func replyToPatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        message: String,
        clientMessageUUID: UUID,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData
    func closePatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        reasonCode: PatientCommunicationCloseReason,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData
    func patientCommunicationRouteCandidates(
        workItemUUID: String,
        bearer: String
    ) async throws -> PatientCommunicationRouteCandidatesData
    func releasePatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        reasonCode: String,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData
    func reassignPatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        targetMembershipUUID: String,
        reasonCode: String,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData
    func reroutePatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        targetPoolUUID: String,
        reasonCode: String,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData
}

extension APIClient: PatientCommunicationsRepository {}

/// Discoverability is based only on the effective capability emitted by /me.
/// The server remains authoritative and also requires a current responsibility-
/// pool membership for every resource read and mutation.
enum PatientCommunicationsEligibility {
    static func isEligible(_ me: MeData?) -> Bool {
        me?.can.viewPatientCommunications == true
    }
}
