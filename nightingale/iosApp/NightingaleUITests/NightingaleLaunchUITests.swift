import XCTest

final class NightingaleLaunchUITests: XCTestCase {
    func testLaunchShowsTheSafePatientFoundationMessage() {
        let app = XCUIApplication()
        app.launch()

        XCTAssertTrue(app.staticTexts["Nightingale"].waitForExistence(timeout: 5))
        XCTAssertTrue(
            app.staticTexts[
                "Live patient access is not available in this foundation build. Please ask your care team for current information."
            ].waitForExistence(timeout: 5)
        )
    }

    func testPrivacyCoverHidesFoundationContentWithNightingaleIdentity() {
        let app = XCUIApplication()
        app.launchEnvironment["NIGHTINGALE_SHOW_PRIVACY_COVER"] = "1"
        app.launch()

        let privacyCover = app.descendants(matching: .any)["nightingale-privacy-cover"]
        XCTAssertTrue(privacyCover.waitForExistence(timeout: 5))
        XCTAssertFalse(app.staticTexts["Your privacy comes first"].isHittable)
    }
}
