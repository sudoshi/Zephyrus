import XCTest

final class StaffSessionManagementUITests: XCTestCase {
    private var app: XCUIApplication!

    override func setUp() {
        super.setUp()
        continueAfterFailure = false
        app = XCUIApplication()
        app.launchArguments = ["-HBStaffSessionsUITest"]
        app.launchEnvironment = [
            "HB_STAFF_SESSIONS_UI_TEST": "1",
        ]
    }

    override func tearDown() {
        app.terminate()
        app = nil
        super.tearDown()
    }

    func testListsSafeDeviceMetadataAndRevokesAnotherSessionWithConfirmation() {
        app.launch()
        openSessions()

        XCTAssertTrue(app.navigationBars["Signed-in devices"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.staticTexts["Rounds iPhone"].exists)
        XCTAssertTrue(app.staticTexts["This device"].exists)
        XCTAssertTrue(app.staticTexts["Unit tablet"].exists)
        assertRestrictedMetadataAbsent()
        capture("staff-sessions-list")

        let revoke = app.buttons["staffSessions.revoke.22222222-2222-4222-8222-222222222222"]
        reveal(revoke)
        XCTAssertTrue(revoke.isHittable)
        revoke.tap()

        let confirmation = app.alerts["Revoke this session?"]
        XCTAssertTrue(confirmation.waitForExistence(timeout: 3))
        XCTAssertTrue(confirmation.staticTexts["That device will need to sign in again. Other devices stay signed in."].exists)
        confirmation.buttons["Revoke session"].tap()

        XCTAssertFalse(app.staticTexts["Unit tablet"].waitForExistence(timeout: 2))
        XCTAssertTrue(app.staticTexts["Rounds iPhone"].exists)
        assertRestrictedMetadataAbsent()
        capture("staff-sessions-remote-revoked")
    }

    func testSessionManagementRemainsUsableAtLargestTextAndReducedEffects() {
        app.launchArguments += [
            "-UIPreferredContentSizeCategoryName", "UICTContentSizeCategoryAccessibilityExtraExtraExtraLarge",
            "-UIAccessibilityDarkerSystemColorsEnabled", "YES",
            "-UIAccessibilityReduceMotionEnabled", "YES",
            "-UIAccessibilityReduceTransparencyEnabled", "YES",
        ]
        app.launch()
        openSessions()

        let current = app.descendants(matching: .any)[
            "staffSessions.session.11111111-1111-4111-8111-111111111111"
        ]
        XCTAssertTrue(current.waitForExistence(timeout: 5))
        reveal(current)
        XCTAssertTrue(current.exists)
        let signOut = app.buttons["staffSessions.revoke.11111111-1111-4111-8111-111111111111"]
        reveal(signOut)
        XCTAssertTrue(signOut.isHittable)
        assertRestrictedMetadataAbsent()
        capture("staff-sessions-xxxl-reduced-effects")
    }

    private func openSessions() {
        let profile = app.buttons["Profile and settings"].firstMatch
        XCTAssertTrue(profile.waitForExistence(timeout: 8))
        XCTAssertTrue(profile.isHittable)
        profile.tap()
        XCTAssertTrue(app.navigationBars["Profile"].waitForExistence(timeout: 5))

        let entry = app.buttons["profile.staffSessions"]
        reveal(entry)
        XCTAssertTrue(entry.waitForExistence(timeout: 5))
        entry.tap()
    }

    private func reveal(_ element: XCUIElement) {
        for _ in 0..<10 where !element.isHittable {
            app.swipeUp()
        }
    }

    private func assertRestrictedMetadataAbsent() {
        for forbidden in [
            "token_family_uuid",
            "refresh_token_id",
            "installation_uuid",
            "access_token",
            "refresh_token",
            "ip_address",
            "user_agent",
            "ptok_",
            "MRN",
        ] {
            XCTAssertFalse(
                app.staticTexts.matching(
                    NSPredicate(format: "label CONTAINS[c] %@", forbidden)
                ).firstMatch.exists,
                "Session management exposed \(forbidden)"
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
