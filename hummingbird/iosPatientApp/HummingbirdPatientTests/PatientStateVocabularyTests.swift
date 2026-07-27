import XCTest
@testable import HummingbirdPatient

final class PatientStateVocabularyTests: XCTestCase {
    func testEveryReleasedStateCodeHasExplicitPatientLanguage() {
        XCTAssertEqual(
            PatientStateVocabulary.labels(for: .schedule),
            [
                "requested": "Requested",
                "planned": "Planned",
                "confirmed": "Confirmed",
                "in_progress": "Happening now",
                "completed": "Completed",
                "delayed": "Delayed",
                "canceled": "No longer planned",
            ]
        )
        XCTAssertEqual(
            PatientStateVocabulary.labels(for: .pathway),
            [
                "planned": "Planned",
                "current": "Happening now",
                "completed": "Completed",
                "delayed": "Delayed",
                "canceled": "No longer planned",
            ]
        )
        XCTAssertEqual(
            PatientStateVocabulary.labels(for: .milestone),
            PatientStateVocabulary.labels(for: .pathway)
        )
        XCTAssertEqual(
            PatientStateVocabulary.labels(for: .pathwayEvent),
            PatientStateVocabulary.labels(for: .pathway)
        )
        XCTAssertEqual(
            PatientStateVocabulary.labels(for: .goal),
            [
                "proposed": "Being considered",
                "planned": "Planned",
                "in_progress": "In progress",
                "completed": "Completed",
                "paused": "Paused",
                "canceled": "No longer planned",
            ]
        )
        XCTAssertEqual(
            PatientStateVocabulary.labels(for: .timingConfidence),
            [
                "confirmed": "Confirmed",
                "estimated": "Estimated",
                "unknown": "Not yet known",
            ]
        )
        XCTAssertEqual(
            PatientStateVocabulary.labels(for: .dischargeCriterion),
            [
                "met": "Met",
                "pending": "Still needed",
                "at_risk": "Needs attention",
            ]
        )
        XCTAssertEqual(
            PatientStateVocabulary.labels(for: .roundsTopic),
            [
                "discussed": "Discussed",
                "current": "Being reviewed",
                "planned": "Planned",
            ]
        )
    }

    func testUnknownStateNeverExposesItsInternalCode() {
        XCTAssertEqual(
            PatientStateVocabulary.label(for: "internal_triage_hold", domain: .pathway),
            "Status being confirmed"
        )
        XCTAssertEqual(PatientPathwayState(releasedStatus: "internal_triage_hold"), .notAvailable)
    }

    func testExplicitServerVocabularyMismatchWithholdsTheProjection() {
        XCTAssertTrue(PatientStateVocabulary.isCompatible(serverVersion: nil))
        XCTAssertTrue(PatientStateVocabulary.isCompatible(serverVersion: PatientStateVocabulary.version))
        XCTAssertFalse(PatientStateVocabulary.isCompatible(serverVersion: "patient-state-vocabulary.v2"))
    }

    func testPathwayEventCategoriesMatchTheAndroidPatientLanguage() {
        XCTAssertEqual(PatientPathwayEventCategory.test.patientLabel, "Test")
        XCTAssertEqual(PatientPathwayEventCategory.procedure.patientLabel, "Procedure")
        XCTAssertEqual(PatientPathwayEventCategory.transport.patientLabel, "Transportation")
        XCTAssertEqual(PatientPathwayEventCategory.other.patientLabel, "Care update")
    }
}
