import Foundation
import UIKit

struct PatientEnvelope<Payload: Codable & Equatable>: Codable, Equatable {
    let data: Payload
    let meta: PatientEnvelopeMeta
    let links: [String: String]
}

struct PatientEnvelopeMeta: Codable, Equatable {
    let asOf: String
    let stale: Bool
    let version: PatientJSONValue?
    let sourceFreshness: PatientSourceFreshness?
    let policyVersion: String?
    let stateVocabularyVersion: String?
    let requestID: String?
    let generatedAt: String?

    init(
        asOf: String,
        stale: Bool,
        version: PatientJSONValue?,
        sourceFreshness: PatientSourceFreshness? = nil,
        policyVersion: String? = nil,
        stateVocabularyVersion: String? = nil,
        requestID: String? = nil,
        generatedAt: String? = nil
    ) {
        self.asOf = asOf
        self.stale = stale
        self.version = version
        self.sourceFreshness = sourceFreshness
        self.policyVersion = policyVersion
        self.stateVocabularyVersion = stateVocabularyVersion
        self.requestID = requestID
        self.generatedAt = generatedAt
    }

    enum CodingKeys: String, CodingKey {
        case asOf = "as_of"
        case stale
        case version
        case sourceFreshness = "source_freshness"
        case policyVersion = "policy_version"
        case stateVocabularyVersion = "state_vocabulary_version"
        case requestID = "request_id"
        case generatedAt = "generated_at"
    }

    var asOfDate: Date? {
        ISO8601DateFormatter.patient.date(from: asOf)
    }
}

struct PatientSourceFreshness: Codable, Equatable {
    let status: String
    let observedAt: String?

    enum CodingKeys: String, CodingKey {
        case status
        case observedAt = "observed_at"
    }
}

enum PatientJSONValue: Codable, Equatable {
    case string(String)
    case integer(Int)
    case decimal(Double)
    case boolean(Bool)
    case null

    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        if container.decodeNil() {
            self = .null
        } else if let value = try? container.decode(Bool.self) {
            self = .boolean(value)
        } else if let value = try? container.decode(Int.self) {
            self = .integer(value)
        } else if let value = try? container.decode(Double.self) {
            self = .decimal(value)
        } else if let value = try? container.decode(String.self) {
            self = .string(value)
        } else {
            throw DecodingError.dataCorruptedError(in: container, debugDescription: "Unsupported envelope version value")
        }
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        switch self {
        case .string(let value): try container.encode(value)
        case .integer(let value): try container.encode(value)
        case .decimal(let value): try container.encode(value)
        case .boolean(let value): try container.encode(value)
        case .null: try container.encodeNil()
        }
    }
}

struct PatientProfile: Codable, Equatable {
    let principalUUID: String
    let principalType: String
    let displayName: String
    let email: String?
    let phoneE164: String?
    let emailVerified: Bool
    let phoneVerified: Bool
    let locale: String
    let timezone: String
    let preferences: PatientPreferences

    enum CodingKeys: String, CodingKey {
        case principalUUID = "principal_uuid"
        case principalType = "principal_type"
        case displayName = "display_name"
        case email
        case phoneE164 = "phone_e164"
        case emailVerified = "email_verified"
        case phoneVerified = "phone_verified"
        case locale
        case timezone
        case preferences
    }
}

struct PatientPreferences: Codable, Equatable {
    let textSize: PatientTextSizePreference?
    let reducedMotion: Bool?
    let highContrast: Bool?
    let notificationPreview: PatientNotificationPreviewPreference?
    let preferredChannel: PatientPreferredChannel?

    init(
        textSize: PatientTextSizePreference? = nil,
        reducedMotion: Bool? = nil,
        highContrast: Bool? = nil,
        notificationPreview: PatientNotificationPreviewPreference? = nil,
        preferredChannel: PatientPreferredChannel? = nil
    ) {
        self.textSize = textSize
        self.reducedMotion = reducedMotion
        self.highContrast = highContrast
        self.notificationPreview = notificationPreview
        self.preferredChannel = preferredChannel
    }

    enum CodingKeys: String, CodingKey {
        case textSize = "text_size"
        case reducedMotion = "reduced_motion"
        case highContrast = "high_contrast"
        case notificationPreview = "notification_preview"
        case preferredChannel = "preferred_channel"
    }
}

