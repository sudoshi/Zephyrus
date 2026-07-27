import Security
import UIKit
import XCTest
@testable import Nightingale

final class NightingaleProductBoundaryTests: XCTestCase {
    func testFoundationHasNoLivePatientOrStaffAccess() {
        XCTAssertEqual(NightingaleProductBoundary.productName, "Nightingale")
        XCTAssertFalse(NightingaleProductBoundary.livePatientAccessEnabled)
        XCTAssertFalse(NightingaleProductBoundary.staffEndpointsPermitted)
    }

    func testPrivacyManifestDeclaresExactFoundationPosture() throws {
        let manifestURL = try XCTUnwrap(
            Bundle.main.url(forResource: "PrivacyInfo", withExtension: "xcprivacy")
        )
        let manifestData = try Data(contentsOf: manifestURL)
        let rawManifest = try PropertyListSerialization.propertyList(
            from: manifestData,
            options: [],
            format: nil
        )
        let manifest = try XCTUnwrap(rawManifest as? [String: Any])

        XCTAssertEqual(manifest["NSPrivacyTracking"] as? Bool, false)
        XCTAssertNil(manifest["NSPrivacyTrackingDomains"])
        XCTAssertTrue(
            try XCTUnwrap(manifest["NSPrivacyCollectedDataTypes"] as? [Any]).isEmpty
        )

        let accessedTypes = try XCTUnwrap(
            manifest["NSPrivacyAccessedAPITypes"] as? [[String: Any]]
        )
        XCTAssertEqual(accessedTypes.count, 1)
        let userDefaults = try XCTUnwrap(accessedTypes.first)
        XCTAssertEqual(
            userDefaults["NSPrivacyAccessedAPIType"] as? String,
            "NSPrivacyAccessedAPICategoryUserDefaults"
        )
        XCTAssertEqual(
            userDefaults["NSPrivacyAccessedAPITypeReasons"] as? [String],
            ["CA92.1"]
        )
    }

    func testPackagedFoundationCopyIsExactEnglishOnlySourceMaterial() throws {
        let localizedURL = try XCTUnwrap(
            Bundle.main.url(
                forResource: "Localizable",
                withExtension: "strings",
                subdirectory: nil,
                localization: "en"
            )
        )
        let localizedData = try Data(contentsOf: localizedURL)
        let rawCopy = try PropertyListSerialization.propertyList(
            from: localizedData,
            options: [],
            format: nil
        )
        let copy = try XCTUnwrap(rawCopy as? [String: String])

        XCTAssertEqual(
            copy,
            [
                "app_name": "Nightingale",
                "display_comfort_heading": "Display comfort",
                "display_comfort_scope":
                    "These settings are stored by Nightingale, not your care account. They never change your care information.",
                "foundation_mission":
                    "A calm place to understand, prepare, and connect with your care team.",
                "foundation_no_patient_data":
                    "No patient information is stored or requested by this build.",
                "foundation_unavailable":
                    "Live patient access is not available in this foundation build. Please ask your care team for current information.",
                "hide_imagery_label": "Hide decorative imagery",
                "imagery_hidden_status":
                    "Decorative imagery is hidden. Essential text and controls remain available.",
                "imagery_shown_status":
                    "A calming Nightingale background is shown softly behind the page.",
                "motion_reduced_status":
                    "Motion is reduced. Nightingale changes views without decorative movement.",
                "motion_standard_status":
                    "Gentle transitions are enabled. Nightingale also follows your system Reduce Motion setting.",
                "privacy_cover_accessibility_label":
                    "Privacy cover. Your care information is hidden while Nightingale is not active.",
                "privacy_cover_message":
                    "Your care information is covered while the app is not active.",
                "privacy_heading": "Your privacy comes first",
                "reduce_motion_label": "Reduce motion in Nightingale",
            ]
        )
        XCTAssertTrue(Bundle.main.localizations.contains("en"))
        XCTAssertFalse(Bundle.main.localizations.contains("en-XA"))
        XCTAssertFalse(Bundle.main.localizations.contains("ar-XB"))
    }

