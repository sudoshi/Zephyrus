import SwiftUI
import WidgetKit

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
        #if DEBUG
        // Test affordance: SIMCTL_CHILD_HB_FORCE_ERROR=1 simulates an unreachable server.
        if ProcessInfo.processInfo.environment["HB_FORCE_ERROR"] == "1" {
            clearLoadedData()
            errorMessage = "Can't reach the server. Check your connection and try again."
            return
        }
        if ForYouPatientCommunicationsUITestMode.isEnabled {
            items = ForYouPatientCommunicationsUITestMode.items
            errorMessage = nil
            isLoading = false
            return
        }
        #endif
        isLoading = true
        defer { isLoading = false }
        do {
            let loaded = try await api.forYou(bearer: bearer)
            guard !Task.isCancelled else { return }
            items = loaded
            errorMessage = nil
            // The app-group widget is persistent. Restricted communications are
            // intentionally excluded even from its aggregate counts.
            ForYouGlanceCache.save(glanceSnapshot(updatedAt: Date()))
            WidgetCenter.shared.reloadTimelines(ofKind: ForYouGlanceCache.widgetKind)
        } catch let error as APIError {
            guard !Task.isCancelled else { return }
            clearLoadedData()
            errorMessage = error.message
            return
        } catch {
            guard !Task.isCancelled else { return }
            clearLoadedData()
            errorMessage = error.localizedDescription
            return
        }
        // Best-effort census for navigation context; never blocks the queue.
        if let census = try? await api.census(bearer: bearer) {
            guard !Task.isCancelled else { return }
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
    func filtered(
        by role: RoleExperience,
        myUnit: String?,
        canViewPatientCommunications: Bool
    ) -> [ForYouItem] {
        items.filter { item in
            if item.isPatientCommunicationAttention {
                return canViewPatientCommunications
            }
            return role.keep(item, unitsByName: unitsByName, myUnit: myUnit)
        }
    }

    /// Restricted communications must never influence the app-group widget's
    /// persisted queue counts. Operational items retain the existing glance.
    func glanceSnapshot(updatedAt: Date) -> ForYouGlanceSnapshot {
        let persistable = items.filter { !$0.isPatientCommunicationAttention }
        return ForYouGlanceSnapshot(
            pending: persistable.count,
            critical: persistable.filter { $0.tier == "critical" }.count,
            updatedAt: updatedAt
        )
    }

    /// Clear every in-memory queue identifier and any navigation lookup state
    /// when the scene is no longer active. A foreground task performs fresh GETs.
    func suspend() {
        clearLoadedData()
        working = []
        errorMessage = nil
        isLoading = false
    }

    /// Capability loss is authoritative even before the next network refresh.
    /// Purge restricted UUIDs immediately while leaving unrelated operational
    /// attention cards available.
    func purgePatientCommunications() {
        let communicationIDs = Set(items.lazy.filter(\.isPatientCommunicationAttention).map(\.id))
        items.removeAll(where: \.isPatientCommunicationAttention)
        working.subtract(communicationIDs)
    }

    private func clearLoadedData() {
        items = []
        unitsById = [:]
        unitsByName = [:]
        webLink = nil
    }
}

/// The "For You" queue — one prioritized list of things that need action.
struct ForYouView: View {
    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var profile: ProfileStore
    @Environment(\.scenePhase) private var scenePhase
    @StateObject private var vm: ForYouViewModel
    @StateObject private var patientCommunications: PatientCommunicationsViewModel
    @State private var path = NavigationPath()
    @State private var showProfile = false

    private var role: RoleExperience { RoleExperience.of(profile.roleId) }

