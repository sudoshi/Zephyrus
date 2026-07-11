import SwiftUI

/// A single transport job: the route, a lifecycle stepper, and one big primary action that
/// advances the job through `Claim → dispatch → pickup → en route → arrived → handoff →
/// complete`. The handoff step opens a structured sheet. The server supplies the legal next
/// transitions and every mutation returns the authoritative lifecycle state.
struct JobDetailView: View {
    let job: TransportJob
    let webLink: String?
    let advance: (Int, String, Int) async -> TransportJob?
    let handoff: (Int, String, String, String, String?, String?, Int) async -> TransportJob?

    @Environment(\.dismiss) private var dismiss
    @Environment(\.openURL) private var openURL
    @State private var status: String
    @State private var allowedTransitions: [String]
    @State private var canHandoff: Bool
    @State private var lifecycleVersion: Int
    @State private var showHandoff = false
    @State private var working = false

    init(job: TransportJob, webLink: String?,
         advance: @escaping (Int, String, Int) async -> TransportJob?,
         handoff: @escaping (Int, String, String, String, String?, String?, Int) async -> TransportJob?) {
        self.job = job
        self.webLink = webLink
        self.advance = advance
        self.handoff = handoff
        _status = State(initialValue: job.status)
        _allowedTransitions = State(initialValue: job.allowedTransitions)
        _canHandoff = State(initialValue: job.canHandoff)
        _lifecycleVersion = State(initialValue: job.lifecycleVersion)
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Z.s4) {
                routeCard
                NavigationLink {
                    DrillDetailView(itemUuid: "transport-\(job.id)")
                } label: {
                    HStack {
                        Label("Why this trip?", systemImage: "questionmark.circle")
                            .font(.system(size: 14, weight: .semibold))
                            .foregroundStyle(Z.primary)
                        Spacer()
                    }
                    .padding(Z.s3)
                    .background(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.border, lineWidth: 1))
                }
                .buttonStyle(.plain)
                if let ref = job.patientContextRef {
                    PatientContextLink(contextRef: ref, title: "Open transport-safe patient context")
                }
                stepperCard
                if let webLink { openInZephyrus(webLink) }
            }
            .padding(Z.s4)
        }
        .background { HummingbirdBackdrop(dim: 0.4) }
        .navigationTitle("Trip")
        .navigationBarTitleDisplayMode(.inline)
        .safeAreaInset(edge: .bottom) { primaryBar }
        .sensoryFeedback(.success, trigger: status)
        .sheet(isPresented: $showHandoff) {
            HandoffSheet { to, role, acceptance, risk, summary in
                Task {
                    working = true
                    if let updated = await handoff(
                        job.id, to, role, acceptance, risk, summary, lifecycleVersion
                    ) {
                        apply(updated)
                    }
                    working = false
                    showHandoff = false
                }
            }
        }
        .tint(Z.primary)
    }

    // MARK: Route

    private var routeCard: some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                HStack {
                    JobPriorityChip(job: job)
                    Spacer()
                    Text(job.sla.label)
                        .font(.system(size: 13, weight: .medium)).monospacedDigit()
                        .foregroundStyle(job.sla.atRisk ? Z.status(.critical) : Z.inkMuted)
                }
                Text("\(job.origin ?? "—")  →  \(job.destination ?? "—")")
                    .font(.system(size: 20, weight: .semibold)).foregroundStyle(Z.ink)
                HStack(spacing: Z.s2) {
                    detailChip("type", statusLabel(job.type))
                    if let mode = job.mode { detailChip("mode", statusLabel(mode)) }
                }
            }
        }
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

    // MARK: Stepper

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
                .foregroundStyle(current ? Z.ink : (done ? Z.inkMuted : Z.inkMuted))
            Spacer()
        }
    }

    // MARK: Primary action bar

    @ViewBuilder
    private var primaryBar: some View {
        if let n = nextAction(after: status) {
            HBActionBar {
                HBPrimaryActionButton(title: n.label, working: working) {
                    if n.status == handoffSentinel {
                        showHandoff = true
                    } else {
                        Task {
                            working = true
                            if let updated = await advance(job.id, n.status, lifecycleVersion) {
                                apply(updated)
                                if updated.status == "completed" { dismiss() }
                            }
                            working = false
                        }
                    }
                }
            }
        } else {
            let terminal = ["completed", "canceled", "failed"].contains(status)
            HBCompletionBanner(
                icon: terminal ? "checkmark.seal.fill" : "lock.shield.fill",
                text: terminal ? statusLabel(status) : "Awaiting an authorized dispatch action"
            )
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
        ("Claimed", ["assigned", "dispatched"]),
        ("Dispatched", ["dispatched"]),
        ("At pickup", ["arrived_pickup", "patient_ready", "patient_not_ready"]),
        ("Picked up", ["picked_up"]),
        ("En route", ["en_route"]),
        ("Arrived", ["arrived_destination"]),
        ("Handed off", ["handoff_started", "handoff_complete"]),
        ("Complete", ["completed"]),
    ]

    private static let order: [String] = [
        "requested", "accepted", "queued", "assigned", "dispatched", "arrived_pickup",
        "patient_ready", "patient_not_ready", "picked_up", "en_route", "arrived_destination",
        "handoff_started", "handoff_complete", "completed",
    ]

    private func rank(_ s: String) -> Int { Self.order.firstIndex(of: s) ?? -1 }

    private func isDone(_ step: (label: String, statuses: [String])) -> Bool {
        // A step is done when the current status has advanced past the step's last status.
        guard let stepRank = step.statuses.map(rank).max() else { return false }
        return rank(status) > stepRank
    }

    private let handoffSentinel = "__handoff__"

    private func apply(_ updated: TransportJob) {
        status = updated.status
        allowedTransitions = updated.allowedTransitions
        canHandoff = updated.canHandoff
        lifecycleVersion = updated.lifecycleVersion
        syncActivity(updated.status)
    }

    /// Mirror this trip onto the lock screen / Dynamic Island after each lifecycle tap.
    private func syncActivity(_ newStatus: String) {
        JobActivityController.sync(
            kind: "transport", id: job.id,
            title: job.priority == "stat" ? "STAT transport" : "Transport trip",
            detail: "\(job.origin ?? "—") → \(job.destination ?? "—")",
            isStat: job.priority == "stat",
            statusRaw: newStatus, statusLabel: statusLabel(newStatus),
            slaDeadline: FlowTime.parse(job.neededAt))
    }

    private func nextAction(after s: String) -> (label: String, status: String)? {
        if canHandoff && job.handoffRequired && ["arrived_destination", "handoff_started"].contains(s) {
            return ("Complete handoff", handoffSentinel)
        }
        if s == "escalated",
           let recovery = allowedTransitions.first(where: { !["canceled", "failed"].contains($0) }) {
            return ("Resume — \(statusLabel(recovery))", recovery)
        }

        let preferred: (label: String, status: String)? = switch s {
        case "requested", "accepted", "queued": ("Claim this trip", "assigned")
        case "assigned": ("Start — dispatch", "dispatched")
        case "dispatched": ("Arrived at pickup", "arrived_pickup")
        case "arrived_pickup", "patient_ready", "patient_not_ready": ("Picked up", "picked_up")
        case "picked_up": ("En route", "en_route")
        case "en_route": ("Arrived at destination", "arrived_destination")
        case "arrived_destination", "handoff_complete": ("Mark trip complete", "completed")
        default: nil
        }

        guard let preferred, allowedTransitions.contains(preferred.status) else { return nil }
        return preferred
    }
}

