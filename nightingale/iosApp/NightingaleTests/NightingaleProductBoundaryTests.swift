import Security
import XCTest
@testable import Nightingale

final class NightingaleProductBoundaryTests: XCTestCase {
    func testFoundationHasNoLivePatientOrStaffAccess() {
        XCTAssertEqual(NightingaleProductBoundary.productName, "Nightingale")
        XCTAssertFalse(NightingaleProductBoundary.livePatientAccessEnabled)
        XCTAssertFalse(NightingaleProductBoundary.staffEndpointsPermitted)
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
        XCTAssertEqual(standard.decorativeImageOpacity, 0.08)
        XCTAssertEqual(standard.transitionDuration, 0.18)

        XCTAssertTrue(patientReduced.reduceMotion)
        XCTAssertFalse(patientReduced.showDecorativeImagery)
        XCTAssertEqual(patientReduced.decorativeImageOpacity, 0)
        XCTAssertEqual(patientReduced.transitionDuration, 0)

        XCTAssertTrue(systemReduced.reduceMotion)
        XCTAssertFalse(systemReduced.showDecorativeImagery)
        XCTAssertEqual(systemReduced.decorativeImageOpacity, 0)
        XCTAssertEqual(systemReduced.cardOpacity, 1)
        XCTAssertEqual(systemReduced.transitionDuration, 0)

        XCTAssertTrue(accessibilityText.showDecorativeImagery)
        XCTAssertEqual(accessibilityText.decorativeImageOpacity, 0.04)
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
}
