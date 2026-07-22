import XCTest

final class PatientCommunicationRoutingUITests: XCTestCase {
    private var app: XCUIApplication!

    override func setUp() {
        super.setUp()
        continueAfterFailure = false
        app = XCUIApplication()
        app.launchArguments = ["-HBStaffCommunicationsUITest"]
        app.launchEnvironment = [
            "HB_STAFF_COMM_UI_TEST": "1",
            "HB_STAFF_COMM_UI_SCENARIO": "routing",
        ]
    }

    override func tearDown() {
        app.terminate()
        app = nil
        super.tearDown()
    }

    func testReassignRequiresBoundedSelectionsAndExplicitConfirmation() {
        app.launch()
        openRoutingAction("patientCommunications.routing.reassignButton")

        XCTAssertTrue(app.navigationBars["Reassign owner"].waitForExistence(timeout: 5))
        XCTAssertFalse(app.staticTexts["Conversation reassigned."].exists)
        XCTAssertFalse(app.staticTexts["44444444-4444-4444-8444-444444444444"].exists)

        let target = app.descendants(matching: .any)["patientCommunications.routing.target.0"]
        XCTAssertTrue(target.waitForExistence(timeout: 5))
        reveal(target)
        target.tap()

        let reason = app.descendants(matching: .any)["patientCommunications.routing.reason.0"]
        XCTAssertTrue(reason.waitForExistence(timeout: 5))
        reveal(reason)
        reason.tap()

        let review = app.buttons["patientCommunications.routing.reviewButton"]
        reveal(review)
        XCTAssertTrue(review.isEnabled)
        capture("patient-communications-routing-reassign-review")
        review.tap()

        let confirm = app.buttons["Confirm reassignment"]
        XCTAssertTrue(confirm.waitForExistence(timeout: 3))
        XCTAssertFalse(app.staticTexts["Conversation reassigned."].exists)
        confirm.tap()

        XCTAssertTrue(app.staticTexts["Conversation reassigned."].waitForExistence(timeout: 5))
        capture("patient-communications-routing-reassigned")
    }

    func testRerouteSelectorRemainsUsableAtXXXLWithContrastAndReducedEffects() {
        app.launchArguments += [
            "-UIPreferredContentSizeCategoryName", "UICTContentSizeCategoryAccessibilityExtraExtraExtraLarge",
            "-UIAccessibilityDarkerSystemColorsEnabled", "YES",
            "-UIAccessibilityReduceMotionEnabled", "YES",
            "-UIAccessibilityReduceTransparencyEnabled", "YES",
        ]
        app.launch()
        openRoutingAction("patientCommunications.routing.rerouteButton")

        XCTAssertTrue(app.navigationBars["Reroute team"].waitForExistence(timeout: 5))
        let target = app.descendants(matching: .any)["patientCommunications.routing.target.0"]
        XCTAssertTrue(target.waitForExistence(timeout: 5))
        reveal(target)
        XCTAssertTrue(target.isHittable)
        target.tap()
        XCTAssertTrue(app.staticTexts["6 North care team"].exists)
        XCTAssertTrue(app.staticTexts["Unit team · 6 North"].exists)

        let reason = app.descendants(matching: .any)["patientCommunications.routing.reason.0"]
        XCTAssertTrue(reason.waitForExistence(timeout: 5))
        reveal(reason)
        XCTAssertTrue(reason.isHittable)
        reason.tap()

        let review = app.buttons["patientCommunications.routing.reviewButton"]
        reveal(review)
        XCTAssertTrue(review.isEnabled)
        XCTAssertFalse(app.staticTexts["55555555-5555-4555-8555-555555555555"].exists)
        capture("patient-communications-routing-xxxl-contrast-reduced-effects")
    }

    func testFirstAttemptReroutePurgesThreadAndDestinationProjection() {
        app.launch()
        openRoutingAction("patientCommunications.routing.rerouteButton")

        let target = app.descendants(matching: .any)["patientCommunications.routing.target.0"]
        XCTAssertTrue(target.waitForExistence(timeout: 5))
        reveal(target)
        target.tap()
        let reason = app.descendants(matching: .any)["patientCommunications.routing.reason.0"]
        reveal(reason)
        reason.tap()
        let review = app.buttons["patientCommunications.routing.reviewButton"]
        reveal(review)
        review.tap()
        let confirmReroute = app.buttons["Confirm reroute"]
        XCTAssertTrue(confirmReroute.waitForExistence(timeout: 3))
        confirmReroute.tap()

        let confirmation = app.descendants(matching: .any)[
            "patientCommunications.routing.minimizedReplayConfirmation"
        ]
        XCTAssertTrue(confirmation.waitForExistence(timeout: 8))
        XCTAssertTrue(app.staticTexts["Reroute confirmed"].exists)
        XCTAssertTrue(app.staticTexts[
            "Destination details are intentionally hidden because this conversation is no longer in your accountable queue."
        ].exists)
        XCTAssertTrue(app.descendants(matching: .any)[
            "patientCommunications.threadUnavailable"
        ].exists)
        XCTAssertFalse(app.staticTexts["Conversation rerouted."].exists)
        XCTAssertFalse(app.staticTexts.matching(
            NSPredicate(format: "label CONTAINS[c] %@", "Could someone explain")
        ).firstMatch.exists)
        XCTAssertFalse(app.staticTexts["6 North care team"].exists)
        capture("patient-communications-routing-first-success-purged")
    }