struct PatientPreferencesInput: Codable, Equatable {
    let locale: String?
    let timezone: String?
    let textSize: PatientTextSizePreference?
    let reducedMotion: Bool?
    let highContrast: Bool?
    let notificationPreview: PatientNotificationPreviewPreference?
    let preferredChannel: PatientPreferredChannel?

    init(
        locale: String? = nil,
        timezone: String? = nil,
        textSize: PatientTextSizePreference? = nil,
        reducedMotion: Bool? = nil,
        highContrast: Bool? = nil,
        notificationPreview: PatientNotificationPreviewPreference? = nil,
        preferredChannel: PatientPreferredChannel? = nil
    ) {
        self.locale = locale
        self.timezone = timezone
        self.textSize = textSize
        self.reducedMotion = reducedMotion
        self.highContrast = highContrast
        self.notificationPreview = notificationPreview
        self.preferredChannel = preferredChannel
    }

    enum CodingKeys: String, CodingKey {
        case locale
        case timezone
        case textSize = "text_size"
        case reducedMotion = "reduced_motion"
        case highContrast = "high_contrast"
        case notificationPreview = "notification_preview"
        case preferredChannel = "preferred_channel"
    }
}

enum PatientTextSizePreference: String, Codable, Equatable {
    case standard
    case large
    case extraLarge = "extra_large"
}

enum PatientNotificationPreviewPreference: String, Codable, Equatable {
    case hidden
    case generic
}

enum PatientPreferredChannel: String, Codable, Equatable {
    case push
    case email
    case sms
    case none
}

struct PatientSessionCollection: Codable, Equatable {
    let sessions: [PatientSessionSummary]
}

struct PatientSessionSummary: Codable, Equatable, Identifiable {
    let sessionUUID: String
    let current: Bool
    let status: PatientSessionStatus
    let device: PatientSessionDevice
    let authMethod: PatientSessionAuthMethod
    let assuranceLevel: String?
    let lastSeenAt: String
    let expiresAt: String
    let createdAt: String

    var id: String { sessionUUID }
    var lastSeenDate: Date? { ISO8601DateFormatter.patient.date(from: lastSeenAt) }
    var expiresDate: Date? { ISO8601DateFormatter.patient.date(from: expiresAt) }
    var createdDate: Date? { ISO8601DateFormatter.patient.date(from: createdAt) }

    enum CodingKeys: String, CodingKey {
        case sessionUUID = "session_uuid"
        case current
        case status
        case device
        case authMethod = "auth_method"
        case assuranceLevel = "assurance_level"
        case lastSeenAt = "last_seen_at"
        case expiresAt = "expires_at"
        case createdAt = "created_at"
    }

    init(
        sessionUUID: String,
        current: Bool,
        status: PatientSessionStatus,
        device: PatientSessionDevice,
        authMethod: PatientSessionAuthMethod,
        assuranceLevel: String?,
        lastSeenAt: String,
        expiresAt: String,
        createdAt: String
    ) {
        self.sessionUUID = sessionUUID
        self.current = current
        self.status = status
        self.device = device
        self.authMethod = authMethod
        self.assuranceLevel = assuranceLevel
        self.lastSeenAt = lastSeenAt
        self.expiresAt = expiresAt
        self.createdAt = createdAt
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        sessionUUID = try container.decode(String.self, forKey: .sessionUUID)
        current = try container.decode(Bool.self, forKey: .current)
        status = try container.decode(PatientSessionStatus.self, forKey: .status)
        device = try container.decode(PatientSessionDevice.self, forKey: .device)
        authMethod = try container.decode(PatientSessionAuthMethod.self, forKey: .authMethod)
        assuranceLevel = try container.decode(String?.self, forKey: .assuranceLevel)
        lastSeenAt = try container.decode(String.self, forKey: .lastSeenAt)
        expiresAt = try container.decode(String.self, forKey: .expiresAt)
        createdAt = try container.decode(String.self, forKey: .createdAt)
    }
}

enum PatientSessionStatus: String, Codable, Equatable {
    case active
}

enum PatientSessionAuthMethod: String, Codable, Equatable {
    case password
    case enrollment
    case federated
    case passkey
    case recovery
}

enum PatientSessionPlatform: String, Codable, Equatable {
    case ios
    case android
    case web
}

struct PatientSessionDevice: Codable, Equatable {
    let uuid: String?
    let platform: PatientSessionPlatform?
    let name: String?
    let appVersion: String?
    let osVersion: String?

