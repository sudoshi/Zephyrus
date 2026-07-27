import Foundation
import Security

enum PatientStorageNamespace {
    static let keychainService = "net.acumenus.hummingbird.patient.credentials"
    static let preferencesSuite = "net.acumenus.hummingbird.patient.preferences"
    static let accessTokenAccount = "patient-access-token"
    static let refreshTokenAccount = "patient-refresh-token"
}

protocol PatientTokenStoring: AnyObject {
    var accessToken: String? { get }
    var refreshToken: String? { get }
    func store(accessToken: String, refreshToken: String) throws
    func clear()
}

enum PatientSecureStoreError: Error {
    case keychain(OSStatus)
}

final class KeychainPatientTokenStore: PatientTokenStoring {
    var accessToken: String? { value(for: PatientStorageNamespace.accessTokenAccount) }
    var refreshToken: String? { value(for: PatientStorageNamespace.refreshTokenAccount) }

    func store(accessToken: String, refreshToken: String) throws {
        clear()
        try set(accessToken, for: PatientStorageNamespace.accessTokenAccount)
        do {
            try set(refreshToken, for: PatientStorageNamespace.refreshTokenAccount)
        } catch {
            clear()
            throw error
        }
    }

    func clear() {
        delete(PatientStorageNamespace.accessTokenAccount)
        delete(PatientStorageNamespace.refreshTokenAccount)
    }

    private func set(_ value: String, for account: String) throws {
        let match = query(account: account)
        SecItemDelete(match as CFDictionary)

        var attributes = match
        attributes[kSecValueData as String] = Data(value.utf8)
        attributes[kSecAttrAccessible as String] = kSecAttrAccessibleWhenUnlockedThisDeviceOnly
        attributes[kSecAttrSynchronizable as String] = false
        let status = SecItemAdd(attributes as CFDictionary, nil)
        guard status == errSecSuccess else { throw PatientSecureStoreError.keychain(status) }
    }

    private func value(for account: String) -> String? {
        var request = query(account: account)
        request[kSecReturnData as String] = true
        request[kSecMatchLimit as String] = kSecMatchLimitOne

        var result: AnyObject?
        guard SecItemCopyMatching(request as CFDictionary, &result) == errSecSuccess,
              let data = result as? Data
        else { return nil }
        return String(data: data, encoding: .utf8)
    }

    private func delete(_ account: String) {
        SecItemDelete(query(account: account) as CFDictionary)
    }

    private func query(account: String) -> [String: Any] {
        [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: PatientStorageNamespace.keychainService,
            kSecAttrAccount as String: account,
            kSecAttrSynchronizable as String: false,
        ]
    }
}
