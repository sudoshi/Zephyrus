import XCTest

extension XCUIElement {
    /// Waits for the element to become enabled instead of sampling `isEnabled` once.
    ///
    /// An element's enabled state settles asynchronously after whatever changed it —
    /// typing into a reply editor, picking a routing target — so a bare
    /// `XCTAssertTrue(element.isEnabled)` races the UI and fails intermittently on a
    /// loaded CI runner. `waitForExistence(timeout:)` is not a substitute: the button
    /// already exists, it is simply still disabled.
    ///
    /// The sibling `reveal(_:)` helpers in these suites poll `isHittable` while
    /// swiping, which is why the `isHittable` assertions are stable and these are not.
    @discardableResult
    func waitForEnabled(timeout: TimeInterval = 5) -> Bool {
        if isEnabled {
            return true
        }

        let becomesEnabled = XCTNSPredicateExpectation(
            predicate: NSPredicate(format: "isEnabled == true"),
            object: self,
        )

        return XCTWaiter().wait(for: [becomesEnabled], timeout: timeout) == .completed
    }
}
