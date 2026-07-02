import SwiftUI

/// A single transport job: the route, a lifecycle stepper, and one big primary action that
/// advances the job through `Claim → dispatch → pickup → en route → arrived → handoff →
/// complete`. The handoff step opens a structured sheet. Writes go through the parent VM
/// (mobile:act); the view optimistically advances its local status so the button stays live.
struct JobDetailView: View {
    let job: TransportJob
    let webLink: String?
    let advance: (Int, String) async -> Void
    let handoff: (Int, String, String?) async -> Void

    @Environment(\.dismiss) private var dismiss
    @Environment(\.openURL) private var openURL
    @State private var status: String
    @State private var showHandoff = false
    @State private var working = false

    init(job: TransportJob, webLink: String?,
         advance: @escaping (Int, String) async -> Void,
         handoff: @escaping (Int, String, String?) async -> Void) {
        self.job = job
        self.webLink = webLink
        self.advance = advance
        self.handoff = handoff
        _status = State(initialValue: job.status)
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Z.s4) {
                routeCard
                NavigationLink {
                    DrillDetailView(itemUuid: "transport-\(job.id)")
                } label: {
                    HStack {
                        Label("Explain trip signal", systemImage: "questionmark.circle")
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
        .background(Z.bg)
        .navigationTitle("Trip")
        .navigationBarTitleDisplayMode(.inline)
        .safeAreaInset(edge: .bottom) { primaryBar }
        .sheet(isPresented: $showHandoff) {
            HandoffSheet { to, summary in
                Task {
                    working = true
                    await handoff(job.id, to, summary)
                    status = "handoff_complete"
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
                AltitudeBreadcrumbView(current: .a2, includesPatient: job.patientContextRef != nil)
                HStack {
                    JobPriorityChip(job: job)
                    Spacer()
                    Text(job.sla.label)
                        .font(.system(size: 13, weight: .medium)).monospacedDigit()
                        .foregroundStyle((job.sla.minutesUntilDue ?? 0) < 0 ? Z.status(.critical) : Z.inkMuted)
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
            Button {
                if n.status == handoffSentinel {
                    showHandoff = true
                } else {
                    Task {
                        working = true
                        await advance(job.id, n.status)
                        status = n.status
                        working = false
                        if n.status == "completed" { dismiss() }
                    }
                }
            } label: {
                HStack(spacing: Z.s2) {
                    if working { ProgressView().tint(.white) }
                    Text(n.label).font(.system(size: 17, weight: .semibold))
                }
                .frame(maxWidth: .infinity).padding(.vertical, Z.s3)
                .foregroundStyle(.white)
                .background(RoundedRectangle(cornerRadius: 12).fill(working ? Z.primary.opacity(0.5) : Z.primary))
            }
            .disabled(working)
            .padding(Z.s4)
            .background(.ultraThinMaterial)
        } else {
            HStack(spacing: Z.s2) {
                Image(systemName: "checkmark.seal.fill").foregroundStyle(Z.status(.success))
                Text("Trip complete").font(.system(size: 15, weight: .semibold)).foregroundStyle(Z.ink)
            }
            .frame(maxWidth: .infinity).padding(Z.s4)
            .background(.ultraThinMaterial)
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

    private func nextAction(after s: String) -> (label: String, status: String)? {
        switch s {
        case "requested", "accepted", "queued": return ("Claim this trip", "assigned")
        case "assigned": return ("Start — dispatch", "dispatched")
        case "dispatched": return ("Arrived at pickup", "arrived_pickup")
        case "arrived_pickup", "patient_ready", "patient_not_ready": return ("Picked up", "picked_up")
        case "picked_up": return ("En route", "en_route")
        case "en_route": return ("Arrived at destination", "arrived_destination")
        case "arrived_destination", "handoff_started": return ("Complete handoff", handoffSentinel)
        case "handoff_complete": return ("Mark trip complete", "completed")
        default: return nil
        }
    }
}

/// The structured handoff capture at the destination (handoff_to + optional summary).
struct HandoffSheet: View {
    let onComplete: (String, String?) -> Void

    @Environment(\.dismiss) private var dismiss
    @State private var handoffTo = ""
    @State private var summary = ""

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: Z.s4) {
                    field("HANDING OFF TO", text: $handoffTo, placeholder: "Receiving nurse / unit")
                    field("SUMMARY (OPTIONAL)", text: $summary, placeholder: "Anything the receiver should know")
                }
                .padding(Z.s4)
            }
            .background(Z.bg)
            .navigationTitle("Structured handoff")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button("Cancel") { dismiss() }.tint(Z.primary)
                }
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Complete") {
                        onComplete(handoffTo.trimmingCharacters(in: .whitespaces),
                                   summary.isEmpty ? nil : summary)
                    }
                    .font(.system(size: 16, weight: .semibold)).tint(Z.primary)
                    .disabled(handoffTo.trimmingCharacters(in: .whitespaces).isEmpty)
                }
            }
        }
        .tint(Z.primary)
    }

    private func field(_ label: String, text: Binding<String>, placeholder: String) -> some View {
        VStack(alignment: .leading, spacing: Z.s2) {
            Text(label).font(.system(size: 11, weight: .semibold)).tracking(0.5).foregroundStyle(Z.inkMuted)
            TextField(placeholder, text: text, axis: .vertical)
                .font(.system(size: 16)).foregroundStyle(Z.ink)
                .padding(Z.s3)
                .background(RoundedRectangle(cornerRadius: 10).fill(Z.surface))
                .overlay(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.border, lineWidth: 1))
        }
    }
}
