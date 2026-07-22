import Foundation

enum PatientMessagingState: Equatable {
    case notGranted
    case disabled
    case loading
    case ready(PatientMessagingOverview)
    case failed
}

struct PatientMessagingOverview: Equatable {
    let topics: [PatientMessageTopic]
    let threads: [PatientMessageThreadSummary]
    let immediateHelp: PatientImmediateHelpGuidance
}

extension PatientMessageThreadDetail {
    var summary: PatientMessageThreadSummary {
        PatientMessageThreadSummary(
            threadUUID: threadUUID,
            topic: topic,
            status: status,
            ownershipState: ownershipState,
            expectedResponseWindow: expectedResponseWindow,
            version: version,
            lastMessageAt: lastMessageAt,
            createdAt: createdAt,
            closedAt: closedAt,
            closeReason: closeReason
        )
    }
}

extension PatientMessageOwnershipState {
    var patientLabel: String {
        switch self {
        case .awaitingTeam:
            "Waiting for your care team"
        case .assigned:
            "With your care team"
        case .acknowledged:
            "Seen by your care team"
        case .responded:
            "Care team responded"
        case .rerouted:
            "Finding the right care team member"
        case .escalated:
            "Receiving added attention"
        case .closed:
            "Conversation closed"
        }
    }
}

extension PatientMessageThreadCloseReason {
    var patientLabel: String {
        switch self {
        case .questionAnswered:
            "My question was answered"
        case .noLongerNeeded:
            "I no longer need help with this"
        case .createdInError:
            "I created this by mistake"
        case .other:
            "Another reason"
        }
    }
}

extension PatientMessageDeliveryState {
    var patientLabel: String {
        switch self {
        case .sent:
            "Sent"
        case .delivered:
            "Delivered"
        case .assigned:
            "With your care team"
        case .acknowledged:
            "Seen by your care team"
        case .responded:
            "Responded"
        case .closed:
            "Closed"
        }
    }
}

#if DEBUG
extension PatientMessagingOverview {
    static let syntheticReference = PatientMessagingOverview(
        topics: [
            PatientMessageTopic(
                code: "rounds_question",
                label: "Question for care-team rounds",
                description: "Share a nonurgent question your care team may review before a care conversation. Sending it does not promise it will be discussed in a particular round.",
                expectedResponseWindow: "Your care team usually responds during the current care shift."
            ),
            PatientMessageTopic(
                code: "care_plan_question",
                label: "Question about my care plan",
                description: "Ask a nonurgent question about a released care step or what may happen next.",
                expectedResponseWindow: "Your care team usually responds during the current care shift."
            ),
            PatientMessageTopic(
                code: "care_preference",
                label: "What matters to you",
                description: "Share a non-urgent preference for this hospital stay. Your care team can review it, but it does not change your care plan or create a clinical order on its own.",
                expectedResponseWindow: "Your care team usually responds during the current care shift."
            ),
            PatientMessageTopic(
                code: "patient_goal",
                label: "A personal goal for my stay",
                description: "Share a non-urgent personal goal for this hospital stay. Your care team can review it, but it does not change your care plan or create a clinical order on its own.",
                expectedResponseWindow: "Your care team usually responds during the current care shift."
            ),
            PatientMessageTopic(
                code: "preparing_for_home",
                label: "Preparing to leave the hospital",
                description: "Ask a nonurgent question about education, support, or next-setting preparation.",
                expectedResponseWindow: "Your care team usually responds during the current care shift."
            ),
        ],
        threads: PatientMessageThreadDetail.syntheticReferenceThreads.map(\.summary),
        immediateHelp: .syntheticReference
    )
}

extension PatientImmediateHelpGuidance {
    static let syntheticReference = PatientImmediateHelpGuidance(
        version: "synthetic-guidance-v1",
        text: "Messages are not monitored for emergencies or as live chat. In the hospital, use your bedside call button or speak with a staff member when you need help now."
    )
}

extension PatientMessageThreadDetail {
    static let syntheticReferenceThreads = [
        syntheticReference,
        syntheticRoundsQuestion,
        syntheticUnsharedRoundsQuestion,
    ]

