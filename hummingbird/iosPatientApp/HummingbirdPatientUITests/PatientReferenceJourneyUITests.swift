import XCTest

final class PatientReferenceJourneyUITests: XCTestCase {
    private var app: XCUIApplication!

    override func setUpWithError() throws {
        continueAfterFailure = false
        app = XCUIApplication()
        app.launchEnvironment["HBP_SYNTHETIC_REFERENCE"] = "1"
        app.launch()
    }

    func testReferenceJourneyExposesCarePathTeamAndSafeMessagingLanguage() {
        XCTAssertTrue(app.tabBars.buttons["Today"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.descendants(matching: .any)["synthetic-reference-banner"].exists)
        XCTAssertTrue(app.descendants(matching: .any)["patient-freshness"].exists)
        XCTAssertTrue(scrollUntilExists(app.descendants(matching: .any)["urgent-help-notice"]))
        attachScreenshot(named: "Today")

        app.tabBars.buttons["My Path"].tap()
        XCTAssertTrue(app.staticTexts["My Path"].waitForExistence(timeout: 2))
        XCTAssertTrue(scrollUntilExists(app.descendants(matching: .any)["patient-projection-revision-notice"]))
        XCTAssertTrue(scrollUntilExists(staticText(containing: "Your care team updated this information")))
        XCTAssertTrue(scrollUntilExists(app.staticTexts["Milestones your team released"]))
        XCTAssertTrue(scrollUntilExists(app.staticTexts["Goals for your care"]))
        XCTAssertTrue(scrollUntilExists(staticText(containing: "Your goal")))
        XCTAssertTrue(scrollUntilExists(app.descendants(matching: .any)["patient-preference-guidance"]))
        XCTAssertTrue(scrollUntilExists(app.staticTexts["Share what matters to you"]))
        XCTAssertTrue(scrollUntilExists(staticText(containing: "does not automatically change your care plan")))
        XCTAssertTrue(scrollUntilExists(app.staticTexts["Learning and preparation"]))
        XCTAssertTrue(scrollUntilExists(staticText(containing: "Preparing for the next setting")))
        XCTAssertTrue(scrollUntilExists(app.staticTexts["Want to talk it through?"]))
        XCTAssertTrue(scrollUntilExists(app.descendants(matching: .any)["education-clarification-safety-guidance"]))
        XCTAssertTrue(scrollUntilExists(staticText(containing: "does not record consent, completion, or that you understand")))
        XCTAssertFalse(app.buttons["Ask for an explanation"].exists)
        XCTAssertTrue(scrollUntilExists(app.staticTexts["What has happened so far"]))
        XCTAssertTrue(scrollUntilExists(app.staticTexts["Key moments your team released"]))
        XCTAssertTrue(scrollUntilExists(staticText(containing: "Admitted to the hospital")))
        XCTAssertTrue(scrollUntilExists(staticText(containing: "Initial tests completed")))
        XCTAssertTrue(scrollUntilExists(staticText(containing: "Preparing for a bedside procedure")))
        XCTAssertTrue(scrollUntilExists(staticText(containing: "Planning transportation after you leave")))
        XCTAssertTrue(scrollUntilExists(app.staticTexts["Getting ready to leave"]))
        XCTAssertTrue(scrollUntilExists(app.staticTexts["What needs to happen"]))
        XCTAssertTrue(scrollUntilExists(staticText(containing: "Your updated medicine list")))
        XCTAssertTrue(scrollUntilExists(staticText(containing: "Your team confirms the details")))
        XCTAssertTrue(scrollUntilExists(app.staticTexts["Your care-team conversation"]))
        XCTAssertTrue(scrollUntilExists(app.staticTexts["Topics your team released"]))
        XCTAssertTrue(scrollUntilExists(staticText(containing: "A released summary, not the full conversation")))
        XCTAssertTrue(scrollUntilExists(app.staticTexts["What uncertainty means"]))
        attachScreenshot(named: "My-Path")

        app.tabBars.buttons["Care Team"].tap()
        XCTAssertTrue(app.staticTexts["Care Team"].waitForExistence(timeout: 2))
        XCTAssertTrue(scrollUntilExists(app.descendants(matching: .any)["urgent-help-notice"]))
        XCTAssertTrue(scrollUntilExists(app.descendants(matching: .any)["care-team-connection-guidance"]))
        attachScreenshot(named: "Care-Team")

        app.tabBars.buttons["Messages"].tap()
        XCTAssertTrue(app.staticTexts["Messages"].waitForExistence(timeout: 2))
        XCTAssertTrue(scrollUntilExists(app.descendants(matching: .any)["message-immediate-help"]))
        XCTAssertTrue(scrollUntilExists(app.descendants(matching: .any)["messages-no-offline-queue"]))
        XCTAssertTrue(scrollUntilExists(app.descendants(matching: .any)["messages-read-only-state"]))
        XCTAssertFalse(app.descendants(matching: .any)["new-message-composer"].exists)
        XCTAssertTrue(scrollUntilExists(staticText(containing: "synthetic preview")))
        attachScreenshot(named: "Messages")

        let threadTopic = app.staticTexts["Question about my care plan"]
        XCTAssertTrue(scrollUntilHittable(threadTopic))
        threadTopic.tap()
        XCTAssertTrue(app.descendants(matching: .any)["message-immediate-help"].waitForExistence(timeout: 3))
        XCTAssertTrue(scrollUntilExists(app.descendants(matching: .any)["message-thread-header"]))
        XCTAssertTrue(scrollUntilExists(app.staticTexts["Your mobility team will check how you are feeling and review safe support before you begin. Timing can still change."]))
        XCTAssertFalse(app.descendants(matching: .any)["message-reply-composer"].exists)
        XCTAssertFalse(app.descendants(matching: .any)["correct-message-019f0000-0000-7000-8000-000000000062"].exists)
        XCTAssertFalse(app.descendants(matching: .any)["withdraw-message-019f0000-0000-7000-8000-000000000062"].exists)
        attachScreenshot(named: "Message-Conversation")

        app.navigationBars.buttons["Messages"].tap()
        XCTAssertTrue(app.staticTexts["Messages"].waitForExistence(timeout: 2))
        let roundsQuestion = app.descendants(matching: .any)["message-thread-019f0000-0000-7000-8000-000000000064"]
        XCTAssertTrue(scrollUntilHittable(roundsQuestion))
        roundsQuestion.tap()
        XCTAssertTrue(scrollUntilExists(staticText(containing: "shared with your care team for possible review")))
        XCTAssertTrue(scrollUntilExists(staticText(containing: "may not be discussed in a particular round")))
        XCTAssertTrue(scrollUntilExists(staticText(containing: "completed their review of the question you shared")))
        attachScreenshot(named: "Rounds-Question-Status")

        app.navigationBars.buttons["Messages"].tap()
        let unsharedRoundsQuestion = app.descendants(matching: .any)["message-thread-019f0000-0000-7000-8000-000000000067"]
        XCTAssertTrue(scrollUntilHittable(unsharedRoundsQuestion))
        unsharedRoundsQuestion.tap()
        XCTAssertTrue(scrollUntilExists(app.descendants(matching: .any)["message-thread-header"]))
        XCTAssertFalse(app.buttons["Withdraw this question"].exists)
        XCTAssertFalse(app.descendants(matching: .any)["message-reply-composer"].exists)
        attachScreenshot(named: "Rounds-Question-Read-Only")
    }

    func testDefaultBuildFailsClosedWithAReadableWelcomeAndNoPatientRequest() {
        app.terminate()
        app = XCUIApplication()
        app.launchEnvironment["HBP_SYNTHETIC_REFERENCE"] = "0"
        app.launchEnvironment["HBP_PATIENT_API_ENABLED"] = "0"
        app.launch()

        XCTAssertTrue(app.descendants(matching: .any)["patient-welcome"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.descendants(matching: .any)["patient-api-off-state"].exists)
        XCTAssertFalse(app.buttons["Sign in securely"].isEnabled)
        attachScreenshot(named: "Welcome-API-Off")
    }

    func testPrivacyCoverHidesCareContentWithCalmBranding() {
        app.terminate()
        app = XCUIApplication()
        app.launchEnvironment["HBP_SYNTHETIC_REFERENCE"] = "1"
        app.launchEnvironment["HBP_SHOW_PRIVACY_COVER"] = "1"
        app.launch()

        let cover = app.descendants(matching: .any)["patient-privacy-cover"]
        XCTAssertTrue(cover.waitForExistence(timeout: 5))
        XCTAssertFalse(app.tabBars.buttons["Today"].isHittable)
        attachScreenshot(named: "Privacy-Cover")
    }

    func testAuthenticationFailureUsesAReadablePatientSafeErrorState() {
        app.terminate()
        app = XCUIApplication()
        app.launchEnvironment["HBP_SYNTHETIC_REFERENCE"] = "0"
        app.launchEnvironment["HBP_PATIENT_API_ENABLED"] = "1"
        // Explicit IPv4 loopback and the reserved unusable port avoid the dual-stack
        // localhost fallback delay while preserving the HTTPS-only transport boundary.
        app.launchEnvironment["HBP_PATIENT_API_BASE_URL"] = "https://127.0.0.1:1"
        app.launch()

        let email = app.textFields["Email"]
        XCTAssertTrue(email.waitForExistence(timeout: 5))
        email.tap()
        email.typeText("sample@example.test")
        let password = app.secureTextFields["Password"]
        password.tap()
        password.typeText("synthetic-password")
        app.buttons["Sign in securely"].tap()

        XCTAssertTrue(
            app.descendants(matching: .any)["patient-error-state"]
                .waitForExistence(timeout: 10)
        )
        XCTAssertFalse(app.staticTexts["staff"].exists)
        attachScreenshot(named: "Authentication-Error")
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

    private func scrollUntilExists(
        _ element: XCUIElement,
        maximumSwipes: Int = 12
    ) -> Bool {
        if element.exists { return true }
        for _ in 0 ..< maximumSwipes {
            app.swipeUp()
            if element.waitForExistence(timeout: 0.35) { return true }
        }
        return false
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

    private func staticText(containing text: String) -> XCUIElement {
        app.staticTexts
            .matching(NSPredicate(format: "label CONTAINS %@", text))
            .firstMatch
    }
}
