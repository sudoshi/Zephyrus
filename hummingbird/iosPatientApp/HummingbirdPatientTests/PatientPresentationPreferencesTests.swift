import XCTest
@testable import HummingbirdPatient

final class PatientPresentationPreferencesTests: XCTestCase {
    func testExtraLargePreferenceNeverShrinksAStrongerSystemDynamicTypeSize() {
        let preferences = PatientPresentationPreferences(
            PatientPreferences(textSize: .extraLarge, reducedMotion: true, highContrast: true)
        )

        XCTAssertEqual(
            preferences.effectiveDynamicTypeSize(systemSize: .large),
            .accessibility1
        )
        XCTAssertEqual(
            preferences.effectiveDynamicTypeSize(systemSize: .accessibility4),
            .accessibility4
        )
        XCTAssertTrue(preferences.reducedMotion)
        XCTAssertTrue(preferences.highContrast)
        XCTAssertEqual(
            preferences.accessibilityIdentifier,
            "patient-presentation-extra_large-high-contrast"
        )
    }

    func testStandardPreferencePreservesTheSystemSize() {
        let preferences = PatientPresentationPreferences()

        XCTAssertEqual(
            preferences.effectiveDynamicTypeSize(systemSize: .accessibility2),
            .accessibility2
        )
        XCTAssertFalse(preferences.highContrast)
        XCTAssertFalse(preferences.reducedMotion)
    }
}
