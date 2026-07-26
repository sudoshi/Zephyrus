import Foundation
import Security

enum NightingaleProtectedStateNamespace {
    static let keychainService = "net.acumenus.nightingale.protected-state.v1"
    static let futureSessionBindingAccount = "future-session-binding-v1"
}

enum NightingaleProtectedStateError: Error, Equatable {
    case emptyValue
    case keychain(operation: String, status: OSStatus)
    case invalidStoredValue
}

enum NightingaleProtectedStateDeletionOutcome: Equatable {
    case deleted
    case alreadyAbsent
}

protocol NightingaleProtectedStateStoring {
    func readFutureSessionBinding() throws -> Data?
    func writeFutureSessionBinding(_ value: Data) throws
    @discardableResult
    func deleteAll() throws -> NightingaleProtectedStateDeletionOutcome
}

/// A dormant protected-state primitive for the pre-identity foundation.
///
/// Production code does not construct or call this store yet. Its sole approved use in
/// this slice is platform verification with a synthetic canary. It deliberately exposes
/// no access-token, refresh-token, device-identity, or legacy migration API.
final class KeychainNightingaleProtectedStateStore: NightingaleProtectedStateStoring {
    func readFutureSessionBinding() throws -> Data? {
        var request = baseQuery()
        request[kSecAttrAccount as String] =
            NightingaleProtectedStateNamespace.futureSessionBindingAccount
        request[kSecReturnData as String] = true
        request[kSecMatchLimit as String] = kSecMatchLimitOne

        var result: AnyObject?
        let status = SecItemCopyMatching(request as CFDictionary, &result)
        switch status {
        case errSecSuccess:
            guard let data = result as? Data else {
                throw NightingaleProtectedStateError.invalidStoredValue
            }
            return data
        case errSecItemNotFound:
            return nil
        default:
            throw NightingaleProtectedStateError.keychain(operation: "read", status: status)
        }
    }

    func writeFutureSessionBinding(_ value: Data) throws {
        guard !value.isEmpty else {
            throw NightingaleProtectedStateError.emptyValue
        }

        var match = baseQuery()
        match[kSecAttrAccount as String] =
            NightingaleProtectedStateNamespace.futureSessionBindingAccount

        let update: [String: Any] = [
            kSecValueData as String: value,
            kSecAttrAccessible as String: kSecAttrAccessibleWhenUnlockedThisDeviceOnly,
            kSecAttrSynchronizable as String: false,
        ]
        let updateStatus = SecItemUpdate(match as CFDictionary, update as CFDictionary)
        if updateStatus == errSecSuccess {
            return
        }
        guard updateStatus == errSecItemNotFound else {
            throw NightingaleProtectedStateError.keychain(
                operation: "update",
                status: updateStatus
            )
        }

        var attributes = match
        attributes.merge(update) { _, replacement in replacement }
        let addStatus = SecItemAdd(attributes as CFDictionary, nil)
        guard addStatus == errSecSuccess else {
            throw NightingaleProtectedStateError.keychain(operation: "add", status: addStatus)
        }
    }

    @discardableResult
    func deleteAll() throws -> NightingaleProtectedStateDeletionOutcome {
        let status = SecItemDelete(baseQuery() as CFDictionary)
        switch status {
        case errSecSuccess:
            return .deleted
        case errSecItemNotFound:
            return .alreadyAbsent
        default:
            throw NightingaleProtectedStateError.keychain(operation: "delete", status: status)
        }
    }

    private func baseQuery() -> [String: Any] {
        [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: NightingaleProtectedStateNamespace.keychainService,
            kSecAttrSynchronizable as String: false,
            kSecUseDataProtectionKeychain as String: true,
        ]
    }
}

enum NightingaleVolatileInputClearReason: Equatable {
    case applicationInactive
    case logout
    case identityTransition
    case recovery
    case revocation
    case localRemoval
}

/// Holds future patient-entered composition text only for the active process lifetime.
///
/// Clearing drops the app's reference; Swift does not provide a reliable zeroization
/// guarantee for immutable String storage, so this type makes no memory-overwrite claim.
@MainActor
final class NightingaleVolatileInputState: ObservableObject {
    @Published private(set) var draft = ""
    private(set) var lastClearReason: NightingaleVolatileInputClearReason?

    var hasDraft: Bool {
        !draft.isEmpty
    }

    func replaceDraftForComposition(_ value: String) {
        draft = value
    }

    func clear(_ reason: NightingaleVolatileInputClearReason) {
        draft = ""
        lastClearReason = reason
    }
}