    func testInboxPolling401PurgesOpenDraftAndRequiresSignIn() {
        launchScenario("inbox_401_detail")
        openDetail()
        let secret = "SESSION LOSS DRAFT 7391"
        enterDraft(secret)

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
            launchScenario(scenario)
            openDetail()
            XCTAssertTrue(
                app.buttons["patientCommunications.routing.rerouteButton"].waitForExistence(timeout: 5),
                scenario
            )
            enterDraft(secret)

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
        launchScenario("inbox_200_empty_detail")
        openDetail()
        XCTAssertTrue(app.buttons["patientCommunications.routing.rerouteButton"].waitForExistence(timeout: 5))
        let secret = "INBOX OMITTED DRAFT 6184"
        enterDraft(secret)

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
        launchScenario("candidate_401_refresh")
        openDetail()
        XCTAssertTrue(app.buttons["patientCommunications.routing.rerouteButton"].waitForExistence(timeout: 5))
        let secret = "CANDIDATE AUTH DRAFT 4826"
        enterDraft(secret)
        triggerDetailRefresh()

        XCTAssertTrue(app.buttons["Sign in"].waitForExistence(timeout: 12))
        XCTAssertFalse(app.textViews["patientCommunications.replyEditor"].exists)
        XCTAssertFalse(elementContaining(secret).exists)
        XCTAssertFalse(app.buttons["patientCommunications.routing.rerouteButton"].exists)
    }

