import XCTest

final class PatientSessionManagementUITests: XCTestCase {
    private let currentSessionUUID = "019f0000-0000-7000-8000-000000000081"
    private let otherSessionUUID = "019f0000-0000-7000-8000-000000000082"
    private var app: XCUIApplication!

    override func setUpWithError() throws {
        continueAfterFailure = false
        app = XCUIApplication()
        app.launchEnvironment["HBP_SYNTHETIC_REFERENCE"] = "1"
        app.launchEnvironment["HBP_PATIENT_SESSION_FIXTURE"] = "1"
        if name.contains("AccessibilityLayout") {
            app.launchArguments += [
                "-UIPreferredContentSizeCategoryName",
                "UICTContentSizeCategoryAccessibilityExtraExtraExtraLarge",
            ]
        }
        app.launch()
    }

    func testManageDevicesLoadsOnExplicitOpenAndRevokesAnotherDeviceWithDistinctConfirmation() {
        openManageDevices()

        XCTAssertTrue(app.staticTexts["This device"].waitForExistence(timeout: 3))
        XCTAssertTrue(app.staticTexts["Home tablet"].exists)
        XCTAssertTrue(app.staticTexts["This iPhone"].exists)

        let revokeOther = app.descendants(matching: .any)["revoke-other-session-\(otherSessionUUID)"]
        XCTAssertTrue(scrollUntilHittable(revokeOther))
        revokeOther.tap()

        XCTAssertTrue(app.staticTexts["Sign out Home tablet?"].waitForExistence(timeout: 2))
        XCTAssertTrue(
            app.staticTexts["This signs out Home tablet from Hummingbird Patient. It will not sign out this device."].exists
        )
        app.buttons.matching(identifier: "confirm-other-session-revocation").firstMatch.tap()

        XCTAssertFalse(app.staticTexts["Home tablet"].waitForExistence(timeout: 1))
        XCTAssertTrue(app.staticTexts["That device is now signed out."].waitForExistence(timeout: 2))
        XCTAssertTrue(app.staticTexts["This device"].exists)
        attachScreenshot(named: "Manage-Devices-Other-Revoked")
    }

    func testAccessibilityLayoutCanConfirmCurrentDeviceRevocationAndReturnsToWelcome() {
        openManageDevices()

        let revokeCurrent = app.descendants(matching: .any)["revoke-current-session-\(currentSessionUUID)"]
        XCTAssertTrue(scrollUntilHittable(revokeCurrent))
        revokeCurrent.tap()

        XCTAssertTrue(app.staticTexts["Sign out here?"].waitForExistence(timeout: 2))
        XCTAssertTrue(
            app.staticTexts["Signing out this device immediately closes Hummingbird Patient here and returns you to the welcome screen."].exists
        )
        attachScreenshot(named: "Manage-Devices-Current-Confirmation-Accessibility")
        app.buttons.matching(identifier: "confirm-current-session-revocation").firstMatch.tap()

        XCTAssertTrue(
            app.descendants(matching: .any)["patient-welcome"]
                .waitForExistence(timeout: 5)
        )
        XCTAssertFalse(app.descendants(matching: .any)["patient-session-management"].exists)
        attachScreenshot(named: "Current-Device-Revoked-Welcome-Accessibility")
    }

    func testPreferencesArePatientSafeAndReferenceModeDoesNotWriteAnAccount() {
        let account = app.buttons["account-options"]
        XCTAssertTrue(account.waitForExistence(timeout: 5))
        account.tap()
        let preferences = app.buttons["Preferences"]
        XCTAssertTrue(preferences.waitForExistence(timeout: 2))
        preferences.tap()

        XCTAssertTrue(app.descendants(matching: .any)["patient-preferences"].waitForExistence(timeout: 3))
        XCTAssertTrue(
            app.staticTexts.containing(NSPredicate(format: "label CONTAINS %@", "do not change your care plan"))
                .firstMatch.exists
        )
        app.buttons["save-patient-preferences"].tap()
        XCTAssertTrue(
            app.staticTexts.containing(NSPredicate(format: "label CONTAINS %@", "No patient account was changed"))
                .firstMatch.waitForExistence(timeout: 3)
        )
        attachScreenshot(named: "Patient-Preferences-Reference")
    }

    func testSavedAccessibilityPreferencesApplyAHighContrastExtraLargeCareView() {
        let account = app.buttons["account-options"]
        XCTAssertTrue(account.waitForExistence(timeout: 5))
        account.tap()
        app.buttons["Preferences"].tap()

        let textSize = app.descendants(matching: .any)["patient-preference-text-size"]
        XCTAssertTrue(textSize.waitForExistence(timeout: 2))
        textSize.tap()
        let extraLarge = app.buttons["Extra large"]
        XCTAssertTrue(extraLarge.waitForExistence(timeout: 2))
        extraLarge.tap()

        let highContrast = app.switches["patient-preference-high-contrast"]
        XCTAssertTrue(highContrast.waitForExistence(timeout: 2))
        if (highContrast.value as? String) == "0" {
            highContrast.coordinate(withNormalizedOffset: CGVector(dx: 0.9, dy: 0.5)).tap()
        }
        expectation(for: NSPredicate(format: "value == '1'"), evaluatedWith: highContrast)
        waitForExpectations(timeout: 2)
        app.buttons["save-patient-preferences"].tap()

        XCTAssertTrue(app.buttons["Done"].waitForExistence(timeout: 2))
        app.buttons["Done"].tap()
        XCTAssertTrue(
            app.descendants(matching: .any)["patient-presentation-preference-notice"]
                .waitForExistence(timeout: 3)
        )
        XCTAssertTrue(
            app.staticTexts.containing(NSPredicate(format: "label CONTAINS %@", "high contrast"))
                .firstMatch.waitForExistence(timeout: 2)
        )
        attachScreenshot(named: "Patient-Preferences-High-Contrast-Extra-Large")
    }

    private func openManageDevices() {
        let account = app.buttons["account-options"]
        XCTAssertTrue(account.waitForExistence(timeout: 5))
        account.tap()
        let manageDevices = app.buttons["Manage devices"]
        XCTAssertTrue(manageDevices.waitForExistence(timeout: 2))
        manageDevices.tap()
        XCTAssertTrue(
            app.descendants(matching: .any)["patient-session-management"]
                .waitForExistence(timeout: 5)
        )
    }

    private func scrollUntilHittable(
        _ element: XCUIElement,
        maximumSwipes: Int = 12
    ) -> Bool {
        if element.exists, element.isHittable { return true }
        for _ in 0 ..< maximumSwipes {
            app.swipeUp()
            if element.waitForExistence(timeout: 0.35), element.isHittable { return true }
        }
        return false
    }

    private func attachScreenshot(named name: String) {
        let attachment = XCTAttachment(screenshot: app.screenshot())
        attachment.name = name
        attachment.lifetime = .keepAlways
        add(attachment)

        let hierarchy = XCTAttachment(string: app.debugDescription)
        hierarchy.name = "\(name)-Accessibility-Hierarchy"
        hierarchy.lifetime = .keepAlways
        add(hierarchy)
    }
}