    enum CodingKeys: String, CodingKey {
        case uuid
        case platform
        case name
        case appVersion = "app_version"
        case osVersion = "os_version"
    }

    init(
        uuid: String?,
        platform: PatientSessionPlatform?,
        name: String?,
        appVersion: String?,
        osVersion: String?
    ) {
        self.uuid = uuid
        self.platform = platform
        self.name = name
        self.appVersion = appVersion
        self.osVersion = osVersion
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        uuid = try container.decode(String?.self, forKey: .uuid)
        platform = try container.decode(PatientSessionPlatform?.self, forKey: .platform)
        name = try container.decode(String?.self, forKey: .name)
        appVersion = try container.decode(String?.self, forKey: .appVersion)
        osVersion = try container.decode(String?.self, forKey: .osVersion)
    }
}

struct PatientSessionRevocationResult: Codable, Equatable {
    let sessionUUID: String
    let revoked: Bool
    let alreadyRevoked: Bool

    enum CodingKeys: String, CodingKey {
        case sessionUUID = "session_uuid"
        case revoked
        case alreadyRevoked = "already_revoked"
    }
}

struct PatientEncounterCollection: Codable, Equatable {
    let encounters: [PatientEncounterHandle]
}

struct PatientEncounterHandle: Codable, Equatable, Identifiable {
    let encounterUUID: String
    let grantUUID: String
    let relationship: String
    let scopes: [String]
    let validFrom: String?
    let expiresAt: String?
    let version: Int

    var id: String { encounterUUID }

    enum CodingKeys: String, CodingKey {
        case encounterUUID = "encounter_uuid"
        case grantUUID = "grant_uuid"
        case relationship
        case scopes
        case validFrom = "valid_from"
        case expiresAt = "expires_at"
        case version
    }
}

struct PatientMessageTopicsResult: Codable, Equatable {
    let topics: [PatientMessageTopic]
    let immediateHelp: PatientImmediateHelpGuidance

    enum CodingKeys: String, CodingKey {
        case topics
        case immediateHelp = "immediate_help"
    }
}

struct PatientMessageTopic: Codable, Equatable, Identifiable {
    let code: String
    let label: String
    let description: String
    let expectedResponseWindow: String

    var id: String { code }

    enum CodingKeys: String, CodingKey {
        case code
        case label
        case description
        case expectedResponseWindow = "expected_response_window"
    }
}

struct PatientImmediateHelpGuidance: Codable, Equatable {
    let version: String
    let text: String
}

struct PatientMessageThreadCreateInput: Codable, Equatable {
    let topicCode: String
    let message: String
    let clientMessageUUID: String
    let urgentGuidanceVersion: String

    enum CodingKeys: String, CodingKey {
        case topicCode = "topic_code"
        case message
        case clientMessageUUID = "client_message_uuid"
        case urgentGuidanceVersion = "urgent_guidance_version"
    }
}

/// A request for an explanation of released pathway education. It intentionally
/// has no completion, comprehension, consent, or clinical-assessment field.
struct PatientEducationClarificationInput: Codable, Equatable {
    let message: String
    let clientMessageUUID: String
    let urgentGuidanceVersion: String

    enum CodingKeys: String, CodingKey {
        case message
        case clientMessageUUID = "client_message_uuid"
        case urgentGuidanceVersion = "urgent_guidance_version"
    }
}

struct PatientMessageCreateInput: Codable, Equatable {
    let message: String
    let clientMessageUUID: String
    let threadVersion: Int
    let urgentGuidanceVersion: String

    enum CodingKeys: String, CodingKey {
        case message
        case clientMessageUUID = "client_message_uuid"
        case threadVersion = "thread_version"
        case urgentGuidanceVersion = "urgent_guidance_version"
    }
}

enum PatientMessageAmendmentAction: String, Codable, Equatable {
    case correction
    case retraction
}

struct PatientMessageAmendmentInput: Codable, Equatable {
    let action: PatientMessageAmendmentAction
    let message: String?
    let clientMessageUUID: String
    let threadVersion: Int
    let urgentGuidanceVersion: String

    enum CodingKeys: String, CodingKey {
        case action
        case message
        case clientMessageUUID = "client_message_uuid"
        case threadVersion = "thread_version"
        case urgentGuidanceVersion = "urgent_guidance_version"
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(action, forKey: .action)
        try container.encodeIfPresent(message, forKey: .message)
        try container.encode(clientMessageUUID, forKey: .clientMessageUUID)
        try container.encode(threadVersion, forKey: .threadVersion)
        try container.encode(urgentGuidanceVersion, forKey: .urgentGuidanceVersion)
    }
}

