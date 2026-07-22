#if DEBUG
import Foundation

enum StaffCommunicationsUITestMode {
    static var isEnabled: Bool {
        ProcessInfo.processInfo.arguments.contains("-HBStaffCommunicationsUITest")
            && ProcessInfo.processInfo.environment["HB_STAFF_COMM_UI_TEST"] == "1"
    }

    static var scenario: String {
        ProcessInfo.processInfo.environment["HB_STAFF_COMM_UI_SCENARIO"] ?? "open"
    }
}

/// Process-memory fixture used only by the Debug UI-test launch mode. It exercises
/// the production screens and state machine without introducing test credentials,
/// a localhost dependency, disk persistence, or a production demo bypass.
@MainActor
final class PatientCommunicationsUITestRepository: PatientCommunicationsRepository {
    private struct CommittedReply {
        let workItemVersion: Int
        let threadVersion: Int
        let body: String
        let clientMessageUUID: UUID
        let idempotencyKey: UUID
        let eventUUID: String
        let message: PatientCommunicationMessage
    }

    private let workItemUUID = "11111111-1111-4111-8111-111111111111"
    private let threadUUID = "22222222-2222-4222-8222-222222222222"
    private var currentPoolUUID = "99999999-9999-4999-8999-999999999999"
    private var status = "open"
    private var ownershipState = "pool_owned"
    private var assignedToMe = false
    private var workItemVersion = 1
    private var threadVersion = 3
    private var closedAt: String?
    private var committedRerouteReplayKey: UUID?
    private var committedRerouteEventUUID: String?
    private var committedReply: CommittedReply?
    private var inboxLoadsAfterDetail = 0
    private var threadLoads = 0
    private var candidateLoads = 0
    private var detailWasLoaded = false
    private var messages: [PatientCommunicationMessage] = [
        .init(
            messageUuid: "33333333-3333-4333-8333-333333333333",
            senderDisplayRole: "Patient",
            visibility: "patient_visible",
            messageKind: "message",
            body: "Could someone explain what needs to happen before I can go home?",
            deliveryState: "acknowledged",
            sentAt: "2026-07-19T14:05:00Z"
        ),
    ]

    init() {
        if [
            "routing",
            "ambiguous_reroute",
            "ambiguous_reply",
            "inbox_401_detail",
            "inbox_403_detail",
            "inbox_404_detail",
            "inbox_200_empty_detail",
            "thread_403_refresh",
            "thread_404_refresh",
            "candidate_401_refresh",
            "candidate_404_refresh",
        ].contains(StaffCommunicationsUITestMode.scenario) {
            ownershipState = "acknowledged"
            assignedToMe = true
        }
    }

    func patientCommunicationsInbox(bearer: String) async throws -> PatientCommunicationInboxData {
        try await briefDelay()
        let inboxDenialStatus: Int? = switch StaffCommunicationsUITestMode.scenario {
        case "inbox_401_detail": 401
        case "inbox_403_detail": 403
        case "inbox_404_detail": 404
        default: nil
        }
        if let inboxDenialStatus, detailWasLoaded {
            inboxLoadsAfterDetail += 1
            let denialAfterLoads = inboxDenialStatus == 401 ? 8 : 16
            if inboxLoadsAfterDetail >= denialAfterLoads {
                throw APIError(message: "Communications unavailable.", statusCode: inboxDenialStatus)
            }
        }
        if StaffCommunicationsUITestMode.scenario == "inbox_200_empty_detail", detailWasLoaded {
            inboxLoadsAfterDetail += 1
            if inboxLoadsAfterDetail >= 16 {
                return PatientCommunicationInboxData(items: [], count: 0)
            }
        }
        if StaffCommunicationsUITestMode.scenario == "denied" {
            throw APIError(message: "Not found.", statusCode: 404)
        }
        let movedAway = StaffCommunicationsUITestMode.scenario == "ambiguous_reroute"
            && committedRerouteReplayKey != nil
        let items = status == "open" && !movedAway ? [makeItem(includeMessages: false)] : []
        return PatientCommunicationInboxData(items: items, count: items.count)
    }

    func patientCommunicationThread(workItemUUID: String, bearer: String) async throws -> PatientCommunicationWorkItem {
        try await briefDelay()
        threadLoads += 1
        if StaffCommunicationsUITestMode.scenario == "ambiguous_reroute",
           committedRerouteReplayKey != nil {
            throw APIError(message: "Temporarily unavailable.", statusCode: 503)
        }
        if StaffCommunicationsUITestMode.scenario == "thread_403_refresh", threadLoads >= 2 {
            throw APIError(message: "Forbidden.", statusCode: 403)
        }
        if StaffCommunicationsUITestMode.scenario == "thread_404_refresh", threadLoads >= 2 {
            throw APIError(message: "Not found.", statusCode: 404)
        }
        guard StaffCommunicationsUITestMode.scenario != "denied", workItemUUID == self.workItemUUID else {
            throw APIError(message: "Not found.", statusCode: 404)
        }
        detailWasLoaded = true
        return makeItem(includeMessages: true)
    }

