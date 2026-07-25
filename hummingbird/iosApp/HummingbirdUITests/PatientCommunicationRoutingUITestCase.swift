import XCTest

/// Shared launch configuration and interaction helpers for the staff
/// patient-communication routing journeys. The journeys live in two concrete
/// classes (routing mutations vs revocation purges) because XCTest
/// distributes work to parallel simulator clones per-class — one 11-test
/// class would pin a whole worker while the other idles. XCTest skips this
/// base class itself: it defines no test methods.
class PatientCommunicationRoutingUITestCase: XCTestCase {
    var app: XCUIApplication!

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

    func openRoutingAction(_ identifier: String) {
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

    func openDetail() {
        let messages = app.tabBars.buttons["Messages"]
        XCTAssertTrue(messages.waitForExistence(timeout: 5))
        messages.tap()

        let row = app.descendants(matching: .any)[
            "patientCommunications.row.11111111-1111-4111-8111-111111111111"
        ]
        XCTAssertTrue(row.waitForExistence(timeout: 5))
        row.tap()
    }

    func launchScenario(_ scenario: String, seededDraft: String? = nil) {
        if app.state != .notRunning { app.terminate() }
        app = XCUIApplication()
        app.launchArguments = ["-HBStaffCommunicationsUITest"]
        app.launchEnvironment = [
            "HB_STAFF_COMM_UI_TEST": "1",
            "HB_STAFF_COMM_UI_SCENARIO": scenario,
        ]
        if let seededDraft {
            app.launchEnvironment["HB_STAFF_COMM_UI_SEEDED_DRAFT"] = seededDraft
        }
        app.launch()
    }

    func enterDraft(_ text: String) {
        let editor = app.textViews["patientCommunications.replyEditor"]
        XCTAssertTrue(editor.waitForExistence(timeout: 5))
        editor.tap()
        editor.typeText(text)
        let typedValue = XCTNSPredicateExpectation(
            predicate: NSPredicate(format: "value CONTAINS %@", text),
            object: editor
        )
        XCTAssertEqual(
            XCTWaiter.wait(for: [typedValue], timeout: 2),
            .completed,
            "Expected the reply editor to contain the exact test body; actual value: \(String(describing: editor.value))"
        )
    }

    func assertSeededDraft(_ text: String) {
        let editor = app.textViews["patientCommunications.replyEditor"]
        XCTAssertTrue(editor.waitForExistence(timeout: 5))
        XCTAssertTrue((editor.value as? String)?.contains(text) == true)
    }

    func triggerDetailRefresh() {
        // These scenarios schedule a deterministic seven-second authorization refresh
        // from the detail view. Do not tap an element that the refresh is allowed to
        // remove between accessibility lookup and event synthesis; the state-specific
        // assertion immediately after this helper is the actual completion barrier.
        _ = app.navigationBars["Patient conversation"].waitForExistence(timeout: 1)
    }

    func elementContaining(_ text: String) -> XCUIElement {
        app.descendants(matching: .any)
            .matching(NSPredicate(format: "label CONTAINS[c] %@", text))
            .firstMatch
    }

    func reveal(_ element: XCUIElement) {
        for _ in 0..<8 where !element.isHittable {
            app.swipeUp()
        }
    }

    func capture(_ name: String) {
        let attachment = XCTAttachment(screenshot: app.screenshot())
        attachment.name = name
        attachment.lifetime = .keepAlways
        add(attachment)
    }
}