struct PatientMessageThreadCloseInput: Codable, Equatable {
    let threadVersion: Int
    let closeReason: PatientMessageThreadCloseReason

    enum CodingKeys: String, CodingKey {
        case threadVersion = "thread_version"
        case closeReason = "close_reason"
    }
}

struct PatientMessageThreadListResult: Codable, Equatable {
    let threads: [PatientMessageThreadSummary]
    let immediateHelp: PatientImmediateHelpGuidance

    enum CodingKeys: String, CodingKey {
        case threads
        case immediateHelp = "immediate_help"
    }
}

struct PatientMessageThreadMutationResult: Codable, Equatable {
    let thread: PatientMessageThreadSummary
}

struct PatientMessageThreadDetailResult: Codable, Equatable {
    let thread: PatientMessageThreadDetail
    let immediateHelp: PatientImmediateHelpGuidance

    enum CodingKeys: String, CodingKey {
        case thread
        case immediateHelp = "immediate_help"
    }
}

struct PatientMessageMutationResult: Codable, Equatable {
    let thread: PatientMessageThreadSummary
    let message: PatientVisibleMessage
}

struct PatientMessageThreadSummary: Codable, Equatable, Identifiable {
    let threadUUID: String
    let topic: PatientMessageThreadTopic
    let status: PatientMessageThreadStatus
    let ownershipState: PatientMessageOwnershipState
    let expectedResponseWindow: String
    let version: Int
    let lastMessageAt: String
    let createdAt: String
    let closedAt: String?
    let closeReason: PatientMessageThreadCloseReason?

    var id: String { threadUUID }

    enum CodingKeys: String, CodingKey {
        case threadUUID = "thread_uuid"
        case topic
        case status
        case ownershipState = "ownership_state"
        case expectedResponseWindow = "expected_response_window"
        case version
        case lastMessageAt = "last_message_at"
        case createdAt = "created_at"
        case closedAt = "closed_at"
        case closeReason = "close_reason"
    }
}

struct PatientMessageThreadDetail: Codable, Equatable, Identifiable {
    let threadUUID: String
    let topic: PatientMessageThreadTopic
    let status: PatientMessageThreadStatus
    let ownershipState: PatientMessageOwnershipState
    let expectedResponseWindow: String
    let version: Int
    let lastMessageAt: String
    let createdAt: String
    let closedAt: String?
    let closeReason: PatientMessageThreadCloseReason?
    let messages: [PatientVisibleMessage]

    var id: String { threadUUID }

    enum CodingKeys: String, CodingKey {
        case threadUUID = "thread_uuid"
        case topic
        case status
        case ownershipState = "ownership_state"
        case expectedResponseWindow = "expected_response_window"
        case version
        case lastMessageAt = "last_message_at"
        case createdAt = "created_at"
        case closedAt = "closed_at"
        case closeReason = "close_reason"
        case messages
    }
}

struct PatientMessageThreadTopic: Codable, Equatable {
    let code: String
    let label: String
    let description: String
}

struct PatientVisibleMessage: Codable, Equatable, Identifiable {
    let messageUUID: String
    let senderDisplayRole: PatientMessageSenderDisplayRole
    let messageKind: PatientMessageKind
    let body: String?
    let relatesToMessageUUID: String?
    let deliveryState: PatientMessageDeliveryState
    let sentAt: String

    var id: String { messageUUID }

    enum CodingKeys: String, CodingKey {
        case messageUUID = "message_uuid"
        case senderDisplayRole = "sender_display_role"
        case messageKind = "message_kind"
        case body
        case relatesToMessageUUID = "relates_to_message_uuid"
        case deliveryState = "delivery_state"
        case sentAt = "sent_at"
    }
}

enum PatientMessageThreadStatus: String, Codable, Equatable {
    case open
    case closed
}

enum PatientMessageOwnershipState: String, Codable, Equatable {
    case awaitingTeam = "awaiting_team"
    case assigned
    case acknowledged
    case responded
    case rerouted
    case escalated
    case closed
}

enum PatientMessageThreadCloseReason: String, Codable, Equatable, CaseIterable, Identifiable {
    case questionAnswered = "question_answered"
    case noLongerNeeded = "no_longer_needed"
    case createdInError = "created_in_error"
    case other