    func testProtectedStateNamespaceIsNightingaleOnlyAndCredentialAgnostic() {
        XCTAssertEqual(
            NightingaleProtectedStateNamespace.keychainService,
            "net.acumenus.nightingale.protected-state.v1"
        )
        XCTAssertEqual(
            NightingaleProtectedStateNamespace.futureSessionBindingAccount,
            "future-session-binding-v1"
        )

        let combined =
            NightingaleProtectedStateNamespace.keychainService
            + NightingaleProtectedStateNamespace.futureSessionBindingAccount
        XCTAssertFalse(combined.localizedCaseInsensitiveContains("hummingbird"))
        XCTAssertFalse(combined.localizedCaseInsensitiveContains("access-token"))
        XCTAssertFalse(combined.localizedCaseInsensitiveContains("refresh-token"))
        XCTAssertFalse(combined.localizedCaseInsensitiveContains("device-uuid"))
    }

    func testProtectedStateRejectsEmptyValues() throws {
        let store = KeychainNightingaleProtectedStateStore()
        XCTAssertThrowsError(try store.writeFutureSessionBinding(Data())) { error in
            XCTAssertEqual(error as? NightingaleProtectedStateError, .emptyValue)
        }
    }

    func testSyntheticProtectedStateCanaryRoundTripsAndDeletesIdempotently() throws {
        let store = KeychainNightingaleProtectedStateStore()
        _ = try? store.deleteAll()
        defer { _ = try? store.deleteAll() }

        let canary = Data("synthetic-nightingale-keychain-canary".utf8)
        try store.writeFutureSessionBinding(canary)
        XCTAssertEqual(try store.readFutureSessionBinding(), canary)
        let attributes = try keychainAttributesForSyntheticBinding()
        XCTAssertEqual(
            attributes[kSecAttrService as String] as? String,
            NightingaleProtectedStateNamespace.keychainService
        )
        XCTAssertEqual(
            attributes[kSecAttrAccessible as String] as? String,
            kSecAttrAccessibleWhenUnlockedThisDeviceOnly as String
        )
        XCTAssertNotEqual(attributes[kSecAttrSynchronizable as String] as? Bool, true)

        XCTAssertEqual(try store.deleteAll(), .deleted)
        XCTAssertNil(try store.readFutureSessionBinding())
        XCTAssertEqual(try store.deleteAll(), .alreadyAbsent)
    }

    @MainActor
    func testVolatileInputClearsAtEverySensitiveBoundary() {
        let state = NightingaleVolatileInputState()
        let reasons: [NightingaleVolatileInputClearReason] = [
            .applicationInactive,
            .logout,
            .identityTransition,
            .recovery,
            .revocation,
            .localRemoval,
        ]

        for reason in reasons {
            state.replaceDraftForComposition("synthetic draft that must remain volatile")
            XCTAssertTrue(state.hasDraft)
            state.clear(reason)
            XCTAssertFalse(state.hasDraft)
            XCTAssertEqual(state.lastClearReason, reason)
        }
    }

    func testPresentationPolicyCombinesPatientAndSystemAccessibilityDecisions() {
        let standard = NightingaleSceneAccessibilityPolicy.resolve(
            preferences: NightingalePresentationPreferenceSnapshot(
                reduceMotionRequested: false,
                hideDecorativeImageryRequested: false
            ),
            systemReduceMotion: false,
            systemReduceTransparency: false,
            increasedContrast: false,
            accessibilityTextSize: false,
            darkMode: false
        )
        let patientReduced = NightingaleSceneAccessibilityPolicy.resolve(
            preferences: NightingalePresentationPreferenceSnapshot(
                reduceMotionRequested: true,
                hideDecorativeImageryRequested: true
            ),
            systemReduceMotion: false,
            systemReduceTransparency: false,
            increasedContrast: false,
            accessibilityTextSize: false,
            darkMode: false
        )
        let systemReduced = NightingaleSceneAccessibilityPolicy.resolve(
            preferences: NightingalePresentationPreferenceSnapshot(
                reduceMotionRequested: false,
                hideDecorativeImageryRequested: false
            ),
            systemReduceMotion: true,
            systemReduceTransparency: true,
            increasedContrast: true,
            accessibilityTextSize: true,
            darkMode: true
        )
        let accessibilityText = NightingaleSceneAccessibilityPolicy.resolve(
            preferences: NightingalePresentationPreferenceSnapshot(
                reduceMotionRequested: false,
                hideDecorativeImageryRequested: false
            ),
            systemReduceMotion: false,
            systemReduceTransparency: false,
            increasedContrast: false,
            accessibilityTextSize: true,
            darkMode: false
        )

        XCTAssertFalse(standard.reduceMotion)
        XCTAssertTrue(standard.showDecorativeImagery)
        XCTAssertEqual(standard.decorativeImageOpacity, 1)
        XCTAssertEqual(standard.backgroundScrimAlphas, [0.46, 0.70, 0.88])
        XCTAssertEqual(standard.cardOpacity, 0.96)
        XCTAssertEqual(standard.transitionDuration, 0.18)

        XCTAssertTrue(patientReduced.reduceMotion)
        XCTAssertFalse(patientReduced.showDecorativeImagery)
        XCTAssertEqual(patientReduced.decorativeImageOpacity, 0)
        XCTAssertEqual(patientReduced.backgroundScrimAlphas, [1, 1, 1])
        XCTAssertEqual(patientReduced.transitionDuration, 0)

        XCTAssertTrue(systemReduced.reduceMotion)
        XCTAssertFalse(systemReduced.showDecorativeImagery)
        XCTAssertEqual(systemReduced.decorativeImageOpacity, 0)
        XCTAssertEqual(systemReduced.backgroundScrimAlphas, [1, 1, 1])
        XCTAssertEqual(systemReduced.cardOpacity, 1)
        XCTAssertEqual(systemReduced.transitionDuration, 0)

        XCTAssertTrue(accessibilityText.showDecorativeImagery)
        XCTAssertEqual(accessibilityText.decorativeImageOpacity, 1)
        XCTAssertEqual(accessibilityText.backgroundScrimAlphas, [0.72, 0.88, 0.97])
    }