    func claimPatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        try await briefDelay()
        try assertCurrent(workItemUUID, workItemVersion, threadVersion)
        assignedToMe = true
        ownershipState = "acknowledged"
        self.workItemVersion += 1
        self.threadVersion += 1
        return mutation(message: nil)
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
        try await briefDelay()
        if let committedReply {
            guard workItemUUID == self.workItemUUID,
                  workItemVersion == committedReply.workItemVersion,
                  threadVersion == committedReply.threadVersion,
                  message == committedReply.body,
                  clientMessageUUID == committedReply.clientMessageUUID,
                  idempotencyKey == committedReply.idempotencyKey else {
                throw APIError(message: "Exact reply tuple changed.", statusCode: 409)
            }
            return PatientCommunicationMutationData(
                workItem: makeItem(includeMessages: false),
                message: committedReply.message,
                eventUuid: committedReply.eventUUID,
                replayed: true
            )
        }
        try assertCurrent(workItemUUID, workItemVersion, threadVersion)
        guard assignedToMe else { throw APIError(message: "Not found.", statusCode: 404) }
        let reply = PatientCommunicationMessage(
            messageUuid: clientMessageUUID.uuidString.lowercased(),
            senderDisplayRole: "Care team",
            visibility: "patient_visible",
            messageKind: "message",
            body: message,
            deliveryState: "delivered",
            sentAt: ISO8601DateFormatter().string(from: Date())
        )
        messages.append(reply)
        ownershipState = "responded"
        self.workItemVersion += 1
        self.threadVersion += 1
        if StaffCommunicationsUITestMode.scenario == "ambiguous_reply" {
            committedReply = CommittedReply(
                workItemVersion: workItemVersion,
                threadVersion: threadVersion,
                body: message,
                clientMessageUUID: clientMessageUUID,
                idempotencyKey: idempotencyKey,
                eventUUID: "77777777-7777-4777-8777-777777777777",
                message: reply
            )
            throw APIError(message: "Service unavailable after commit.", statusCode: 503)
        }
        return mutation(message: reply)
    }

    func closePatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        reasonCode: PatientCommunicationCloseReason,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        try await briefDelay()
        try assertCurrent(workItemUUID, workItemVersion, threadVersion)
        guard assignedToMe, ownershipState == "responded" else {
            throw APIError(message: "A patient-visible response is required.", statusCode: 409)
        }
        status = "closed"
        ownershipState = "closed"
        closedAt = ISO8601DateFormatter().string(from: Date())
        self.workItemVersion += 1
        self.threadVersion += 1
        return mutation(message: nil)
    }

    func patientCommunicationRouteCandidates(
        workItemUUID: String,
        bearer: String
    ) async throws -> PatientCommunicationRouteCandidatesData {
        try await briefDelay()
        candidateLoads += 1
        if StaffCommunicationsUITestMode.scenario == "ambiguous_reroute",
           committedRerouteReplayKey != nil {
            throw APIError(message: "Temporarily unavailable.", statusCode: 503)
        }
        if StaffCommunicationsUITestMode.scenario == "candidate_401_refresh", candidateLoads >= 2 {
            throw APIError(message: "Expired.", statusCode: 401)
        }
        if StaffCommunicationsUITestMode.scenario == "candidate_404_refresh", candidateLoads >= 2 {
            throw APIError(message: "Not found.", statusCode: 404)
        }
        guard StaffCommunicationsUITestMode.scenario != "denied", workItemUUID == self.workItemUUID else {
            throw APIError(message: "Not found.", statusCode: 404)
        }
        let json = """
        {
          "work_item_uuid": "\(self.workItemUUID)",
          "work_item_version": \(workItemVersion),
          "thread_version": \(threadVersion),
          "actions": {
            "can_release": \(assignedToMe && status == "open"),
            "can_reassign": \(status == "open"),
            "can_reroute": \(status == "open")
          },
          "reason_options": {
            "release": [
              {"code": "return_to_team", "label": "Return to team queue"},
              {"code": "shift_handoff", "label": "Shift handoff"}
            ],
            "reassign": [
              {"code": "coverage_change", "label": "Coverage change"},
              {"code": "workload_balance", "label": "Workload balance"}
            ],
            "reroute": [
              {"code": "wrong_team", "label": "Wrong care team"},
              {"code": "unit_transfer", "label": "Unit transfer"}
            ]
          },
          "reassign_candidates": [
            {
              "membership_uuid": "44444444-4444-4444-8444-444444444444",
              "label": "Jordan Lee",
              "membership_role": "responder"
            }
          ],
          "reroute_candidates": [
            {
              "pool_uuid": "55555555-5555-4555-8555-555555555555",
              "label": "6 North care team",
              "scope_type": "unit",
              "unit": {"id": 86, "label": "6 North"}
            }
          ]
        }
        """
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        return try decoder.decode(PatientCommunicationRouteCandidatesData.self, from: Data(json.utf8))
    }

    func releasePatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        reasonCode: String,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        try await briefDelay()
        try assertCurrent(workItemUUID, workItemVersion, threadVersion)
        guard assignedToMe,
              PatientCommunicationRoutingAction.release.allows(reasonCode: reasonCode) else {
            throw APIError(message: "Not found.", statusCode: 404)
        }
        assignedToMe = false
        ownershipState = "pool_owned"
        self.workItemVersion += 1
        self.threadVersion += 1
        return mutation(message: nil)
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
        try await briefDelay()
        try assertCurrent(workItemUUID, workItemVersion, threadVersion)
        guard targetMembershipUUID == "44444444-4444-4444-8444-444444444444",
              PatientCommunicationRoutingAction.reassign.allows(reasonCode: reasonCode) else {
            throw APIError(message: "Not found.", statusCode: 404)
        }
        assignedToMe = false
        ownershipState = "assigned"
        self.workItemVersion += 1
        self.threadVersion += 1
        return mutation(message: nil)
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
        try await briefDelay()
        if let replayKey = committedRerouteReplayKey {
            guard idempotencyKey == replayKey else {
                throw APIError(message: "Replay key changed.", statusCode: 409)
            }
            return PatientCommunicationMutationData(
                workItem: nil,
                message: nil,
                eventUuid: committedRerouteEventUUID,
                replayed: true
            )
        }
        try assertCurrent(workItemUUID, workItemVersion, threadVersion)
        guard targetPoolUUID == "55555555-5555-4555-8555-555555555555",
              PatientCommunicationRoutingAction.reroute.allows(reasonCode: reasonCode) else {
            throw APIError(message: "Not found.", statusCode: 404)
        }
        assignedToMe = false
        ownershipState = "rerouted"
        currentPoolUUID = targetPoolUUID
        self.workItemVersion += 1
        self.threadVersion += 1
        if StaffCommunicationsUITestMode.scenario == "ambiguous_reroute" {
            committedRerouteReplayKey = idempotencyKey
            committedRerouteEventUUID = "66666666-6666-4666-8666-666666666666"
            throw APIError(message: "Service unavailable after commit.", statusCode: 503)
        }
        return mutation(message: nil)
    }

    private func makeItem(includeMessages: Bool) -> PatientCommunicationWorkItem {
        PatientCommunicationWorkItem(
            workItemUuid: workItemUUID,
            threadUuid: threadUUID,
            patientContextRef: "ptok_0123456789abcdef01234567",
            topic: .init(code: "discharge_planning", label: "Discharge planning"),
            unit: .init(id: 85, label: "5 East — Medical/Surgical"),
            pool: .init(poolUuid: currentPoolUUID, label: "5 East care team"),
            status: status,
            ownershipState: ownershipState,
            assignedToMe: assignedToMe,
            workItemVersion: workItemVersion,
            threadVersion: threadVersion,
            lastMessageAt: "2026-07-19T14:05:00Z",
            dueAt: "2026-07-19T15:05:00Z",
            escalateAt: "2026-07-19T16:05:00Z",
            isResponseDue: true,
            isEscalationDue: false,
            closedAt: closedAt,
            messages: includeMessages ? messages : nil,
            hasEarlierMessages: includeMessages ? false : nil
        )
    }

    private func mutation(
        message: PatientCommunicationMessage?,
        replayed: Bool = false
    ) -> PatientCommunicationMutationData {
        PatientCommunicationMutationData(
            workItem: makeItem(includeMessages: false),
            message: message,
            eventUuid: UUID().uuidString.lowercased(),
            replayed: replayed
        )
    }

    private func assertCurrent(_ uuid: String, _ workVersion: Int, _ threadVersion: Int) throws {
        guard uuid == workItemUUID,
              workVersion == workItemVersion,
              threadVersion == self.threadVersion else {
            throw APIError(message: "Changed since load.", statusCode: 409)
        }
    }

    private func briefDelay() async throws {
        try await Task.sleep(for: .milliseconds(40))
    }
}
#endif