    var id: String { rawValue }
}

enum PatientMessageSenderDisplayRole: String, Codable, Equatable {
    case patient = "You"
    case careTeam = "Care team"
}

enum PatientMessageKind: String, Codable, Equatable {
    case message
    case correction
    case retraction
    case systemStatus = "system_status"
}

enum PatientMessageDeliveryState: String, Codable, Equatable {
    case sent
    case delivered
    case assigned
    case acknowledged
    case responded
    case closed
}

struct PatientTokenPair: Codable, Equatable {
    let tokenType: String
    let accessToken: String
    let refreshToken: String
    let expiresIn: Int
    let sessionUUID: String
    let abilities: [String]

    enum CodingKeys: String, CodingKey {
        case tokenType = "token_type"
        case accessToken = "access_token"
        case refreshToken = "refresh_token"
        case expiresIn = "expires_in"
        case sessionUUID = "session_uuid"
        case abilities
    }
}

struct PatientRevocationResult: Codable, Equatable {
    let revoked: Bool
}

struct PatientProjectionData<Content: Codable & Equatable>: Codable, Equatable {
    let projectionUUID: String
    let encounterUUID: String
    let kind: String
    let content: Content
    let uncertainty: PatientProjectionUncertainty
    let provenance: PatientProjectionProvenance
    let revisionNotice: PatientProjectionRevisionNotice?
    let observedAt: String?
    let generatedAt: String?
    let releasedAt: String?

    enum CodingKeys: String, CodingKey {
        case projectionUUID = "projection_uuid"
        case encounterUUID = "encounter_uuid"
        case kind
        case content
        case uncertainty
        case provenance
        case revisionNotice = "revision_notice"
        case observedAt = "observed_at"
        case generatedAt = "generated_at"
        case releasedAt = "released_at"
    }
}

enum PatientProjectionRevisionKind: String, Codable, Equatable {
    case correction
}

struct PatientProjectionRevisionNotice: Codable, Equatable {
    let kind: PatientProjectionRevisionKind
    let message: String
}

struct PatientProjectionUncertainty: Codable, Equatable {
    let level: String
    let explanation: String
    let canChange: Bool
    let reviewedAt: String

    enum CodingKeys: String, CodingKey {
        case level
        case explanation
        case canChange = "can_change"
        case reviewedAt = "reviewed_at"
    }
}

struct PatientProjectionProvenance: Codable, Equatable {
    let projectionMethod: String
    let sourceClass: String
    let inputClasses: [String]
    let reviewState: String
    let producerVersion: String

    enum CodingKeys: String, CodingKey {
        case projectionMethod = "projection_method"
        case sourceClass = "source_class"
        case inputClasses = "input_classes"
        case reviewState = "review_state"
        case producerVersion = "producer_version"
    }

    var patientDescription: String {
        [reviewState.patientWords, sourceClass.patientWords]
            .filter { !$0.isEmpty }
            .joined(separator: " · ")
    }
}

struct PatientTodayContent: Codable, Equatable {
    let headline: String
    let summary: String
    let schedule: [PatientScheduleItem]?
    let nextSteps: [String]?
    let careLocation: PatientCareLocation?
    let dischargeOutlook: PatientDischargeOutlook?
    let questions: [String]?
    let notices: [String]?

    enum CodingKeys: String, CodingKey {
        case headline
        case summary
        case schedule
        case nextSteps = "next_steps"
        case careLocation = "care_location"
        case dischargeOutlook = "discharge_outlook"
        case questions
        case notices
    }
}

struct PatientScheduleItem: Codable, Equatable, Identifiable {
    let itemUUID: String
    let label: String
    let detail: String?
    let status: String
    let timeWindow: String
    let timingConfidence: String?
    let preparation: String?
    let canChange: Bool

    var id: String { itemUUID }

    enum CodingKeys: String, CodingKey {
        case itemUUID = "item_uuid"
        case label
        case detail
        case status
        case timeWindow = "time_window"
        case timingConfidence = "timing_confidence"
        case preparation
        case canChange = "can_change"
    }
}

struct PatientCareLocation: Codable, Equatable {
    let facilityDisplayName: String?
    let unitDisplayName: String?
    let roomDisplayName: String?
    let status: String

    enum CodingKeys: String, CodingKey {
        case facilityDisplayName = "facility_display_name"
        case unitDisplayName = "unit_display_name"
        case roomDisplayName = "room_display_name"
        case status
    }
}

