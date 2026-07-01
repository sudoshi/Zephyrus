import SwiftUI

@MainActor
final class ForYouViewModel: ObservableObject {
    @Published var items: [ForYouItem] = []
    @Published var isLoading = false
    @Published var errorMessage: String?
    /// Census units keyed by id + name, so a queue item that names a unit can drill into that
    /// unit's live detail. Loaded best-effort alongside the queue.
    @Published var unitsById: [Int: CensusUnit] = [:]
    @Published var webLink: String?
    /// Item ids with an action in flight, so their inline button shows progress and disables.
    @Published var working: Set<String> = []
    private var unitsByName: [String: CensusUnit] = [:]

    let api: APIClient
    init(api: APIClient) { self.api = api }

    /// Resolve an open barrier from its queue item (id like "barrier-123"), then refresh.
    func resolveBarrier(item: ForYouItem, bearer: String) async {
        guard let barrierId = Self.refId(item.id, prefix: "barrier-") else { return }
        working.insert(item.id)
        defer { working.remove(item.id) }
        do {
            try await api.resolveBarrier(id: barrierId, bearer: bearer)
            await load(bearer: bearer)
        } catch let error as APIError { errorMessage = error.message }
        catch { errorMessage = error.localizedDescription }
    }

    /// Parse the numeric id out of a composite queue-item id ("barrier-123" → 123).
    static func refId(_ composite: String, prefix: String) -> Int? {
        composite.hasPrefix(prefix) ? Int(composite.dropFirst(prefix.count)) : nil
    }

    func load(bearer: String) async {
        // Test affordance: SIMCTL_CHILD_HB_FORCE_ERROR=1 simulates an unreachable server. No-op in prod.
        if ProcessInfo.processInfo.environment["HB_FORCE_ERROR"] == "1" {
            errorMessage = "Can't reach the server. Check your connection and try again."
            return
        }
        isLoading = true
        defer { isLoading = false }
        do {
            items = try await api.forYou(bearer: bearer)
            errorMessage = nil
        } catch let error as APIError {
            errorMessage = error.message
        } catch {
            errorMessage = error.localizedDescription
        }
        // Best-effort census for navigation context; never blocks the queue.
        if let census = try? await api.census(bearer: bearer) {
            unitsById = Dictionary(census.data.map { ($0.unitId, $0) }, uniquingKeysWith: { a, _ in a })
            unitsByName = Dictionary(census.data.map { ($0.name, $0) }, uniquingKeysWith: { a, _ in a })
            webLink = census.links?["web"]
        }
    }

    /// The census unit a queue item points at (barriers / capacity carry a unit name; bed
    /// requests don't), or nil when there's nothing specific to drill into.
    func unit(for item: ForYouItem) -> CensusUnit? {
        guard let name = item.unit else { return nil }
        return unitsByName[name]
    }

    /// The queue narrowed to what a given role is responsible for.
    func filtered(by role: RoleExperience, myUnit: String?) -> [ForYouItem] {
        items.filter { role.keep($0, unitsByName: unitsByName, myUnit: myUnit) }
    }
}

/// The "For You" queue — one prioritized list of things that need action.
struct ForYouView: View {
    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var profile: ProfileStore
    @StateObject private var vm = ForYouViewModel(api: APIClient(baseURL: URL(string: AppConfig.baseURL)!))
    @State private var path = NavigationPath()

    private var role: RoleExperience { RoleExperience.of(profile.roleId) }

    var body: some View {
        let items = vm.filtered(by: role, myUnit: profile.unitName)
        return NavigationStack(path: $path) {
            ScrollView {
                VStack(alignment: .leading, spacing: Z.s3) {
                    header(count: items.count)
                    if vm.items.isEmpty && vm.isLoading {
                        ProgressView().tint(Z.primary).frame(maxWidth: .infinity).padding(.top, Z.s6)
                    } else if vm.items.isEmpty && vm.errorMessage != nil {
                        // A failed load must never read as "All clear".
                        RetryableMessage(symbol: "wifi.exclamationmark", title: "Can't load your queue",
                                         message: vm.errorMessage ?? "", tone: .warning) {
                            Task { await vm.load(bearer: auth.accessToken ?? "") }
                        }
                    } else if items.isEmpty {
                        emptyState
                    } else {
                        ForEach(items) { item in
                            if item.type == "barrier" {
                                // Discharge barriers get a one-tap inline Resolve (mobile:act).
                                ForYouRow(item: item, navigable: false,
                                          actionLabel: "Resolve",
                                          busy: vm.working.contains(item.id)) {
                                    Task { await vm.resolveBarrier(item: item, bearer: auth.accessToken ?? "") }
                                }
                            } else if let unit = vm.unit(for: item) {
                                NavigationLink(value: unit.unitId) { ForYouRow(item: item) }
                                    .buttonStyle(.plain)
                            } else {
                                ForYouRow(item: item, navigable: false)
                            }
                        }
                    }
                }
                .padding(Z.s4)
            }
            .background(Z.bg)
            .navigationTitle("For You")
            .navigationBarTitleDisplayMode(.inline)
            .navigationDestination(for: Int.self) { unitId in
                if let unit = vm.unitsById[unitId] {
                    UnitDetailView(unit: unit, webLink: vm.webLink)
                }
            }
            .refreshable { await vm.load(bearer: auth.accessToken ?? "") }
            .task {
                let token = auth.accessToken ?? ""
                var first = true
                while !Task.isCancelled {
                    await vm.load(bearer: token)
                    if first {
                        first = false
                        // Test affordance: SIMCTL_CHILD_HB_FORYOU_OPEN=1 drills into the first
                        // queue item that has a unit, to exercise the row→detail path. No-op in prod.
                        if ProcessInfo.processInfo.environment["HB_FORYOU_OPEN"] == "1",
                           let unit = vm.items.compactMap({ vm.unit(for: $0) }).first {
                            path.append(unit.unitId)
                        }
                    }
                    try? await Task.sleep(for: .seconds(15))
                }
            }
        }
        .tint(Z.primary)
    }