    func testBackgroundCatalogIsExactStableForLocalDayAndRotatesWithoutMotion() throws {
        XCTAssertEqual(
            NightingaleBackgroundCatalog.assetNames,
            [
                "nightingale_background_01",
                "nightingale_background_02",
                "nightingale_background_03",
                "nightingale_background_04",
                "nightingale_background_05",
                "nightingale_background_06",
                "nightingale_background_07",
            ]
        )
        XCTAssertEqual(Set(NightingaleBackgroundCatalog.assetNames).count, 7)

        for assetName in NightingaleBackgroundCatalog.assetNames {
            let resourceURL = Bundle.main.url(
                forResource: assetName,
                withExtension: "jpg"
            )
            XCTAssertNotNil(
                resourceURL,
                "Missing governed Nightingale background resource: \(assetName)"
            )
            if let resourceURL {
                XCTAssertNotNil(
                    UIImage(contentsOfFile: resourceURL.path),
                    "Unreadable governed Nightingale background resource: \(assetName)"
                )
            }
        }

        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = try XCTUnwrap(TimeZone(secondsFromGMT: 0))
        let morning = try XCTUnwrap(
            calendar.date(
                from: DateComponents(
                    year: 2026,
                    month: 7,
                    day: 26,
                    hour: 1
                )
            )
        )
        let evening = try XCTUnwrap(
            calendar.date(
                from: DateComponents(
                    year: 2026,
                    month: 7,
                    day: 26,
                    hour: 23
                )
            )
        )
        let nextDay = try XCTUnwrap(
            calendar.date(byAdding: .day, value: 1, to: morning)
        )

        let morningIndex = NightingaleBackgroundCatalog.index(
            for: morning,
            calendar: calendar
        )
        XCTAssertEqual(morningIndex, 3)
        XCTAssertEqual(
            morningIndex,
            NightingaleBackgroundCatalog.index(for: evening, calendar: calendar)
        )
        XCTAssertEqual(
            NightingaleBackgroundCatalog.index(for: nextDay, calendar: calendar),
            (morningIndex + 1) % NightingaleBackgroundCatalog.assetNames.count
        )
        var nonGregorianCalendar = Calendar(identifier: .buddhist)
        nonGregorianCalendar.timeZone = calendar.timeZone
        XCTAssertEqual(
            NightingaleBackgroundCatalog.index(
                for: morning,
                calendar: nonGregorianCalendar
            ),
            morningIndex
        )

        let unixEpoch = try XCTUnwrap(
            calendar.date(
                from: DateComponents(year: 1970, month: 1, day: 1)
            )
        )
        let dayBeforeEpoch = try XCTUnwrap(
            calendar.date(byAdding: .day, value: -1, to: unixEpoch)
        )
        XCTAssertEqual(
            NightingaleBackgroundCatalog.index(
                for: unixEpoch,
                calendar: calendar
            ),
            0
        )
        XCTAssertEqual(
            NightingaleBackgroundCatalog.index(
                for: dayBeforeEpoch,
                calendar: calendar
            ),
            6
        )
    }

