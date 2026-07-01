import SwiftUI

/// A pending bed placement: the request, the engine's **transparent** top recommendation (bed,
/// unit, score, and the safety/capability chips that justify it), and the runner-ups. The bed
/// manager reviews and taps **Place** (accept the chosen bed) or **Reject**. The server re-checks
/// availability + safety on accept (the chosen bed is never trusted), so a 409/422 surfaces here.
struct PlacementDetailView: View {
    let placement: Placement
    let api: APIClient
    let bearer: String
    let onDone: () async -> Void

    @Environment(\.dismiss) private var dismiss
    @State private var recs: [PlacementRecommendation] = []
    @State private var loading = true
    @State private var error: String?
    @State private var working = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Z.s4) {
                requestCard
                if loading {
                    ProgressView().tint(Z.primary).frame(maxWidth: .infinity).padding(.top, Z.s5)
                } else if let error {
                    RetryableMessage(symbol: "exclamationmark.triangle", title: "Couldn't get a bed",
                                     message: error, tone: .warning) { Task { await loadRecs() } }
                } else if recs.isEmpty {
                    RetryableMessage(symbol: "bed.double", title: "No safe bed available",
                                     message: "No bed currently meets this request's safety and capability constraints.",
                                     tone: .warning)
                } else {
                    recommendationCard(recs[0], isTop: true)
                    if recs.count > 1 {
                        sectionLabel("ALSO CONSIDERED")
                        ForEach(recs.dropFirst()) { recommendationCard($0, isTop: false) }
                    }
                }
            }
            .padding(Z.s4)
        }
        .background(Z.bg)
        .navigationTitle("Placement")
        .navigationBarTitleDisplayMode(.inline)
        .safeAreaInset(edge: .bottom) { actionBar }
        .task { await loadRecs() }
        .tint(Z.primary)
    }

    // MARK: Request

    private var requestCard: some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                HStack(spacing: Z.s2) {
                    StatusChip(status: placement.capacity)
                    if placement.needsIsolation { IsolationBadge() }
                    Spacer()
                }
                Text(placement.service ?? "Unassigned service")
                    .font(.system(size: 20, weight: .semibold)).foregroundStyle(Z.ink)
                HStack(spacing: Z.s2) {
                    if let s = placement.source { detailChip("source", s) }
                    if let t = placement.acuityTier { detailChip("acuity", "tier \(t)") }
                    if let u = placement.requiredUnitType { detailChip("needs", u.replacingOccurrences(of: "_", with: " ")) }
                }
            }
        }
    }

    // MARK: Recommendation

    private func recommendationCard(_ rec: PlacementRecommendation, isTop: Bool) -> some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                if isTop {
                    Text("RECOMMENDED BED").font(.system(size: 11, weight: .semibold)).tracking(0.5).foregroundStyle(Z.gold)
                }
                HStack(alignment: .firstTextBaseline) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(rec.bedLabel).font(.system(size: isTop ? 22 : 17, weight: .semibold)).foregroundStyle(Z.ink)
                        Text(rec.unitName).font(.system(size: 13)).foregroundStyle(Z.inkMuted)
                    }
                    Spacer()
                    VStack(alignment: .trailing, spacing: 1) {
                        Text("\(rec.score)").font(.system(size: isTop ? 28 : 20, weight: .semibold)).monospacedDigit().foregroundStyle(Z.primary)
                        Text("score").font(.system(size: 10)).foregroundStyle(Z.inkMuted)
                    }
                }
                if isTop {
                    FlowChips(chips: rec.chips)
                }
            }
        }
    }

    // MARK: Actions

    @ViewBuilder
    private var actionBar: some View {
        if let top = recs.first {
            VStack(spacing: Z.s2) {
                Button { place(top) } label: {
                    HStack(spacing: Z.s2) {
                        if working { ProgressView().tint(.white) }
                        Text("Place in \(top.bedLabel)").font(.system(size: 17, weight: .semibold))
                    }
                    .frame(maxWidth: .infinity).padding(.vertical, Z.s3)
                    .foregroundStyle(.white)
                    .background(RoundedRectangle(cornerRadius: 12).fill(working ? Z.primary.opacity(0.5) : Z.primary))
                }
                .disabled(working)
                Button { reject() } label: {
                    Text("Reject request").font(.system(size: 15, weight: .medium)).foregroundStyle(Z.status(.critical))
                }
                .disabled(working)
            }
            .padding(Z.s4)
            .background(.ultraThinMaterial)
        }
    }

    private func loadRecs() async {
        loading = true
        defer { loading = false }
        do {
            let r = try await api.placementRecommendations(id: placement.id, bearer: bearer)
            recs = r.recommendations
            error = nil
        } catch let e as APIError { error = e.message }
        catch { self.error = error.localizedDescription }
    }

    private func place(_ rec: PlacementRecommendation) {
        Task {
            working = true
            do {
                try await api.placeBed(id: placement.id, action: "accepted", chosenBedId: rec.bedId, bearer: bearer)
                await onDone()
                dismiss()
            } catch let e as APIError { error = e.message }
            catch { self.error = error.localizedDescription }
            working = false
        }
    }

    private func reject() {
        Task {
            working = true
            try? await api.placeBed(id: placement.id, action: "rejected", chosenBedId: nil, bearer: bearer)
            await onDone()
            working = false
            dismiss()
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

    private func sectionLabel(_ text: String) -> some View {
        Text(text).font(.system(size: 11, weight: .semibold)).tracking(0.5).foregroundStyle(Z.inkMuted).padding(.top, Z.s2)
    }
}

/// The recommendation's justification chips (acuity headroom, isolation, capability), each
/// paired with a pass/▵ icon so the rationale survives without color.
struct FlowChips: View {
    let chips: [PlacementChip]
    var body: some View {
        VStack(alignment: .leading, spacing: Z.s2) {
            ForEach(Array(chips.enumerated()), id: \.offset) { _, chip in
                HStack(spacing: Z.s2) {
                    Image(systemName: chip.ok ? "checkmark.circle.fill" : "exclamationmark.triangle.fill")
                        .font(.system(size: 13))
                        .foregroundStyle(chip.ok ? Z.status(.success) : Z.status(.warning))
                    Text(chip.label).font(.system(size: 13)).foregroundStyle(Z.ink)
                    Spacer()
                }
            }
        }
    }
}
