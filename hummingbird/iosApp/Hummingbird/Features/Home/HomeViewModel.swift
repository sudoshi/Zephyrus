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

    // MARK: House roll-up (derived)

    /// Units with an actual safe-capacity baseline. The house ratio is computed over these
    /// only, so units with no capacity data (live: safeCapacity == 0) don't skew the numerator
    /// against a denominator they aren't part of.
    private var capacityUnits: [CensusUnit] { units.filter { $0.safeCapacity > 0 } }

    var totalOccupied: Int { capacityUnits.reduce(0) { $0 + $1.occupied } }
    var totalSafe: Int { capacityUnits.reduce(0) { $0 + $1.safeCapacity } }
    var occupancyPercent: Int {
        guard totalSafe > 0 else { return 0 }
        return Int((Double(totalOccupied) / Double(totalSafe) * 100).rounded())
    }

    /// House-level status from house occupancy — not the single worst unit (that's surfaced
    /// separately by `pressuredUnitCount`). Falls back to `.info` ("No data") when no unit
    /// reports a capacity baseline.
    var houseStatus: CapacityStatus {
        guard totalSafe > 0 else { return .info }
        switch occupancyPercent {
        case 100...: return .critical
        case 85...: return .warning
        default: return .success
        }
    }

    var pressuredUnitCount: Int {
        units.filter { $0.capacity == .warning || $0.capacity == .critical }.count
    }

    var asOfDisplay: String {
        guard let asOf, let date = ISO8601DateFormatter.flexible.date(from: asOf) else { return "—" }
        let f = DateFormatter()
        f.dateFormat = "h:mm a"
        return f.string(from: date)
    }
}

extension ISO8601DateFormatter {
    static let flexible: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return f
    }()
}
