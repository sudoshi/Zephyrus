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
    /// Narrowly-scoped token (ability `password:change`) held only while the user is
    /// on the change-password screen. Exchanged for a full session by `changePassword`.
    private(set) var changeToken: String?
    private let keychain = Keychain(service: "net.acumenus.hummingbird")

    init(api: APIClient) { self.api = api }

    #if DEBUG
    /// Credential-free, process-memory session used only when the UI-test runner
    /// launches the dedicated patient-communications fixture mode. Nothing is
    /// written to Keychain and this code is absent from Release builds.
    func installPatientCommunicationsUITestSession() {
        guard StaffCommunicationsUITestMode.isEnabled else { return }
        accessToken = "ui-test-memory-token"
        refreshToken = nil
        changeToken = nil
        me = MeData(
            id: 9_999_001,
            name: "UI Test Clinician",
            username: "ui-test-clinician",
            email: nil,
            roles: ["bedside_nurse"],
            isAdmin: true,
            can: .init(viewPatientCommunications: true, respondPatientCommunications: true),
            workflowPreference: "superuser",
            mustChangePassword: false,
            units: [.init(unitId: 85, name: "5 East — Medical/Surgical", role: "bedside_nurse", isPrimary: true)]
        )
        phase = .loggedIn
    }
    #endif

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
                changeToken = result.changeToken
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

    /// Exchange the temp password for a new one using the scoped change token, then
    /// adopt the full session the backend hands back. Mirrors `login`'s token handling.
    func changePassword(currentPassword: String, newPassword: String) async {
        guard let change = changeToken else {
            errorMessage = "Your change session expired. Please sign in again."
            phase = .loggedOut
            return
        }
        isBusy = true
        errorMessage = nil
        defer { isBusy = false }
        do {
            let result = try await api.changePassword(currentPassword: currentPassword,
                                                      newPassword: newPassword, bearer: change)
            guard let access = result.accessToken else {
                errorMessage = "Unexpected response from the server."
                return
            }
            accessToken = access
            refreshToken = result.refreshToken
            keychain.set(access, for: "accessToken")
            if let rt = result.refreshToken { keychain.set(rt, for: "refreshToken") }
            changeToken = nil
            me = try await api.me(bearer: access)
            phase = .loggedIn
        } catch let error as APIError {
            errorMessage = error.message
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    /// Abandon a forced change and return to the sign-in screen.
    func backToLogin() {
        changeToken = nil
        errorMessage = nil
        phase = .loggedOut
    }

    func logout() async {
        if let token = accessToken { await api.revoke(bearer: token) }
        clearTokens()
        me = nil
        errorMessage = nil
        phase = .loggedOut
        // A signed-out device must not keep operational state on the lock screen or in a
        // cache that could outlive the session — end activities, drop the offline flow cache
        // (also the belt-and-braces guard against ever serving another user's window), and
        // clear the App-Group glance snapshots.
        JobActivityController.endAll()
        FlowWindowCache.clearAll()
        HouseGlanceCache.clear()
        ForYouGlanceCache.clear()
    }

    private func clearTokens() {
        accessToken = nil
        refreshToken = nil
        changeToken = nil
        keychain.delete("accessToken")
        keychain.delete("refreshToken")
    }
}
