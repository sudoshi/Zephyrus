import Foundation

/// The patient API transmits stable state *codes*. Keep their English display
/// copy explicit and contextual here instead of turning internal identifiers
/// such as `at_risk` into title-cased text at render time. A future localized
/// registry must preserve the codes and replace only this approved copy.
enum PatientStateDomain: CaseIterable {
    case schedule
    case pathway
    case milestone
    case pathwayEvent
    case goal
    case timingConfidence
    case dischargeCriterion
    case roundsTopic
}

enum PatientStateVocabulary {
    static let version = "patient-state-vocabulary.v1-draft"

    private static let labels: [PatientStateDomain: [String: String]] = [
        .schedule: [
            "requested": "Requested",
            "planned": "Planned",
            "confirmed": "Confirmed",
            "in_progress": "Happening now",
            "completed": "Completed",
            "delayed": "Delayed",
            "canceled": "No longer planned",
        ],
        .pathway: [
            "planned": "Planned",
            "current": "Happening now",
            "completed": "Completed",
            "delayed": "Delayed",
            "canceled": "No longer planned",
        ],
        .milestone: [
            "planned": "Planned",
            "current": "Happening now",
            "completed": "Completed",
            "delayed": "Delayed",
            "canceled": "No longer planned",
        ],
        .pathwayEvent: [
            "planned": "Planned",
            "current": "Happening now",
            "completed": "Completed",
            "delayed": "Delayed",
            "canceled": "No longer planned",
        ],
        .goal: [
            "proposed": "Being considered",
            "planned": "Planned",
            "in_progress": "In progress",
            "completed": "Completed",
            "paused": "Paused",
            "canceled": "No longer planned",
        ],
        .timingConfidence: [
            "confirmed": "Confirmed",
            "estimated": "Estimated",
            "unknown": "Not yet known",
        ],
        .dischargeCriterion: [
            "met": "Met",
            "pending": "Still needed",
            "at_risk": "Needs attention",
        ],
        .roundsTopic: [
            "discussed": "Discussed",
            "current": "Being reviewed",
            "planned": "Planned",
        ],
    ]

    static func label(for code: String, domain: PatientStateDomain) -> String {
        labels[domain]?[code] ?? "Status being confirmed"
    }

    static func labels(for domain: PatientStateDomain) -> [String: String] {
        labels[domain] ?? [:]
    }

    /// Older servers did not advertise this additive value. Preserve their
    /// existing compatibility, but never render a projection explicitly
    /// stamped with an unfamiliar state-language vocabulary.
    static func isCompatible(serverVersion: String?) -> Bool {
        serverVersion == nil || serverVersion == version
    }
}