    init() {
        let baseURL = URL(string: AppConfig.baseURL)!
        _vm = StateObject(wrappedValue: ForYouViewModel(api: APIClient.noCache(baseURL: baseURL)))
        #if DEBUG
        let communicationsRepository: PatientCommunicationsRepository = StaffCommunicationsUITestMode.isEnabled
            ? PatientCommunicationsUITestRepository()
            : APIClient.patientCommunications(baseURL: baseURL)
        #else
        let communicationsRepository: PatientCommunicationsRepository = APIClient.patientCommunications(baseURL: baseURL)
        #endif
        _patientCommunications = StateObject(
            wrappedValue: PatientCommunicationsViewModel(repository: communicationsRepository)
        )
    }

    var body: some View {
        let items = vm.filtered(
            by: role,
            myUnit: profile.unitName,
            canViewPatientCommunications: auth.me?.can.viewPatientCommunications == true
        )
        return NavigationStack(path: $path) {
            ScrollView {
                VStack(alignment: .leading, spacing: Z.s3) {
                    header(count: items.count)
                    AltitudeContextCard(domain: roleAltitudeDomain)
                    if vm.items.isEmpty && vm.isLoading {
                        SkeletonRows()
                    } else if vm.items.isEmpty && vm.errorMessage != nil {
                        // A failed load must never read as "All clear".
                        RetryableMessage(symbol: "wifi.exclamationmark", title: "Can't load your queue",
                                         message: vm.errorMessage ?? "", tone: .warning) {
                            Task { await loadQueue() }
                        }
                    } else if items.isEmpty {
                        emptyState
                    } else {
                        ForEach(items) { item in
                            if item.isPatientCommunicationAttention {
                                if let route = PatientCommunicationForYouRoute(
                                    item: item,
                                    canView: auth.me?.can.viewPatientCommunications == true
                                ) {
                                    NavigationLink(value: route) {
                                        PatientCommunicationForYouRow(item: item, navigable: true)
                                    }
                                    .buttonStyle(.plain)
                                } else {
                                    PatientCommunicationForYouRow(item: item, navigable: false)
                                }
                            } else if supportsDrill(item) {
                                NavigationLink(value: item.id) { ForYouRow(item: item) }
                                    .buttonStyle(.plain)
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
            .background { HummingbirdBackdrop(dim: 0.4) }
            .navigationTitle("For You")
            .eddyContext("for_you", title: "For You", summary: "your priority queue")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button { showProfile = true } label: {
                        EmptyView()
                    }
                    .accessibilityLabel("Profile and settings")
                }
            }
            .sheet(isPresented: $showProfile) { ProfileView() }
            .navigationDestination(for: Int.self) { unitId in
                if let unit = vm.unitsById[unitId] {
                    UnitDetailView(unit: unit, webLink: vm.webLink)
                }
            }
            .navigationDestination(for: String.self) { itemId in
                DrillDetailView(itemUuid: itemId)
            }
            .navigationDestination(for: PatientCommunicationForYouRoute.self) { route in
                PatientCommunicationDetailView(
                    viewModel: patientCommunications,
                    workItemUUID: route.workItemUUID,
                    canRespond: auth.me?.can.respondPatientCommunications == true
                )
            }
            .refreshable { await loadQueue() }
            .task(id: scenePhase) {
                guard scenePhase == .active else { return }
                #if DEBUG
                var first = true
                #endif
                while !Task.isCancelled {
                    await loadQueue()
                    #if DEBUG
                    if first {
                        first = false
                        // Test affordance: SIMCTL_CHILD_HB_FORYOU_OPEN=1 drills into the first
                        // queue item, to exercise the row→A2 drill path.
                        if ProcessInfo.processInfo.environment["HB_FORYOU_OPEN"] == "1",
                           let route = vm.items.compactMap({
                               PatientCommunicationForYouRoute(
                                   item: $0,
                                   canView: auth.me?.can.viewPatientCommunications == true
                               )
                           }).first {
                            path.append(route)
                        } else if ProcessInfo.processInfo.environment["HB_FORYOU_OPEN"] == "1",
                           let first = vm.items.first(where: supportsDrill) {
                            path.append(first.id)
                        } else if ProcessInfo.processInfo.environment["HB_FORYOU_OPEN"] == "1",
                                  let unit = vm.items.compactMap({ vm.unit(for: $0) }).first {
                            path.append(unit.unitId)
                        }
                    }
                    #endif
                    try? await Task.sleep(for: .seconds(15))
                }
            }
        }
        .tint(Z.primary)
        .onChange(of: scenePhase) { _, phase in
            guard phase != .active else { return }
            path = NavigationPath()
            showProfile = false
            vm.suspend()
            patientCommunications.suspend()
        }
        .onChange(of: auth.me?.can.viewPatientCommunications) { previous, canView in
            #if DEBUG
            // The synthetic fixture carries its own authorized contract rows.
            // Ignore launch-time capability publication churn in UI tests only.
            if ForYouPatientCommunicationsUITestMode.isEnabled { return }
            #endif
            // Ignore initial bootstrap churn; an explicit transition away from
            // an established true capability is the revocation boundary.
            guard previous == true, canView != true else { return }
            path = NavigationPath()
            vm.purgePatientCommunications()
            patientCommunications.suspend()
        }
    }

    private func header(count: Int) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(role.queueTitle)
                .font(.system(size: 22, weight: .semibold)).foregroundStyle(Z.ink)
            // Suppress the count when we have nothing because the load failed (it's unknown, not 0).
            if !(vm.items.isEmpty && vm.errorMessage != nil) {
                Text("\(count) item\(count == 1 ? "" : "s") to action").monospacedDigit()
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

    private func supportsDrill(_ item: ForYouItem) -> Bool {
        item.id.hasPrefix("bedreq-")
            || item.id.hasPrefix("barrier-")
            || item.id.hasPrefix("transport-")
            || item.id.hasPrefix("evs-")
            || item.id.hasPrefix("ops-approval-")
            || item.id.hasPrefix("staffing-")
            || item.id.hasPrefix("cap-")
            || item.id.hasPrefix("improvement-")
    }

    private func loadQueue() async {
        await vm.load(bearer: auth.accessToken ?? "")
        #if DEBUG
        // The credential-free fixture is itself the authorization test input;
        // do not let launch-time @Published ordering erase it before the
        // synthetic /me session settles. Release always executes the gate below.
        if ForYouPatientCommunicationsUITestMode.isEnabled { return }
        #endif
        if auth.me?.can.viewPatientCommunications != true {
            vm.purgePatientCommunications()
        }
    }

    private var roleAltitudeDomain: String {
        switch profile.roleId {
        case "transport": return "transport"
        case "evs": return "evs"
        case "staffing_coordinator": return "staffing"
        case "capacity_lead": return "ops"
        default: return "rtdc"
        }
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
                    .accessibilityHidden(true)
                VStack(alignment: .leading, spacing: 2) {
                    Text(item.title).font(.system(size: 15, weight: .semibold)).foregroundStyle(Z.ink)
                        .accessibilityLabel("\(status.label): \(item.title)")
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
                        .accessibilityHidden(true)
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
        case "ops_approval": return "checkmark.seal.fill"
        case "staffing_request": return "person.2.badge.gearshape.fill"
        default: return "bell.fill"
        }
    }

    private var metaLine: String? {
        let parts = [item.unit, contextToken, relativeTime].compactMap { $0 }
        return parts.isEmpty ? nil : parts.joined(separator: " · ")
    }

    private var contextToken: String? {
        guard item.patientContextRef != nil else { return nil }
        return "patient context available"
    }

    private var relativeTime: String? {
        guard let at = item.at, let date = parseISO(at) else { return nil }
        return OperationalDuration.age(since: date)
    }

    private func parseISO(_ s: String) -> Date? {
        if let d = ISO8601DateFormatter().date(from: s) { return d }
        return ISO8601DateFormatter.flexible.date(from: s)
    }
}
