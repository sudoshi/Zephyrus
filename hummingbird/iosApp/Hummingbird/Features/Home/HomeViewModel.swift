import Foundation

@MainActor
final class HomeViewModel: ObservableObject {
    @Published var units: [CensusUnit] = []
    @Published var asOf: String?
    @Published var stale = false
    @Published var isLoading = false
    @Published var errorMessage: String?
    @Published var needsReauth = false

    let api: APIClient
    init(api: APIClient) { self.api = api }

    func load(bearer: String) async {
        isLoading = true
        defer { isLoading = false }
        do {
            let env = try await api.census(bearer: bearer)
            units = env.data
            asOf = env.meta?.asOf
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

    var totalOccupied: Int { units.reduce(0) { $0 + $1.occupied } }
    var totalSafe: Int { units.reduce(0) { $0 + $1.safeCapacity } }
    var occupancyPercent: Int {
        guard totalSafe > 0 else { return 0 }
        return Int((Double(totalOccupied) / Double(totalSafe) * 100).rounded())
    }
    var worstStatus: CapacityStatus {
        units.map(\.capacity).max(by: { $0.severity < $1.severity }) ?? .info
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
