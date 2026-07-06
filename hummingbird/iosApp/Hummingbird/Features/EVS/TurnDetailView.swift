import SwiftUI

/// A single bed-turn: the location, an isolation SOP/PPE callout when required, a lifecycle
/// stepper, and one primary action that advances `Claim → Start cleaning → Complete`. Completing
/// the turn opens the bed for placement. Writes go through the parent VM (mobile:act).
struct TurnDetailView: View {
    let turn: EvsTurn
    let webLink: String?
    let advance: (Int, String) async -> Void

    @Environment(\.dismiss) private var dismiss
    @Environment(\.openURL) private var openURL
    @State private var status: String
    @State private var working = false

    init(turn: EvsTurn, webLink: String?, advance: @escaping (Int, String) async -> Void) {
        self.turn = turn
        self.webLink = webLink
        self.advance = advance
        _status = State(initialValue: turn.status)
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Z.s4) {
                locationCard
                NavigationLink {
                    DrillDetailView(itemUuid: "evs-\(turn.id)")
                } label: {
                    HStack {
                        Label("Why this turn?", systemImage: "questionmark.circle")
                            .font(.system(size: 14, weight: .semibold))
                            .foregroundStyle(Z.primary)
                        Spacer()
                    }
                    .padding(Z.s3)
                    .background(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.border, lineWidth: 1))
                }
                .buttonStyle(.plain)
                if let ref = turn.patientContextRef {
                    PatientContextLink(contextRef: ref, title: "Open operational dependency context")
                }
                if turn.isolationRequired { isolationCard }
                stepperCard
                if let webLink { openInZephyrus(webLink) }
            }
            .padding(Z.s4)
        }
        .background { HummingbirdBackdrop(dim: 0.4) }
        .navigationTitle("Bed Turn")
        .navigationBarTitleDisplayMode(.inline)
        .safeAreaInset(edge: .bottom) { primaryBar }
        .sensoryFeedback(.success, trigger: status)
        .tint(Z.primary)
    }

    private var locationCard: some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                HStack(spacing: Z.s2) {
                    TurnPriorityChip(turn: turn)
                    if turn.isolationRequired { IsolationBadge() }
                    Spacer()
                    Text(turn.sla.label)
                        .font(.system(size: 13, weight: .medium)).monospacedDigit()
                        .foregroundStyle((turn.sla.minutesUntilDue ?? 0) < 0 ? Z.status(.critical) : Z.inkMuted)
                }
                Text(turn.locationLabel ?? "—")
                    .font(.system(size: 22, weight: .semibold)).foregroundStyle(Z.ink)
                detailChip("turn", statusLabel(turn.turnType ?? turn.requestType))
            }
        }
    }

    private var isolationCard: some View {
        VStack(alignment: .leading, spacing: Z.s2) {
            HStack(spacing: Z.s2) {
                Image(systemName: "cross.case.fill").foregroundStyle(Z.status(.warning))
                Text("ISOLATION CLEAN — PPE REQUIRED")
                    .font(.system(size: 12, weight: .semibold)).tracking(0.4).foregroundStyle(Z.ink)
            }
            Text("Don gown, gloves, and mask before entering. Follow the isolation disinfection SOP, then doff and dispose of PPE at the door on the way out.")
                .font(.system(size: 13)).foregroundStyle(Z.inkMuted)
        }
        .padding(Z.s3)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).fill(Z.status(.warning).opacity(0.10)))
        .overlay(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).strokeBorder(Z.status(.warning).opacity(0.4), lineWidth: 1))
    }

    private func detailChip(_ label: String, _ value: String) -> some View {
        VStack(alignment: .leading, spacing: 1) {
            Text(label.uppercased()).font(.system(size: 10, weight: .semibold)).tracking(0.4).foregroundStyle(Z.inkMuted)
            Text(value).font(.system(size: 14, weight: .medium)).foregroundStyle(Z.ink)
        }
        .padding(.horizontal, Z.s3).padding(.vertical, Z.s2)
        .background(RoundedRectangle(cornerRadius: 10).fill(Z.bg))
        .overlay(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.border, lineWidth: 1))
    }

    private var stepperCard: some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                Text("PROGRESS")
                    .font(.system(size: 11, weight: .semibold)).tracking(0.5).foregroundStyle(Z.inkMuted)
                ForEach(Array(Self.lifecycle.enumerated()), id: \.offset) { _, step in
                    stepRow(step)
                }
            }
        }
    }

    private func stepRow(_ step: (label: String, statuses: [String])) -> some View {
        let done = isDone(step)
        let current = step.statuses.contains(status)
        return HStack(spacing: Z.s3) {
            Image(systemName: done ? "checkmark.circle.fill" : (current ? "circle.circle.fill" : "circle"))
                .font(.system(size: 16))
                .foregroundStyle(done ? Z.status(.success) : (current ? Z.primary : Z.border))
            Text(step.label)
                .font(.system(size: 15, weight: current ? .semibold : .regular))
                .foregroundStyle(current ? Z.ink : Z.inkMuted)
            Spacer()
        }
    }

    @ViewBuilder
    private var primaryBar: some View {
        if let n = nextAction(after: status) {
            HBActionBar {
                HBPrimaryActionButton(title: n.label, working: working) {
                    Task {
                        working = true
                        await advance(turn.id, n.status)
                        status = n.status
                        syncActivity(n.status)
                        working = false
                        if n.status == "completed" { dismiss() }
                    }
                }
            }
        } else {
            HBCompletionBanner(icon: "checkmark.seal.fill", text: "Bed turned — ready to place")
        }
    }

    private func openInZephyrus(_ link: String) -> some View {
        Button { if let url = URL(string: link) { openURL(url) } } label: {
            HStack {
                Label("Open in Zephyrus", systemImage: "arrow.up.forward.square")
                    .font(.system(size: 14, weight: .medium)).foregroundStyle(Z.primary)
                Spacer()
            }
            .padding(Z.s3)
            .background(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.border, lineWidth: 1))
        }
        .buttonStyle(.plain)
    }

    // MARK: Lifecycle model

    private static let lifecycle: [(label: String, statuses: [String])] = [
        ("Claimed", ["assigned"]),
        ("Cleaning", ["in_progress"]),
        ("Complete", ["completed"]),
    ]

    private static let order: [String] = ["requested", "queued", "assigned", "in_progress", "completed"]

    private func rank(_ s: String) -> Int { Self.order.firstIndex(of: s) ?? -1 }

    private func isDone(_ step: (label: String, statuses: [String])) -> Bool {
        guard let stepRank = step.statuses.map(rank).max() else { return false }
        return rank(status) > stepRank
    }

    private func nextAction(after s: String) -> (label: String, status: String)? {
        switch s {
        case "requested", "queued": return ("Claim this bed", "assigned")
        case "assigned": return (turn.isolationRequired ? "Start clean (PPE on)" : "Start cleaning", "in_progress")
        case "in_progress": return ("Mark complete", "completed")
        default: return nil
        }
    }

    /// Mirror this turn onto the lock screen / Dynamic Island after each lifecycle tap.
    private func syncActivity(_ newStatus: String) {
        JobActivityController.sync(
            kind: "evs", id: turn.id,
            title: turn.isolationRequired ? "Isolation bed-turn" : "Bed turn",
            detail: turn.locationLabel ?? "Bed",
            isStat: turn.priority == "stat",
            statusRaw: newStatus, statusLabel: statusLabel(newStatus),
            slaDeadline: FlowTime.parse(turn.neededAt))
    }
}
