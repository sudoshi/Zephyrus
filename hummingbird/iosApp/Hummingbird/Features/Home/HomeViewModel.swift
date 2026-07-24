import Foundation

@MainActor
final class HomeViewModel: ObservableObject {
    @Published var units: [CensusUnit] = []
    @Published var asOf: String?
    @Published var webLink: String?
    @Published var stale = false
    @Published var isLoading = false
    @Published var errorMessage: String?
    @Published var needsReauth = false
    @Published var live = false

    let api: APIClient
    private var bearerToken = ""

    private lazy var realtime = RealtimeClient(
        scheme: AppConfig.reverbScheme,
        host: AppConfig.reverbHost, port: AppConfig.reverbPort, key: AppConfig.reverbKey,
        channel: "hospital.beds",
        onEvent: { [weak self] in
            guard let self else { return }
            Task { await self.load(bearer: self.bearerToken) }
        },
        onState: { [weak self] connected in self?.live = connected }
    )

    init(api: APIClient) { self.api = api }

    /// Open the Reverb websocket and re-snapshot on every beds.changed event.
    func startLive(bearer: String) {
        bearerToken = bearer
        realtime.start()
    }

    func stopLive() {
        realtime.stop()
    }

    func load(bearer: String) async {
        #if DEBUG
        // Test affordance: SIMCTL_CHILD_HB_FORCE_ERROR=1 simulates an unreachable server so the
        // offline/error state can be exercised without taking the backend down.
        if ProcessInfo.processInfo.environment["HB_FORCE_ERROR"] == "1" {
            errorMessage = "Can't reach the server. Check your connection and try again."
            stale = true
            return
        }
        #endif
        isLoading = true
        defer { isLoading = false }
        do {
            let env = try await api.census(bearer: bearer)
            units = env.data
            asOf = env.meta?.asOf
            webLink = env.links?["web"]
            stale = env.meta?.stale ?? false
            errorMessage = nil
        } catch let error as APIError {
            if error.statusCode == 401 { needsReauth = true }
            errorMessage = error.message
            stale = true // keep whatever we had; flag it as not-fresh
        } catch {
            errorMessage = error.localizedDescription
            stale = true
        }
    }

    // MARK: House roll-up

    /// Occupancy roll-up over whatever set of units a surface shows (whole house, a role's
    /// scoped slice, etc.).
    var rollup: CensusRollup { CensusRollup(units) }

    var asOfDisplay: String {
        guard let asOf, let date = ISO8601DateFormatter.flexible.date(from: asOf) else { return "—" }
        let f = DateFormatter()
        f.dateFormat = "h:mm a"
        return f.string(from: date)
    }
}

/// Occupancy roll-up for an arbitrary set of census units. The ratio is computed over
/// capacity-bearing units only (live data often has safeCapacity == 0 = "No data"), so the
/// numerator and denominator stay consistent, and the status reflects house occupancy rather
/// than the single worst unit (that's `pressured`).
struct CensusRollup {
    let total: Int
    let occupied: Int
    let safe: Int
    let percent: Int
    let status: CapacityStatus
    let pressured: Int

    init(_ units: [CensusUnit]) {
        total = units.count
        let withCapacity = units.filter { $0.staffedBedCount > 0 }
        occupied = withCapacity.reduce(0) { $0 + $1.occupied }
        safe = withCapacity.reduce(0) { $0 + $1.staffedBedCount }
        percent = safe > 0 ? Int((Double(occupied) / Double(safe) * 100).rounded()) : 0
        if safe == 0 {
            status = .info
        } else {
            switch percent {
            case 100...: status = .critical
            case 85...: status = .warning
            default: status = .success
            }
        }
        pressured = units.filter { $0.capacity == .warning || $0.capacity == .critical }.count
    }
}