struct PatientDischargeOutlook: Codable, Equatable {
    let estimatedRange: String
    let confidence: String
    let readinessTopics: [String]?
    let remainingSteps: [String]?
    let canChange: Bool

    enum CodingKeys: String, CodingKey {
        case estimatedRange = "estimated_range"
        case confidence
        case readinessTopics = "readiness_topics"
        case remainingSteps = "remaining_steps"
        case canChange = "can_change"
    }
}

struct PatientPathwayContent: Codable, Equatable {
    let headline: String
    let summary: String
    let currentStage: String?
    let stages: [PatientReleasedPathwayStage]?
    let milestones: [PatientReleasedPathwayMilestone]?
    let goals: [PatientReleasedGoal]?
    let education: [PatientReleasedEducation]?
    let questions: [String]?
    let notices: [String]?

    enum CodingKeys: String, CodingKey {
        case headline
        case summary
        case currentStage = "current_stage"
        case stages
        case milestones
        case goals
        case education
        case questions
        case notices
    }
}

struct PatientReleasedPathwayStage: Codable, Equatable, Identifiable {
    let stageUUID: String
    let title: String
    let status: String
    let summary: String
    let expectedRange: String?
    let timingConfidence: String?
    let canChange: Bool

    var id: String { stageUUID }

    enum CodingKeys: String, CodingKey {
        case stageUUID = "stage_uuid"
        case title
        case status
        case summary
        case expectedRange = "expected_range"
        case timingConfidence = "timing_confidence"
        case canChange = "can_change"
    }
}

struct PatientReleasedPathwayMilestone: Codable, Equatable, Identifiable {
    let milestoneUUID: String
    let title: String
    let status: String
    let detail: String?
    let timing: String?
    let timingConfidence: String?
    let canChange: Bool

    var id: String { milestoneUUID }

    enum CodingKeys: String, CodingKey {
        case milestoneUUID = "milestone_uuid"
        case title
        case status
        case detail
        case timing
        case timingConfidence = "timing_confidence"
        case canChange = "can_change"
    }
}

struct PatientReleasedGoal: Codable, Equatable, Identifiable {
    let goalUUID: String
    let authorType: String
    let label: String
    let explanation: String?
    let status: String
    let targetRange: String?

    var id: String { goalUUID }

    enum CodingKeys: String, CodingKey {
        case goalUUID = "goal_uuid"
        case authorType = "author_type"
        case label
        case explanation
        case status
        case targetRange = "target_range"
    }
}

struct PatientReleasedEducation: Codable, Equatable, Identifiable {
    let itemUUID: String
    let title: String
    let summary: String

    var id: String { itemUUID }

    enum CodingKeys: String, CodingKey {
        case itemUUID = "item_uuid"
        case title
        case summary
    }
}

struct PatientPathwayEventsContent: Codable, Equatable {
    let headline: String
    let summary: String
    let events: [PatientReleasedPathwayEvent]?
    let notices: [String]?
}

enum PatientPathwayEventCategory: String, Codable, Equatable {
    case test
    case procedure
    case transport
    case other

    var patientLabel: String {
        switch self {
        case .test: "Test"
        case .procedure: "Procedure"
        case .transport: "Transportation"
        case .other: "Care update"
        }
    }
}

struct PatientReleasedPathwayEvent: Codable, Equatable, Identifiable {
    let eventUUID: String
    let title: String
    let when: String
    let status: String
    let detail: String?
    let category: PatientPathwayEventCategory?

    var id: String { eventUUID }

    enum CodingKeys: String, CodingKey {
        case eventUUID = "event_uuid"
        case title
        case when
        case status
        case detail
        case category
    }
}

struct PatientDischargeReadinessContent: Codable, Equatable {
    let headline: String
    let summary: String
    let estimatedRange: String?
    let estimatedConfidence: String?
    let criteria: [PatientDischargeCriterion]?
    let unresolvedNeeds: [String]?
    let medications: [PatientDischargeMedication]?
    let followUp: [PatientDischargeFollowUp]?
    let warningSigns: [String]?
    let contacts: [PatientDischargeContact]?
    let questions: [String]?
    let notices: [String]?

    enum CodingKeys: String, CodingKey {
        case headline
        case summary
        case estimatedRange = "estimated_range"
        case estimatedConfidence = "estimated_confidence"
        case criteria
        case unresolvedNeeds = "unresolved_needs"
        case medications
        case followUp = "follow_up"
        case warningSigns = "warning_signs"
        case contacts
        case questions
        case notices
    }
}