    func testCandidate404RefreshPurgesDraftThreadAndCommands() {
        launchScenario("candidate_404_refresh")
        openDetail()
        XCTAssertTrue(app.buttons["patientCommunications.routing.rerouteButton"].waitForExistence(timeout: 5))
        let secret = "CANDIDATE DENIAL DRAFT 1564"
        enterDraft(secret)
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
            launchScenario(scenario)
            openDetail()
            enterDraft(secret)
            triggerDetailRefresh()

            let unavailable = app.descendants(matching: .any)["patientCommunications.threadUnavailable"]
            XCTAssertTrue(unavailable.waitForExistence(timeout: 12), scenario)
            XCTAssertFalse(app.textViews["patientCommunications.replyEditor"].exists, scenario)
            XCTAssertFalse(elementContaining(secret).exists, scenario)
            XCTAssertFalse(app.buttons["patientCommunications.routing.retryExactButton"].exists, scenario)
            app.terminate()
        }
    }

    func testLostCommittedRerouteOffersOnlyExplicitExactReplay() {
        app.launchEnvironment["HB_STAFF_COMM_UI_SCENARIO"] = "ambiguous_reroute"
        app.launch()
        openRoutingAction("patientCommunications.routing.rerouteButton")

        let target = app.descendants(matching: .any)["patientCommunications.routing.target.0"]
        XCTAssertTrue(target.waitForExistence(timeout: 5))
        reveal(target)
        target.tap()
        let reason = app.descendants(matching: .any)["patientCommunications.routing.reason.0"]
        reveal(reason)
        reason.tap()
        let review = app.buttons["patientCommunications.routing.reviewButton"]
        reveal(review)
        review.tap()
        let confirmReroute = app.buttons["Confirm reroute"]
        XCTAssertTrue(confirmReroute.waitForExistence(timeout: 3))
        confirmReroute.tap()

        let retry = app.buttons["patientCommunications.routing.retryExactButton"]
        for _ in 0..<8 where !retry.exists {
            app.swipeDown()
        }
        XCTAssertTrue(retry.waitForExistence(timeout: 5))
        XCTAssertTrue(app.staticTexts["Ownership outcome unconfirmed"].exists)
        XCTAssertFalse(app.staticTexts.matching(
            NSPredicate(format: "label CONTAINS[c] %@", "earlier reroute was confirmed")
        ).firstMatch.exists)
        capture("patient-communications-routing-ambiguous-no-auto-retry")
        retry.tap()

        let confirmRetry = app.buttons["Retry exact request"]
        XCTAssertTrue(confirmRetry.waitForExistence(timeout: 3))
        confirmRetry.tap()
        let confirmation = app.descendants(matching: .any)[
            "patientCommunications.routing.minimizedReplayConfirmation"
        ]
        XCTAssertTrue(confirmation.waitForExistence(timeout: 8))
        XCTAssertTrue(app.staticTexts["Reroute confirmed"].exists)
        XCTAssertTrue(app.staticTexts[
            "Destination details are intentionally hidden because this conversation is no longer in your accountable queue."
        ].exists)
        XCTAssertFalse(app.staticTexts.matching(
            NSPredicate(format: "label CONTAINS[c] %@", "Could someone explain")
        ).firstMatch.exists)
        XCTAssertFalse(app.staticTexts["6 North care team"].exists)
        capture("patient-communications-routing-exact-replay-confirmed")
    }

    func testLostCommittedReplyRequiresExplicitExactReplayAndDoesNotDuplicateMessage() {
        launchScenario("ambiguous_reply")
        openDetail()
        let replyBody = "I will review the discharge steps with you this afternoon."
        enterDraft(replyBody)

        let send = app.buttons["patientCommunications.sendButton"]
        reveal(send)
        XCTAssertTrue(send.isEnabled)
        send.tap()

        let retry = app.buttons["patientCommunications.mutation.retryExactButton"]
        for _ in 0..<8 where !retry.exists {
            app.swipeDown()
        }
        XCTAssertTrue(retry.waitForExistence(timeout: 8))
        XCTAssertTrue(app.staticTexts["Reply outcome unconfirmed"].exists)
        XCTAssertFalse(app.staticTexts["Your earlier reply was confirmed."].exists)
        XCTAssertFalse(app.buttons["patientCommunications.routing.rerouteButton"].exists)
        capture("patient-communications-reply-ambiguous-no-auto-retry")

        retry.tap()
        let confirm = app.buttons["Retry exact request"]
        XCTAssertTrue(confirm.waitForExistence(timeout: 3))
        confirm.tap()

        let confirmed = app.staticTexts["Your earlier reply was confirmed."]
        for _ in 0..<8 where !confirmed.exists {
            app.swipeUp()
        }
        XCTAssertTrue(confirmed.waitForExistence(timeout: 8))
        XCTAssertFalse(app.buttons["patientCommunications.mutation.retryExactButton"].exists)
        XCTAssertEqual(
            app.staticTexts.matching(NSPredicate(format: "label == %@", replyBody)).count,
            1,
            "Exact replay must not append a second patient-visible reply"
        )
        capture("patient-communications-reply-exact-replay-confirmed")
    }

    private func openRoutingAction(_ identifier: String) {
        openDetail()

        let action = app.buttons[identifier]
        for _ in 0..<8 where !action.exists {
            app.swipeUp()
        }
        XCTAssertTrue(action.waitForExistence(timeout: 5))
        reveal(action)
        XCTAssertTrue(action.isHittable)
        action.tap()
    }

    private func openDetail() {
        let messages = app.tabBars.buttons["Messages"]
        XCTAssertTrue(messages.waitForExistence(timeout: 5))
        messages.tap()

        let row = app.descendants(matching: .any)[
            "patientCommunications.row.11111111-1111-4111-8111-111111111111"
        ]
        XCTAssertTrue(row.waitForExistence(timeout: 5))
        row.tap()
    }

    private func launchScenario(_ scenario: String) {
        if app.state != .notRunning { app.terminate() }
        app = XCUIApplication()
        app.launchArguments = ["-HBStaffCommunicationsUITest"]
        app.launchEnvironment = [
            "HB_STAFF_COMM_UI_TEST": "1",
            "HB_STAFF_COMM_UI_SCENARIO": scenario,
        ]
        app.launch()
    }

    private func enterDraft(_ text: String) {
        let editor = app.textViews["patientCommunications.replyEditor"]
        XCTAssertTrue(editor.waitForExistence(timeout: 5))
        editor.tap()
        editor.typeText(text)
        XCTAssertTrue((editor.value as? String)?.contains(text) == true)
    }

    private func triggerDetailRefresh() {
        app.navigationBars["Patient conversation"].tap()
    }

    private func elementContaining(_ text: String) -> XCUIElement {
        app.descendants(matching: .any)
            .matching(NSPredicate(format: "label CONTAINS[c] %@", text))
            .firstMatch
    }

    private func reveal(_ element: XCUIElement) {
        for _ in 0..<8 where !element.isHittable {
            app.swipeUp()
        }
    }

    private func capture(_ name: String) {
        let attachment = XCTAttachment(screenshot: app.screenshot())
        attachment.name = name
        attachment.lifetime = .keepAlways
        add(attachment)
    }
}
