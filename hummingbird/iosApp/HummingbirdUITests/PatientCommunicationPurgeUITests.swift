import XCTest

/// Revocation purge journeys: inbox-polling and detail-refresh responses
/// (401/403/404/200-omission) must purge open drafts, thread content, and
/// routing commands. Routing mutation journeys live in
/// `PatientCommunicationRoutingUITests`.
final class PatientCommunicationPurgeUITests: PatientCommunicationRoutingUITestCase {
    func testInboxPolling401PurgesOpenDraftAndRequiresSignIn() {
        let secret = "SESSION LOSS DRAFT 7391"
        launchScenario("inbox_401_detail", seededDraft: secret)
        openDetail()
        assertSeededDraft(secret)

        XCTAssertTrue(app.buttons["Sign in"].waitForExistence(timeout: 20))
        XCTAssertFalse(app.textViews["patientCommunications.replyEditor"].exists)
        XCTAssertFalse(elementContaining(secret).exists)
        XCTAssertFalse(app.buttons["Confirm reroute"].exists)
        capture("patient-communications-inbox-401-purged")
    }

    func testInboxPolling403And404PurgeOpenDraftThreadAndCommands() {
        for (scenario, secret) in [
            ("inbox_403_detail", "INBOX FORBIDDEN DRAFT 2648"),
            ("inbox_404_detail", "INBOX NOT FOUND DRAFT 7315"),
        ] {
            launchScenario(scenario, seededDraft: secret)
            openDetail()
            XCTAssertTrue(
                app.buttons["patientCommunications.routing.rerouteButton"].waitForExistence(timeout: 5),
                scenario
            )
            assertSeededDraft(secret)

            let unavailable = app.descendants(matching: .any)["patientCommunications.threadUnavailable"]
            XCTAssertTrue(unavailable.waitForExistence(timeout: 20), scenario)
            XCTAssertFalse(app.textViews["patientCommunications.replyEditor"].exists, scenario)
            XCTAssertFalse(elementContaining(secret).exists, scenario)
            XCTAssertFalse(app.buttons["patientCommunications.routing.rerouteButton"].exists, scenario)
            XCTAssertFalse(app.buttons["patientCommunications.routing.retryExactButton"].exists, scenario)
            XCTAssertFalse(app.buttons["Sign in"].exists, scenario)
            capture("patient-communications-\(scenario)-purged")
            app.terminate()
        }
    }

    func testInboxPolling200OmissionPurgesOpenDraftThreadAndCommands() {
        let secret = "INBOX OMITTED DRAFT 6184"
        launchScenario("inbox_200_empty_detail", seededDraft: secret)
        openDetail()
        XCTAssertTrue(app.buttons["patientCommunications.routing.rerouteButton"].waitForExistence(timeout: 5))
        assertSeededDraft(secret)

        let unavailable = app.descendants(matching: .any)["patientCommunications.threadUnavailable"]
        XCTAssertTrue(unavailable.waitForExistence(timeout: 20))
        XCTAssertFalse(app.textViews["patientCommunications.replyEditor"].exists)
        XCTAssertFalse(elementContaining(secret).exists)
        XCTAssertFalse(app.buttons["patientCommunications.routing.rerouteButton"].exists)
        XCTAssertFalse(app.buttons["patientCommunications.routing.retryExactButton"].exists)
        XCTAssertFalse(app.buttons["Sign in"].exists)
        capture("patient-communications-inbox-200-omission-purged")
    }

    func testCandidate401RefreshPurgesDraftAndRequiresSignIn() {
        let secret = "CANDIDATE AUTH DRAFT 4826"
        launchScenario("candidate_401_refresh", seededDraft: secret)
        openDetail()
        XCTAssertTrue(app.buttons["patientCommunications.routing.rerouteButton"].waitForExistence(timeout: 5))
        assertSeededDraft(secret)
        triggerDetailRefresh()

        XCTAssertTrue(app.buttons["Sign in"].waitForExistence(timeout: 12))
        XCTAssertFalse(app.textViews["patientCommunications.replyEditor"].exists)
        XCTAssertFalse(elementContaining(secret).exists)
        XCTAssertFalse(app.buttons["patientCommunications.routing.rerouteButton"].exists)
    }

    func testCandidate404RefreshPurgesDraftThreadAndCommands() {
        let secret = "CANDIDATE DENIAL DRAFT 1564"
        launchScenario("candidate_404_refresh", seededDraft: secret)
        openDetail()
        XCTAssertTrue(app.buttons["patientCommunications.routing.rerouteButton"].waitForExistence(timeout: 5))
        assertSeededDraft(secret)
        triggerDetailRefresh()

        let unavailable = app.descendants(matching: .any)["patientCommunications.threadUnavailable"]
        XCTAssertTrue(unavailable.waitForExistence(timeout: 12))
        XCTAssertFalse(app.textViews["patientCommunications.replyEditor"].exists)
        XCTAssertFalse(elementContaining(secret).exists)
        XCTAssertFalse(app.buttons["patientCommunications.routing.rerouteButton"].exists)
        XCTAssertFalse(app.buttons["patientCommunications.routing.retryExactButton"].exists)
        capture("patient-communications-candidate-404-purged")
    }

    func testDetail403And404RefreshPurgeDraftBeforeUnavailableState() {
        for (scenario, secret) in [
            ("thread_403_refresh", "DETAIL FORBIDDEN DRAFT 8327"),
            ("thread_404_refresh", "DETAIL NOT FOUND DRAFT 9053"),
        ] {
            launchScenario(scenario, seededDraft: secret)
            openDetail()
            assertSeededDraft(secret)
            triggerDetailRefresh()

            let unavailable = app.descendants(matching: .any)["patientCommunications.threadUnavailable"]
            XCTAssertTrue(unavailable.waitForExistence(timeout: 12), scenario)
            XCTAssertFalse(app.textViews["patientCommunications.replyEditor"].exists, scenario)
            XCTAssertFalse(elementContaining(secret).exists, scenario)
            XCTAssertFalse(app.buttons["patientCommunications.routing.retryExactButton"].exists, scenario)
            app.terminate()
        }
    }
}
