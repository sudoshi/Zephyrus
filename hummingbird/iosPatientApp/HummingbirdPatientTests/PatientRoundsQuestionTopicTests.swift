import XCTest
@testable import HummingbirdPatient

final class PatientRoundsQuestionTopicTests: XCTestCase {
    func testSyntheticMessageTopicsOfferAnExplicitNonPromissoryRoundsQuestion() {
        let topic = PatientMessagingOverview.syntheticReference.topics.first { $0.code == "rounds_question" }

        XCTAssertEqual(topic?.label, "Question for care-team rounds")
        XCTAssertTrue(topic?.description.contains("does not promise") == true)

        let thread = PatientMessageThreadDetail.syntheticReferenceThreads
            .first { $0.topic.code == "rounds_question" }
        let statuses = thread?.messages.filter { $0.messageKind == .systemStatus } ?? []
        XCTAssertEqual(statuses.count, 2)
        XCTAssertEqual(statuses.first?.senderDisplayRole, .careTeam)
        XCTAssertTrue(statuses.first?.body?.contains("possible review") == true)
        XCTAssertTrue(statuses.first?.body?.contains("may not be discussed") == true)
        XCTAssertTrue(statuses.last?.body?.contains("completed their review") == true)
        XCTAssertFalse(statuses.last?.body?.localizedCaseInsensitiveContains("round") == true)
    }

    func testSyntheticMessageTopicsOfferCarePreferenceWithoutPromisingACarePlanChange() {
        let topic = PatientMessagingOverview.syntheticReference.topics.first { $0.code == "care_preference" }

        XCTAssertEqual(topic?.label, "What matters to you")
        XCTAssertTrue(topic?.description.contains("does not change your care plan") == true)
        XCTAssertTrue(topic?.description.contains("clinical order") == true)
    }

    func testSyntheticMessageTopicsOfferPersonalGoalWithoutPromisingClinicalGoalOrCarePlanMutation() {
        let topic = PatientMessagingOverview.syntheticReference.topics.first { $0.code == "patient_goal" }

        XCTAssertEqual(topic?.label, "A personal goal for my stay")
        XCTAssertTrue(topic?.description.contains("does not change your care plan") == true)
        XCTAssertTrue(topic?.description.contains("clinical order") == true)
    }
}