    func testForestAccentMeetsTextContrastInLightAndDarkAppearances() {
        let lightTraits = UITraitCollection(userInterfaceStyle: .light)
        let darkTraits = UITraitCollection(userInterfaceStyle: .dark)

        let lightAccent = NightingalePalette.forestUIColor.resolvedColor(with: lightTraits)
        let darkAccent = NightingalePalette.forestUIColor.resolvedColor(with: darkTraits)
        let lightBackground = UIColor.systemBackground.resolvedColor(with: lightTraits)
        let darkBackground = UIColor.systemBackground.resolvedColor(with: darkTraits)

        XCTAssertGreaterThanOrEqual(
            contrastRatio(foreground: lightAccent, background: lightBackground),
            4.5
        )
        XCTAssertGreaterThanOrEqual(
            contrastRatio(foreground: darkAccent, background: darkBackground),
            4.5
        )
    }

    @MainActor
    func testPresentationPreferencesPersistOnlyUnderNightingaleKeys() throws {
        let suiteName = "net.acumenus.nightingale.tests.presentation.\(UUID().uuidString)"
        let defaults = try XCTUnwrap(UserDefaults(suiteName: suiteName))
        defer { defaults.removePersistentDomain(forName: suiteName) }

        let preferences = NightingalePresentationPreferences(
            defaults: defaults,
            environment: [:]
        )
        XCTAssertFalse(preferences.snapshot.reduceMotionRequested)
        XCTAssertFalse(preferences.snapshot.hideDecorativeImageryRequested)

        preferences.setReduceMotionRequested(true)
        preferences.setHideDecorativeImageryRequested(true)

        let reloaded = NightingalePresentationPreferences(
            defaults: defaults,
            environment: [:]
        )
        XCTAssertTrue(reloaded.snapshot.reduceMotionRequested)
        XCTAssertTrue(reloaded.snapshot.hideDecorativeImageryRequested)
        XCTAssertEqual(
            Set(defaults.persistentDomain(forName: suiteName).map { Array($0.keys) } ?? []),
            Set(NightingalePresentationPreferenceNamespace.allKeys)
        )

        let combinedKeys = NightingalePresentationPreferenceNamespace.allKeys
            .joined(separator: "|")
            .lowercased()
        XCTAssertTrue(combinedKeys.contains("nightingale"))
        XCTAssertFalse(combinedKeys.contains("hummingbird"))
        XCTAssertFalse(combinedKeys.contains("patient"))
        XCTAssertFalse(combinedKeys.contains("account"))
        XCTAssertFalse(combinedKeys.contains("token"))
    }

    private func keychainAttributesForSyntheticBinding() throws -> [String: Any] {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: NightingaleProtectedStateNamespace.keychainService,
            kSecAttrAccount as String:
                NightingaleProtectedStateNamespace.futureSessionBindingAccount,
            kSecAttrSynchronizable as String: false,
            kSecUseDataProtectionKeychain as String: true,
            kSecReturnAttributes as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne,
        ]
        var result: AnyObject?
        let status = SecItemCopyMatching(query as CFDictionary, &result)
        guard status == errSecSuccess, let attributes = result as? [String: Any] else {
            throw NightingaleProtectedStateError.keychain(
                operation: "test-attributes",
                status: status
            )
        }
        return attributes
    }

    private func contrastRatio(foreground: UIColor, background: UIColor) -> Double {
        let foregroundLuminance = relativeLuminance(foreground)
        let backgroundLuminance = relativeLuminance(background)
        return (max(foregroundLuminance, backgroundLuminance) + 0.05)
            / (min(foregroundLuminance, backgroundLuminance) + 0.05)
    }

    private func relativeLuminance(_ color: UIColor) -> Double {
        var red: CGFloat = 0
        var green: CGFloat = 0
        var blue: CGFloat = 0
        var alpha: CGFloat = 0
        XCTAssertTrue(color.getRed(&red, green: &green, blue: &blue, alpha: &alpha))
        XCTAssertEqual(alpha, 1, accuracy: 0.001)

        func linearized(_ component: CGFloat) -> Double {
            let value = Double(component)
            if value <= 0.04045 {
                return value / 12.92
            }
            return pow((value + 0.055) / 1.055, 2.4)
        }

        return 0.2126 * linearized(red)
            + 0.7152 * linearized(green)
            + 0.0722 * linearized(blue)
    }
}
