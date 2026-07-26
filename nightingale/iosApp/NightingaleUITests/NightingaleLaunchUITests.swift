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

    func testLargestTextLandscapeKeepsContentOrderedReachableAndTouchable() {
        let device = XCUIDevice.shared
        device.orientation = .landscapeLeft
        defer { device.orientation = .portrait }

        let app = resetApplication()
        app.launchEnvironment["NIGHTINGALE_TEST_ACCESSIBILITY_TEXT_SIZE"] = "1"
        app.launch()

        let productHeading = app.staticTexts["nightingale-product-heading"]
        XCTAssertTrue(productHeading.waitForExistence(timeout: 5))
        XCTAssertGreaterThanOrEqual(productHeading.frame.height, 60)

        let orderedIdentifiers = app.descendants(matching: .any)
            .allElementsBoundByAccessibilityElement
            .map(\.identifier)
            .filter {
                [
                    "nightingale-product-heading",
                    "nightingale-privacy-status-heading",
                    "nightingale-display-comfort-heading",
                    "nightingale-reduce-motion-toggle",
                    "nightingale-hide-imagery-toggle",
                ].contains($0)
            }
        XCTAssertEqual(
            orderedIdentifiers,
            [
                "nightingale-product-heading",
                "nightingale-privacy-status-heading",
                "nightingale-display-comfort-heading",
                "nightingale-reduce-motion-toggle",
                "nightingale-hide-imagery-toggle",
            ]
        )

        let reduceMotion = app.switches["nightingale-reduce-motion-toggle"]
        let hideImagery = app.switches["nightingale-hide-imagery-toggle"]
        scrollToHittable(reduceMotion, in: app)
        XCTAssertGreaterThanOrEqual(reduceMotion.frame.height, 44)
        reduceMotion.tap()
        XCTAssertEqual(reduceMotion.value as? String, "1")

        scrollToHittable(hideImagery, in: app)
        XCTAssertGreaterThanOrEqual(hideImagery.frame.height, 44)
        hideImagery.tap()
        XCTAssertEqual(hideImagery.value as? String, "1")
    }

    private func resetApplication() -> XCUIApplication {
        let app = XCUIApplication()
        app.launchEnvironment["NIGHTINGALE_TEST_RESET_PRESENTATION_PREFERENCES"] = "1"
        return app
    }

    private func scrollToHittable(
        _ element: XCUIElement,
        in app: XCUIApplication,
        maxSwipes: Int = 12
    ) {
        XCTAssertTrue(element.waitForExistence(timeout: 5))
        var remainingSwipes = maxSwipes
        while !element.isHittable, remainingSwipes > 0 {
            app.swipeUp()
            remainingSwipes -= 1
        }
        XCTAssertTrue(element.isHittable)
    }
}