    private func header(count: Int) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(role.queueTitle)
                .font(.system(size: 22, weight: .semibold)).foregroundStyle(Z.ink)
            // Suppress the count when we have nothing because the load failed (it's unknown, not 0).
            if !(vm.items.isEmpty && vm.errorMessage != nil) {
                Text("\(count) item\(count == 1 ? "" : "s") to action")
                    .font(.system(size: 13)).foregroundStyle(Z.inkMuted)
            }
        }
    }

    private var emptyState: some View {
        VStack(spacing: Z.s2) {
            Image(systemName: "checkmark.circle.fill")
                .font(.system(size: 40)).foregroundStyle(Z.status(.success))
            Text("All clear").font(.system(size: 18, weight: .semibold)).foregroundStyle(Z.ink)
            Text(role.emptyQueue)
                .font(.system(size: 13)).foregroundStyle(Z.inkMuted)
                .multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity).padding(.top, Z.s6)
    }
}

struct ForYouRow: View {
    let item: ForYouItem
    /// Whether to show the disclosure chevron (drill into the related unit). Off when the
    /// row is already shown in that unit's context, or has no unit to navigate to.
    var navigable: Bool = true
    /// An inline primary action (e.g. "Resolve"). When set, replaces the chevron with a button.
    var actionLabel: String? = nil
    var busy: Bool = false
    var onAction: (() -> Void)? = nil
    private var status: CapacityStatus { item.capacity }

    var body: some View {
        HStack(spacing: 0) {
            Rectangle().fill(Z.status(status)).frame(width: 4)
            HStack(spacing: Z.s3) {
                Image(systemName: icon)
                    .font(.system(size: 18)).foregroundStyle(Z.status(status)).frame(width: 26)
                VStack(alignment: .leading, spacing: 2) {
                    Text(item.title).font(.system(size: 15, weight: .semibold)).foregroundStyle(Z.ink)
                    Text(item.subtitle).font(.system(size: 13)).foregroundStyle(Z.inkMuted).lineLimit(2)
                    if let meta = metaLine {
                        Text(meta).font(.system(size: 11)).foregroundStyle(Z.inkMuted)
                    }
                }
                Spacer(minLength: Z.s2)
                if let actionLabel, let onAction {
                    Button(action: onAction) {
                        Group {
                            if busy {
                                ProgressView().controlSize(.small).tint(Z.primary)
                            } else {
                                Text(actionLabel)
                                    .font(.system(size: 13, weight: .semibold)).foregroundStyle(Z.primary)
                            }
                        }
                        .padding(.horizontal, Z.s3).padding(.vertical, Z.s1)
                        .background(Capsule().strokeBorder(Z.primary.opacity(0.5), lineWidth: 1))
                    }
                    .buttonStyle(.plain)
                    .disabled(busy)
                } else if navigable {
                    Image(systemName: "chevron.right")
                        .font(.system(size: 12, weight: .semibold)).foregroundStyle(Z.inkMuted)
                }
            }
            .padding(Z.s3)
        }
        .background(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).fill(Z.surface))
        .overlay(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).strokeBorder(Z.border, lineWidth: 1))
        .clipShape(RoundedRectangle(cornerRadius: Z.radius, style: .continuous))
    }

    private var icon: String {
        switch item.type {
        case "bed_request": return "bed.double.fill"
        case "barrier": return "exclamationmark.octagon.fill"
        case "capacity": return "building.2.fill"
        case "transport": return "figure.walk"
        case "evs": return "sparkles"
        default: return "bell.fill"
        }
    }

    private var metaLine: String? {
        let parts = [item.unit, relativeTime].compactMap { $0 }
        return parts.isEmpty ? nil : parts.joined(separator: " · ")
    }

    private var relativeTime: String? {
        guard let at = item.at, let date = parseISO(at) else { return nil }
        // Anything within the last minute (incl. synthetic "now" items like at-capacity,
        // and minor server clock skew that would read "in 0s") reads as "now".
        if Date().timeIntervalSince(date) < 60 { return "now" }
        let f = RelativeDateTimeFormatter()
        f.unitsStyle = .abbreviated
        return f.localizedString(for: date, relativeTo: Date())
    }

    private func parseISO(_ s: String) -> Date? {
        if let d = ISO8601DateFormatter().date(from: s) { return d }
        return ISO8601DateFormatter.flexible.date(from: s)
    }
}
