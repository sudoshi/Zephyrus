import XCTest

final class ForYouPatientCommunicationsUITests: XCTestCase {
    private var app: XCUIApplication!

    override func setUp() {
        super.setUp()
        continueAfterFailure = false
        app = XCUIApplication()
        app.launchArguments = [
            "-HBStaffCommunicationsUITest",
            "-HBForYouPatientCommunicationsUITest",
        ]
        app.launchEnvironment = [
            "HB_STAFF_COMM_UI_TEST": "1",
            "HB_STAFF_COMM_UI_SCENARIO": "open",
            "HB_FORYOU_PATIENT_COMM_UI_TEST": "1",
            "HB_TAB": "foryou",
            // The transporter's legacy For You filter is `.none`; seeing the
            // authorized row verifies communications bypass local role filtering.
            "HB_ROLE": "transport",
        ]
    }

    override func tearDown() {
        app.terminate()
        app = nil
        super.tearDown()
    }

    func testAuthorizedAttentionUsesFixedCopyAndOpensOnlySecureCommunicationDetail() {
        app.launch()

        let row = validRow
        XCTAssertTrue(row.waitForExistence(timeout: 6))
        XCTAssertTrue(app.staticTexts["Escalated patient communication"].exists)
        XCTAssertTrue(app.staticTexts["Open the secure conversation to review the request."].exists)
        XCTAssertTrue(app.descendants(matching: .any)["forYou.patientCommunication.malformed"].exists)
        XCTAssertFalse(app.buttons["patientCommunications.claimButton"].exists, "For You must not expose inline mutations")
        assertOvershareIsAbsent()
        capture("for-you-patient-communication-normal")

        row.tap()

        XCTAssertTrue(app.navigationBars["Patient conversation"].waitForExistence(timeout: 6))
        XCTAssertTrue(app.staticTexts["Keep urgent care on clinical channels"].exists)
        XCTAssertTrue(app.buttons["patientCommunications.claimButton"].waitForExistence(timeout: 5))
        XCTAssertFalse(app.navigationBars["Details"].exists)
        assertOvershareIsAbsent()
        capture("for-you-patient-communication-secure-detail")
    }

    func testAttentionRowRemainsVisibleAndHittableAtXXXLWithContrastAndReducedEffects() {
        app.launchArguments += [
            "-UIPreferredContentSizeCategoryName", "UICTContentSizeCategoryAccessibilityExtraExtraExtraLarge",
            "-UIAccessibilityDarkerSystemColorsEnabled", "YES",
            "-UIAccessibilityReduceMotionEnabled", "YES",
            "-UIAccessibilityReduceTransparencyEnabled", "YES",
        ]
        app.launch()

        XCTAssertTrue(validRow.waitForExistence(timeout: 6))
        XCTAssertTrue(validRow.isHittable)
        XCTAssertTrue(app.staticTexts["Escalated patient communication"].exists)
        assertOvershareIsAbsent()
        capture("for-you-patient-communication-xxxl-contrast-reduced-effects")
    }

    private var validRow: XCUIElement {
        app.descendants(matching: .any)[
            "forYou.patientCommunication.11111111-1111-4111-8111-111111111111"
        ]
    }

    private func assertOvershareIsAbsent() {
        for forbidden in ["DO NOT SHOW", "Jane Doe", "MRN 123456", "ptok_", "patient-123"] {
            XCTAssertFalse(
                app.staticTexts.matching(NSPredicate(format: "label CONTAINS[c] %@", forbidden)).firstMatch.exists,
                "Restricted For You surface exposed \(forbidden)"
            )
        }
    }

    private func capture(_ name: String) {
        let attachment = XCTAttachment(screenshot: app.screenshot())
        attachment.name = name
        attachment.lifetime = .keepAlways
        add(attachment)
    }
}
