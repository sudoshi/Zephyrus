import XCTest

final class PatientCommunicationsUITests: XCTestCase {
    private var app: XCUIApplication!

    override func setUp() {
        super.setUp()
        continueAfterFailure = false
        app = XCUIApplication()
        app.launchArguments = ["-HBStaffCommunicationsUITest"]
        app.launchEnvironment = [
            "HB_STAFF_COMM_UI_TEST": "1",
            "HB_STAFF_COMM_UI_SCENARIO": "open",
        ]
    }

    override func tearDown() {
        app.terminate()
        app = nil
        super.tearDown()
    }

    func testClaimReplyAndClosePatientVisibleWorkflow() {
        app.launch()

        openMessages()
        let row = app.descendants(matching: .any)["patientCommunications.row.11111111-1111-4111-8111-111111111111"]
        XCTAssertTrue(row.waitForExistence(timeout: 5))
        capture("patient-communications-inbox-normal")
        row.tap()

        let claim = app.buttons["patientCommunications.claimButton"]
        XCTAssertTrue(claim.waitForExistence(timeout: 5))
        claim.tap()

        let editor = app.textViews["patientCommunications.replyEditor"]
        XCTAssertTrue(editor.waitForExistence(timeout: 5))
        capture("patient-communications-thread-owned")
        editor.tap()
        editor.typeText("Your care team is coordinating the final medication review and transportation plan with you.")

        let send = app.buttons["patientCommunications.sendButton"]
        XCTAssertTrue(send.isEnabled)
        send.tap()
        XCTAssertTrue(app.staticTexts["Reply delivered to the patient."].waitForExistence(timeout: 5))

        let close = app.buttons["patientCommunications.closeButton"]
        XCTAssertTrue(close.waitForExistence(timeout: 5))
        capture("patient-communications-reply-delivered")
        close.tap()
        let answered = app.buttons["Question answered"]
        XCTAssertTrue(answered.waitForExistence(timeout: 3))
        answered.tap()

        XCTAssertTrue(app.descendants(matching: .any)["patientCommunications.closed"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.staticTexts["Conversation closed"].exists)
    }

    func testGenericDenialDoesNotExposeAuthorizationOrRoutingDetails() {
        app.launchEnvironment = [
            "HB_STAFF_COMM_UI_TEST": "1",
            "HB_STAFF_COMM_UI_SCENARIO": "denied",
        ]
        app.launch()
        openMessages()

        let unavailable = app.descendants(matching: .any)
            .matching(NSPredicate(format: "label CONTAINS[c] %@", "Communications unavailable"))
            .firstMatch
        XCTAssertTrue(unavailable.waitForExistence(timeout: 5))
        capture("patient-communications-generic-denial")
        XCTAssertFalse(app.staticTexts.matching(NSPredicate(format: "label CONTAINS[c] %@", "pool")).firstMatch.exists)
        XCTAssertFalse(app.staticTexts.matching(NSPredicate(format: "label CONTAINS[c] %@", "capability")).firstMatch.exists)
    }

    func testLayoutRemainsNavigableWithAccessibilityDisplayPreferences() {
        app.launchArguments += [
            "-UIPreferredContentSizeCategoryName", "UICTContentSizeCategoryAccessibilityExtraExtraExtraLarge",
            "-UIAccessibilityDarkerSystemColorsEnabled", "YES",
            "-UIAccessibilityReduceMotionEnabled", "YES",
            "-UIAccessibilityReduceTransparencyEnabled", "YES",
        ]
        app.launch()
        openMessages()

        XCTAssertTrue(app.staticTexts["Non-emergency communication"].waitForExistence(timeout: 5))
        let row = app.descendants(matching: .any)["patientCommunications.row.11111111-1111-4111-8111-111111111111"]
        XCTAssertTrue(row.waitForExistence(timeout: 5))
        row.tap()
        XCTAssertTrue(app.staticTexts["Keep urgent care on clinical channels"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.buttons["patientCommunications.claimButton"].waitForExistence(timeout: 5))
        capture("patient-communications-accessibility-xxxl-contrast-reduced-effects")
    }

    private func capture(_ name: String) {
        let attachment = XCTAttachment(screenshot: app.screenshot())
        attachment.name = name
        attachment.lifetime = .keepAlways
        add(attachment)
    }

    private func openMessages() {
        let tab = app.tabBars.buttons["Messages"]
        XCTAssertTrue(tab.waitForExistence(timeout: 5))
        tab.tap()
    }
}
