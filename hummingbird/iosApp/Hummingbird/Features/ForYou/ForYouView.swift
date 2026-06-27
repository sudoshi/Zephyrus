import SwiftUI

@MainActor
final class ForYouViewModel: ObservableObject {
    @Published var items: [ForYouItem] = []
    @Published var isLoading = false
    @Published var errorMessage: String?

    let api: APIClient
    init(api: APIClient) { self.api = api }

    func load(bearer: String) async {
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
    }
}

/// The "For You" queue — one prioritized list of things that need action.
struct ForYouView: View {
    @EnvironmentObject var auth: AuthStore
    @StateObject private var vm = ForYouViewModel(api: APIClient(baseURL: URL(string: AppConfig.baseURL)!))

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: Z.s3) {
                    header
                    if vm.items.isEmpty && vm.isLoading {
                        ProgressView().tint(Z.primary).frame(maxWidth: .infinity).padding(.top, Z.s6)
                    } else if vm.items.isEmpty {
                        emptyState
                    } else {
                        ForEach(vm.items) { ForYouRow(item: $0) }
                    }
                }
                .padding(Z.s4)
            }
            .background(Z.bg)
            .navigationTitle("For You")
            .navigationBarTitleDisplayMode(.inline)
            .refreshable { await vm.load(bearer: auth.accessToken ?? "") }
            .task {
                let token = auth.accessToken ?? ""
                while !Task.isCancelled {
                    await vm.load(bearer: token)
                    try? await Task.sleep(for: .seconds(15))
                }
            }
        }
        .tint(Z.primary)
    }

    private var header: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text("Needs you now")
                .font(.system(size: 22, weight: .semibold)).foregroundStyle(Z.ink)
            Text("\(vm.items.count) item\(vm.items.count == 1 ? "" : "s") to action")
                .font(.system(size: 13)).foregroundStyle(Z.inkMuted)
        }
    }

    private var emptyState: some View {
        VStack(spacing: Z.s2) {
            Image(systemName: "checkmark.circle.fill")
                .font(.system(size: 40)).foregroundStyle(Z.status(.success))
            Text("All clear").font(.system(size: 18, weight: .semibold)).foregroundStyle(Z.ink)
            Text("Nothing needs your action right now.")
                .font(.system(size: 13)).foregroundStyle(Z.inkMuted)
        }
        .frame(maxWidth: .infinity).padding(.top, Z.s6)
    }
}

struct ForYouRow: View {
    let item: ForYouItem
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
                Image(systemName: "chevron.right")
                    .font(.system(size: 12, weight: .semibold)).foregroundStyle(Z.inkMuted)
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
        default: return "bell.fill"
        }
    }

    private var metaLine: String? {
        let parts = [item.unit, relativeTime].compactMap { $0 }
        return parts.isEmpty ? nil : parts.joined(separator: " · ")
    }

    private var relativeTime: String? {
        guard let at = item.at, let date = parseISO(at) else { return nil }
        let f = RelativeDateTimeFormatter()
        f.unitsStyle = .abbreviated
        return f.localizedString(for: date, relativeTo: Date())
    }

    private func parseISO(_ s: String) -> Date? {
        if let d = ISO8601DateFormatter().date(from: s) { return d }
        return ISO8601DateFormatter.flexible.date(from: s)
    }
}