    static let syntheticReference = PatientMessageThreadDetail(
        threadUUID: "019f0000-0000-7000-8000-000000000061",
        topic: PatientMessageThreadTopic(
            code: "care_plan_question",
            label: "Question about my care plan",
            description: "A nonurgent question about a released care step."
        ),
        status: .open,
        ownershipState: .acknowledged,
        expectedResponseWindow: "Your care team usually responds during the current care shift.",
        version: 2,
        lastMessageAt: "2026-07-19T15:12:00.000000Z",
        createdAt: "2026-07-19T14:45:00.000000Z",
        closedAt: nil,
        closeReason: nil,
        messages: [
            PatientVisibleMessage(
                messageUUID: "019f0000-0000-7000-8000-000000000062",
                senderDisplayRole: .patient,
                messageKind: .message,
                body: "Could someone explain what I should expect before my walk this afternoon?",
                relatesToMessageUUID: nil,
                deliveryState: .acknowledged,
                sentAt: "2026-07-19T14:45:00.000000Z"
            ),
            PatientVisibleMessage(
                messageUUID: "019f0000-0000-7000-8000-000000000063",
                senderDisplayRole: .careTeam,
                messageKind: .message,
                body: "Your mobility team will check how you are feeling and review safe support before you begin. Timing can still change.",
                relatesToMessageUUID: nil,
                deliveryState: .responded,
                sentAt: "2026-07-19T15:12:00.000000Z"
            ),
        ]
    )

    static let syntheticRoundsQuestion = PatientMessageThreadDetail(
        threadUUID: "019f0000-0000-7000-8000-000000000064",
        topic: PatientMessageThreadTopic(
            code: "rounds_question",
            label: "Question for care-team rounds",
            description: "A nonurgent question for possible care-team review."
        ),
        status: .open,
        ownershipState: .acknowledged,
        expectedResponseWindow: "Your care team usually responds during the current care shift.",
        version: 3,
        lastMessageAt: "2026-07-19T15:30:00.000000Z",
        createdAt: "2026-07-19T15:18:00.000000Z",
        closedAt: nil,
        closeReason: nil,
        messages: [
            PatientVisibleMessage(
                messageUUID: "019f0000-0000-7000-8000-000000000065",
                senderDisplayRole: .patient,
                messageKind: .message,
                body: "Could my care team consider my question before the next care conversation?",
                relatesToMessageUUID: nil,
                deliveryState: .acknowledged,
                sentAt: "2026-07-19T15:18:00.000000Z"
            ),
            PatientVisibleMessage(
                messageUUID: "019f0000-0000-7000-8000-000000000066",
                senderDisplayRole: .careTeam,
                messageKind: .systemStatus,
                body: "Your question was shared with your care team for possible review. It may not be discussed in a particular round.",
                relatesToMessageUUID: nil,
                deliveryState: .sent,
                sentAt: "2026-07-19T15:24:00.000000Z"
            ),
            PatientVisibleMessage(
                messageUUID: "019f0000-0000-7000-8000-000000000069",
                senderDisplayRole: .careTeam,
                messageKind: .systemStatus,
                body: "Your care team has completed their review of the question you shared. If you still need help, please send a message to your care team.",
                relatesToMessageUUID: nil,
                deliveryState: .sent,
                sentAt: "2026-07-19T15:30:00.000000Z"
            ),
        ]
    )

    static let syntheticUnsharedRoundsQuestion = PatientMessageThreadDetail(
        threadUUID: "019f0000-0000-7000-8000-000000000067",
        topic: PatientMessageThreadTopic(
            code: "rounds_question",
            label: "Question for care-team rounds",
            description: "A nonurgent question for possible care-team review."
        ),
        status: .open,
        ownershipState: .awaitingTeam,
        expectedResponseWindow: "Your care team usually responds during the current care shift.",
        version: 1,
        lastMessageAt: "2026-07-19T16:02:00.000000Z",
        createdAt: "2026-07-19T16:02:00.000000Z",
        closedAt: nil,
        closeReason: nil,
        messages: [
            PatientVisibleMessage(
                messageUUID: "019f0000-0000-7000-8000-000000000068",
                senderDisplayRole: .patient,
                messageKind: .message,
                body: "Could I ask about the next care-team conversation?",
                relatesToMessageUUID: nil,
                deliveryState: .sent,
                sentAt: "2026-07-19T16:02:00.000000Z"
            ),
        ]
    )
}
#endif
