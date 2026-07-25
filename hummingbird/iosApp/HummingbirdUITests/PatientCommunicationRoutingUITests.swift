import XCTest

/// Routing mutation journeys: reassign/reroute flows, their explicit
/// confirmations, and exact-replay recovery for lost-committed requests.
/// Revocation/purge journeys live in `PatientCommunicationPurgeUITests`.
final class PatientCommunicationRoutingUITests: PatientCommunicationRoutingUITestCase {
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
        let replyBody = "Replay-safe reply 7391."
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
}
