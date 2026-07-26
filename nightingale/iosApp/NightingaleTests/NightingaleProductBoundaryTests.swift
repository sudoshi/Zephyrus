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
