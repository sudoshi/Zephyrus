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
    private let tokenCoordinator: StaffTokenCoordinator
    private var sessionObserver: NSObjectProtocol?

    init(api: APIClient, tokenCoordinator: StaffTokenCoordinator? = nil) {
        self.api = api
        self.tokenCoordinator = tokenCoordinator ?? api.tokenCoordinator ?? .shared
        sessionObserver = NotificationCenter.default.addObserver(
            forName: StaffTokenCoordinator.didChangeNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor [weak self] in
                await self?.synchronizeCoordinatorSession()
            }
        }
    }

    deinit {
        if let sessionObserver {
            NotificationCenter.default.removeObserver(sessionObserver)
        }
    }

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
        guard let stored = await tokenCoordinator.restore() else {
            phase = .loggedOut
            return
        }
        accessToken = stored.accessToken
        refreshToken = stored.refreshToken
        do {
            me = try await api.me(bearer: stored.accessToken)
            await synchronizeCoordinatorSession()
            phase = .loggedIn
        } catch let error as APIError where error.statusCode == 401 || error.statusCode == 403 {
            await invalidateSession()
        } catch let error as APIError {
            // A transport/5xx/contract failure does not prove the protected refresh
            // credential is invalid. Keep it for a later bootstrap or fresh login, but
            // expose no authenticated UI until /me succeeds.
            applyClearedSession()
            me = nil
            errorMessage = error.message
            phase = .loggedOut
        } catch {
            applyClearedSession()
            me = nil
            errorMessage = error.localizedDescription
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
            let session = try await adopt(result)
            do {
                me = try await api.me(bearer: session.accessToken)
            } catch {
                await invalidateSession()
                throw error
            }
            await synchronizeCoordinatorSession()
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
            let session = try await adopt(result)
            changeToken = nil
            do {
                me = try await api.me(bearer: session.accessToken)
            } catch {
                await invalidateSession()
                throw error
            }
            await synchronizeCoordinatorSession()
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
        if let session = await tokenCoordinator.snapshot() {
            // Access and refresh tokens are separate Sanctum credentials. Revoke both
            // best-effort before erasing the device-only protected pair.
            await api.revoke(bearer: session.accessToken)
            await api.revoke(bearer: session.refreshToken)
        } else if let token = accessToken {
            await api.revoke(bearer: token)
        }
        await tokenCoordinator.clear()
        applyClearedSession()
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

    private func adopt(_ result: TokenResponse) async throws -> StaffTokenSession {
        do {
            let session = try await tokenCoordinator.install(result)
            accessToken = session.accessToken
            refreshToken = session.refreshToken
            return session
        } catch {
            // A server-issued pair that cannot be protected locally must not remain
            // live. Both credentials are revoked best-effort and never copied to a
            // less secure store.
            if let access = result.accessToken { await api.revoke(bearer: access) }
            if let refresh = result.refreshToken { await api.revoke(bearer: refresh) }
            throw error
        }
    }

    private func synchronizeCoordinatorSession() async {
        if let session = await tokenCoordinator.snapshot() {
            accessToken = session.accessToken
            refreshToken = session.refreshToken
            return
        }
        if phase == .loggedIn {
            finishInvalidation()
        } else {
            applyClearedSession()
        }
    }

    private func invalidateSession() async {
        await tokenCoordinator.clear()
        finishInvalidation()
    }

    private func finishInvalidation() {
        applyClearedSession()
        me = nil
        errorMessage = nil
        phase = .loggedOut
        JobActivityController.endAll()
        FlowWindowCache.clearAll()
        HouseGlanceCache.clear()
        ForYouGlanceCache.clear()
    }

    private func applyClearedSession() {
        accessToken = nil
        refreshToken = nil
        changeToken = nil
    }
}
