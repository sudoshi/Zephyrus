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
}
