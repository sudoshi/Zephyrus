import XCTest

final class NightingaleLaunchUITests: XCTestCase {
    func testLaunchShowsTheSafePatientFoundationMessage() {
        let app = resetApplication()
        app.launch()

        XCTAssertTrue(app.staticTexts["Nightingale"].waitForExistence(timeout: 5))
        XCTAssertTrue(
            app.staticTexts[
                "Live patient access is not available in this foundation build. Please ask your care team for current information."
            ].waitForExistence(timeout: 5)
        )
    }

    func testPrivacyCoverHidesFoundationContentWithNightingaleIdentity() {
        let app = resetApplication()
        app.launchEnvironment["NIGHTINGALE_SHOW_PRIVACY_COVER"] = "1"
        app.launch()

        let privacyCover = app.descendants(matching: .any)["nightingale-privacy-cover"]
        XCTAssertTrue(privacyCover.waitForExistence(timeout: 5))
        XCTAssertFalse(app.staticTexts["Your privacy comes first"].isHittable)
    }

    func testDisplayComfortControlsPersistAndExplainTheirEffect() {
        let app = resetApplication()
        app.launch()
        app.swipeUp()

        let reduceMotion = app.switches["nightingale-reduce-motion-toggle"]
        let hideImagery = app.switches["nightingale-hide-imagery-toggle"]
        XCTAssertTrue(reduceMotion.waitForExistence(timeout: 5))
        XCTAssertTrue(hideImagery.waitForExistence(timeout: 5))
        XCTAssertEqual(reduceMotion.value as? String, "0")
        XCTAssertEqual(hideImagery.value as? String, "0")

        reduceMotion.tap()
        XCTAssertTrue(
            app.staticTexts[
                "Motion is reduced. Nightingale changes views without decorative movement."
            ].waitForExistence(timeout: 5)
        )

        hideImagery.tap()
        XCTAssertTrue(
            app.staticTexts[
                "Decorative imagery is hidden. Essential text and controls remain available."
            ].waitForExistence(timeout: 5)
        )

        app.terminate()

        let relaunched = XCUIApplication()
        relaunched.launch()
        relaunched.swipeUp()
        XCTAssertEqual(
            relaunched.switches["nightingale-reduce-motion-toggle"].value as? String,
            "1"
        )
        XCTAssertEqual(
            relaunched.switches["nightingale-hide-imagery-toggle"].value as? String,
            "1"
        )
    }

    private func resetApplication() -> XCUIApplication {
        let app = XCUIApplication()
        app.launchEnvironment["NIGHTINGALE_TEST_RESET_PRESENTATION_PREFERENCES"] = "1"
        return app
    }
}
