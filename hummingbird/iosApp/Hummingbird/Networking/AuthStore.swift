import Foundation

/// Owns auth state + tokens for the app. Token-based, honoring the backend's
/// must_change_password challenge (the web flow is never touched).
@MainActor
final class AuthStore: ObservableObject {
    enum Phase {
        case loading
        case loggedOut
        case needsPasswordChange
        case loggedIn
    }

    @Published var phase: Phase = .loading
    @Published var me: MeData?
    @Published var errorMessage: String?
    @Published var isBusy = false

    let api: APIClient
    private(set) var accessToken: String?
    private(set) var refreshToken: String?
    private let keychain = Keychain(service: "net.acumenus.hummingbird")

    init(api: APIClient) { self.api = api }

    /// On launch: if we have a stored token, validate it by loading /me.
    func bootstrap() async {
        guard let stored = keychain.get("accessToken") else {
            phase = .loggedOut
            return
        }
        accessToken = stored
        refreshToken = keychain.get("refreshToken")
        do {
            me = try await api.me(bearer: stored)
            phase = .loggedIn
        } catch {
            clearTokens()
            phase = .loggedOut
        }
    }

    func login(username: String, password: String) async {
        isBusy = true
        errorMessage = nil
        defer { isBusy = false }
        do {
            let result = try await api.token(username: username, password: password)
            if result.passwordChangeRequired == true {
                phase = .needsPasswordChange
                return
            }
            guard let access = result.accessToken else {
                errorMessage = "Unexpected response from the server."
                return
            }
            accessToken = access
            refreshToken = result.refreshToken
            keychain.set(access, for: "accessToken")
            if let rt = result.refreshToken { keychain.set(rt, for: "refreshToken") }
            me = try await api.me(bearer: access)
            phase = .loggedIn
        } catch let error as APIError {
            errorMessage = error.message
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    func logout() async {
        if let token = accessToken { await api.revoke(bearer: token) }
        clearTokens()
        me = nil
        errorMessage = nil
        phase = .loggedOut
    }

    private func clearTokens() {
        accessToken = nil
        refreshToken = nil
        keychain.delete("accessToken")
        keychain.delete("refreshToken")
    }
}
