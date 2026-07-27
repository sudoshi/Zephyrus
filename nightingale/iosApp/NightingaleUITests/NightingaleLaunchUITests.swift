import XCTest

final class NightingaleLaunchUITests: XCTestCase {
    override func tearDown() {
        XCUIDevice.shared.orientation = .portrait
        super.tearDown()
    }

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
        XCTAssertTrue(
            app.staticTexts[
                "A calming Nightingale background is shown softly behind the page."
            ].waitForExistence(timeout: 5)
        )

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

        let app = resetApplication()
        app.launchEnvironment["NIGHTINGALE_TEST_ACCESSIBILITY_TEXT_SIZE"] = "1"
        app.launch()

        XCTAssertGreaterThan(
            app.frame.width,
            app.frame.height,
            "The released app contract must expose a landscape viewport."
        )

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
        XCTAssertTrue(reduceMotion.waitForExistence(timeout: 5))
        XCTAssertGreaterThanOrEqual(reduceMotion.frame.height, 44)
        reduceMotion.tap()
        XCTAssertEqual(reduceMotion.value as? String, "1")

        XCTAssertTrue(hideImagery.waitForExistence(timeout: 5))
        XCTAssertGreaterThanOrEqual(hideImagery.frame.height, 44)
        hideImagery.tap()
        XCTAssertEqual(hideImagery.value as? String, "1")
    }

    func testDoubleLengthPseudolanguageReflowsAndKeepsControlsReachable() {
        let device = XCUIDevice.shared
        device.orientation = .landscapeLeft

        let app = resetApplication()
        app.launchArguments += ["-NSDoubleLocalizedStrings", "YES"]
        app.launchEnvironment["NIGHTINGALE_TEST_ACCESSIBILITY_TEXT_SIZE"] = "1"
        app.launch()

        let sourceMission =
            "A calm place to understand, prepare, and connect with your care team."
        let mission = app.staticTexts["nightingale-foundation-mission"]
        XCTAssertTrue(mission.waitForExistence(timeout: 5))
        XCTAssertGreaterThan(
            mission.label.count,
            sourceMission.count,
            "The Apple double-length pseudolanguage must affect rendered localized copy."
        )

        for identifier in [
            "nightingale-reduce-motion-toggle",
            "nightingale-hide-imagery-toggle",
        ] {
            let control = app.switches[identifier]
            XCTAssertTrue(control.waitForExistence(timeout: 5))
            XCTAssertGreaterThanOrEqual(control.frame.height, 44)
            control.tap()
            XCTAssertEqual(control.value as? String, "1")
        }
    }

    func testRightToLeftLayoutDirectionMirrorsTheShellWithoutChangingSemanticOrder() {
        let leftToRight = resetApplication()
        leftToRight.launch()
        let leftToRightHeading = leftToRight.staticTexts["nightingale-product-heading"]
        XCTAssertTrue(leftToRightHeading.waitForExistence(timeout: 5))
        let leftToRightMinimumX = leftToRightHeading.frame.minX
        leftToRight.terminate()

        let rightToLeft = resetApplication()
        rightToLeft.launchArguments += [
            "-AppleLanguages",
            "(ar)",
            "-AppleLocale",
            "ar_SA",
            "-AppleTextDirection",
            "YES",
        ]
        rightToLeft.launchEnvironment["NIGHTINGALE_TEST_LAYOUT_DIRECTION"] = "RTL"
        rightToLeft.launch()

        let rightToLeftHeading = rightToLeft.staticTexts["nightingale-product-heading"]
        XCTAssertTrue(rightToLeftHeading.waitForExistence(timeout: 5))
        XCTAssertGreaterThan(
            rightToLeftHeading.frame.minX,
            leftToRightMinimumX + 40,
            "The right-to-left pseudolanguage must mirror leading alignment."
        )

        let orderedIdentifiers = rightToLeft.descendants(matching: .any)
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

        let hideImagery = rightToLeft.switches["nightingale-hide-imagery-toggle"]
        XCTAssertTrue(hideImagery.waitForExistence(timeout: 5))
        let valueBeforeTap = hideImagery.value as? String
        hideImagery.tap()
        XCTAssertNotEqual(
            hideImagery.value as? String,
            valueBeforeTap,
            "The mirrored switch must remain operable in either persisted state."
        )
    }

    private func resetApplication() -> XCUIApplication {
        let app = XCUIApplication()
        app.launchEnvironment["NIGHTINGALE_TEST_RESET_PRESENTATION_PREFERENCES"] = "1"
        return app
    }
}