/// Append-only receiver acceptance evidence captured at the destination.
struct HandoffSheet: View {
    let onComplete: (String, String, String, String?, String?) -> Void

    @Environment(\.dismiss) private var dismiss
    @State private var handoffTo = ""
    @State private var receiverRole = ""
    @State private var acceptanceStatus = "accepted"
    @State private var outstandingRisk = ""
    @State private var summary = ""
    @FocusState private var focusedField: String?

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: Z.s4) {
                    field("HANDING OFF TO", text: $handoffTo, placeholder: "Receiving nurse / unit")
                    field("RECEIVER ROLE", text: $receiverRole, placeholder: "RN, paramedic, transport lead")
                    VStack(alignment: .leading, spacing: Z.s2) {
                        Text("ACCEPTANCE")
                            .font(.system(size: 11, weight: .semibold)).tracking(0.5)
                            .foregroundStyle(Z.inkMuted)
                        Picker("Acceptance", selection: $acceptanceStatus) {
                            Text("Accepted").tag("accepted")
                            Text("With risks").tag("accepted_with_risks")
                        }
                        .pickerStyle(.segmented)
                    }
                    if acceptanceStatus == "accepted_with_risks" {
                        field(
                            "OUTSTANDING RISK",
                            text: $outstandingRisk,
                            placeholder: "Risk the receiver explicitly accepted"
                        )
                    }
                    field("SUMMARY (OPTIONAL)", text: $summary, placeholder: "Anything the receiver should know")
                }
                .padding(Z.s4)
            }
            .background { HummingbirdBackdrop(dim: 0.4) }
            .navigationTitle("Structured handoff")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button("Cancel") { dismiss() }.tint(Z.primary)
                }
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Complete") {
                        onComplete(handoffTo.trimmingCharacters(in: .whitespaces),
                                   receiverRole.trimmingCharacters(in: .whitespaces),
                                   acceptanceStatus,
                                   outstandingRisk.trimmingCharacters(in: .whitespaces).isEmpty
                                       ? nil : outstandingRisk.trimmingCharacters(in: .whitespaces),
                                   summary.isEmpty ? nil : summary)
                    }
                    .font(.system(size: 16, weight: .semibold)).tint(Z.primary)
                    .disabled(
                        handoffTo.trimmingCharacters(in: .whitespaces).isEmpty ||
                        receiverRole.trimmingCharacters(in: .whitespaces).isEmpty ||
                        (acceptanceStatus == "accepted_with_risks" &&
                         outstandingRisk.trimmingCharacters(in: .whitespaces).isEmpty)
                    )
                }
            }
        }
        .tint(Z.primary)
    }

    private func field(_ label: String, text: Binding<String>, placeholder: String) -> some View {
        VStack(alignment: .leading, spacing: Z.s2) {
            Text(label).font(.system(size: 11, weight: .semibold)).tracking(0.5).foregroundStyle(Z.inkMuted)
            TextField(placeholder, text: text, axis: .vertical)
                .focused($focusedField, equals: label)
                .font(.system(size: 16)).foregroundStyle(Z.ink)
                .padding(Z.s3)
                .background(RoundedRectangle(cornerRadius: 10).fill(Z.surface))
                .overlay(RoundedRectangle(cornerRadius: 10)
                    .strokeBorder(focusedField == label ? Z.gold : Z.border, lineWidth: focusedField == label ? 1.5 : 1))
                .animation(.easeOut(duration: 0.15), value: focusedField)
        }
    }
}
