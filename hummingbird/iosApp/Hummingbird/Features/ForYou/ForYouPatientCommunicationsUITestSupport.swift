#if DEBUG
import Foundation

/// Synthetic-only fixture mode for exercising the authorized For You → secure
/// communication detail path. It requires both a launch argument and environment
/// flag and is compiled out of Release; no credentials or live data are present.
enum ForYouPatientCommunicationsUITestMode {
    static var isEnabled: Bool {
        StaffCommunicationsUITestMode.isEnabled
            && ProcessInfo.processInfo.arguments.contains("-HBForYouPatientCommunicationsUITest")
            && ProcessInfo.processInfo.environment["HB_FORYOU_PATIENT_COMM_UI_TEST"] == "1"
    }

    static var items: [ForYouItem] {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        return (try? decoder.decode([ForYouItem].self, from: Data(payload.utf8))) ?? []
    }

    /// Deliberate overshare strings prove that the production decoder replaces
    /// or discards all untrusted communication copy before observable state.
    private static let payload = #"""
    [
      {
        "id": "patient-communication-11111111-1111-4111-8111-111111111111",
        "type": "patient_communication",
        "domain": "communications",
        "tier": "critical",
        "visual_status": "critical",
        "title": "DO NOT SHOW: Jane Doe asked about discharge",
        "subtitle": "DO NOT SHOW: message body and MRN 123456",
        "unit": "5 East — Medical/Surgical",
        "at": "2026-07-19T14:05:00Z",
        "patient_context_ref": "ptok_do_not_retain",
        "dependencies": [{"label": "DO NOT SHOW dependency text", "entity_ref": "patient-123"}],
        "recommended_actions": [{"kind": "view", "label": "DO NOT SHOW action", "endpoint": "/patients/123"}],
        "provenance": {"source_service": "DO NOT SHOW internal source"}
      },
      {
        "id": "patient-communication-not-a-canonical-uuid",
        "type": "patient_communication",
        "domain": "communications",
        "tier": "warning",
        "title": "DO NOT SHOW: malformed sensitive title",
        "subtitle": "DO NOT SHOW: malformed sensitive subtitle",
        "unit": "5 East — Medical/Surgical",
        "at": "not a timestamp",
        "patient_context_ref": "ptok_malformed"
      }
    ]
    """#
}
#endif