struct PatientRoundsSummaryContent: Codable, Equatable {
    let headline: String
    let summary: String
    let roundWindow: String?
    let topics: [PatientReleasedRoundsTopic]?
    let nextSteps: [String]?
    let questions: [String]?
    let notices: [String]?

    enum CodingKeys: String, CodingKey {
        case headline
        case summary
        case roundWindow = "round_window"
        case topics
        case nextSteps = "next_steps"
        case questions
        case notices
    }
}

struct PatientReleasedRoundsTopic: Codable, Equatable, Identifiable {
    let topicUUID: String
    let title: String
    let summary: String
    let status: String

    var id: String { topicUUID }

    enum CodingKeys: String, CodingKey {
        case topicUUID = "topic_uuid"
        case title
        case summary
        case status
    }
}

struct PatientDischargeCriterion: Codable, Equatable, Identifiable {
    let itemUUID: String
    let label: String
    let status: String
    let detail: String?

    var id: String { itemUUID }

    enum CodingKeys: String, CodingKey {
        case itemUUID = "item_uuid"
        case label
        case status
        case detail
    }
}

struct PatientDischargeMedication: Codable, Equatable, Identifiable {
    let itemUUID: String
    let name: String
    let purpose: String?

    var id: String { itemUUID }

    enum CodingKeys: String, CodingKey {
        case itemUUID = "item_uuid"
        case name
        case purpose
    }
}

struct PatientDischargeFollowUp: Codable, Equatable, Identifiable {
    let itemUUID: String
    let label: String
    let when: String

    var id: String { itemUUID }

    enum CodingKeys: String, CodingKey {
        case itemUUID = "item_uuid"
        case label
        case when
    }
}

struct PatientDischargeContact: Codable, Equatable, Identifiable {
    let itemUUID: String
    let label: String
    let route: String

    var id: String { itemUUID }

    enum CodingKeys: String, CodingKey {
        case itemUUID = "item_uuid"
        case label
        case route
    }
}

struct PatientCareTeamContent: Codable, Equatable {
    let headline: String
    let summary: String
    let members: [PatientReleasedCareTeamMember]?
    let communicationOptions: [String]?
    let questions: [String]?
    let notices: [String]?

    enum CodingKeys: String, CodingKey {
        case headline
        case summary
        case members
        case communicationOptions = "communication_options"
        case questions
        case notices
    }
}

struct PatientReleasedCareTeamMember: Codable, Equatable, Identifiable {
    let memberUUID: String
    let displayName: String
    let role: String
    let service: String?
    let responsibilities: [String]
    let contactRoute: String

    var id: String { memberUUID }

    enum CodingKeys: String, CodingKey {
        case memberUUID = "member_uuid"
        case displayName = "display_name"
        case role
        case service
        case responsibilities
        case contactRoute = "contact_route"
    }
}

struct PatientEnrollmentInput: Equatable {
    let challengeUUID: String
    let challengeToken: String
    let verificationCode: String
    let displayName: String
    let email: String
    let password: String
    let passwordConfirmation: String
}

struct PatientDeviceDescriptor: Codable, Equatable {
    let uuid: String
    let platform: String
    let name: String
    let appVersion: String
    let osVersion: String

    static var current: PatientDeviceDescriptor {
        let preferences = UserDefaults(suiteName: PatientStorageNamespace.preferencesSuite)
        let key = "patient-device-uuid"
        let existing = preferences?.string(forKey: key)
        let uuid = existing ?? UUID().uuidString.lowercased()
        if existing == nil { preferences?.set(uuid, forKey: key) }

        return PatientDeviceDescriptor(
            uuid: uuid,
            platform: "ios",
            name: UIDevice.current.name,
            appVersion: Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "unknown",
            osVersion: UIDevice.current.systemVersion
        )
    }

    enum CodingKeys: String, CodingKey {
        case uuid
        case platform
        case name
        case appVersion = "app_version"
        case osVersion = "os_version"
    }
}

private extension ISO8601DateFormatter {
    static let patient: ISO8601DateFormatter = {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return formatter
    }()
}

private extension String {
    var patientWords: String {
        replacingOccurrences(of: "_", with: " ")
            .trimmingCharacters(in: .whitespacesAndNewlines)
    }
}
