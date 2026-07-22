import SwiftUI

struct PatientCommunicationRoutingCard: View {
    let candidates: PatientCommunicationRouteCandidatesData
    let isWorking: Bool
    let onSelect: (PatientCommunicationRoutingAction) -> Void

    private var availableActions: [PatientCommunicationRoutingAction] {
        PatientCommunicationRoutingAction.allCases.filter(candidates.actions.allows)
    }

    var body: some View {
        PatientCommunicationCard {
            VStack(alignment: .leading, spacing: Z.s3) {
                VStack(alignment: .leading, spacing: Z.s1) {
                    Label("Ownership and routing", systemImage: "arrow.triangle.branch")
                        .font(.headline)
                        .foregroundStyle(Z.ink)
                    Text("Use only when accountability needs to move. Every change requires a reason and confirmation, and is recorded for the care team.")
                        .font(.subheadline)
                        .foregroundStyle(Z.inkMuted)
                        .fixedSize(horizontal: false, vertical: true)
                }

                if availableActions.isEmpty {
                    Label(
                        "No ownership changes are available in the conversation's current state.",
                        systemImage: "lock.fill"
                    )
                    .font(.subheadline)
                    .foregroundStyle(Z.inkMuted)
                } else {
                    ForEach(availableActions) { action in
                        Button {
                            onSelect(action)
                        } label: {
                            HStack(alignment: .center, spacing: Z.s2) {
                                Image(systemName: symbol(for: action))
                                    .frame(width: 24)
                                    .accessibilityHidden(true)
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(action.label)
                                        .font(.body.weight(.semibold))
                                    Text(hint(for: action))
                                        .font(.caption)
                                        .foregroundStyle(Z.inkMuted)
                                        .fixedSize(horizontal: false, vertical: true)
                                }
                                Spacer(minLength: Z.s1)
                                Image(systemName: "chevron.right")
                                    .font(.caption.weight(.bold))
                                    .accessibilityHidden(true)
                            }
                            .foregroundStyle(Z.ink)
                            .padding(Z.s3)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .background(
                                RoundedRectangle(cornerRadius: 10, style: .continuous)
                                    .fill(Z.bg.opacity(0.68))
                            )
                            .overlay(
                                RoundedRectangle(cornerRadius: 10, style: .continuous)
                                    .strokeBorder(Z.border, lineWidth: 1)
                            )
                        }
                        .buttonStyle(.plain)
                        .disabled(isWorking)
                        .accessibilityHint(hint(for: action))
                        .accessibilityIdentifier("patientCommunications.routing.\(action.rawValue)Button")
                    }
                }
            }
        }
    }

    private func symbol(for action: PatientCommunicationRoutingAction) -> String {
        switch action {
        case .release: return "person.crop.circle.badge.minus"
        case .reassign: return "person.2.arrowtriangle.left.arrowtriangle.right"
        case .reroute: return "arrow.triangle.turn.up.right.diamond.fill"
        }
    }

    private func hint(for action: PatientCommunicationRoutingAction) -> String {
        switch action {
        case .release: return "Return accountability to the current team queue"
        case .reassign: return "Select another eligible responder"
        case .reroute: return "Select another authorized care team"
        }
    }
}

struct PatientCommunicationRoutingSheet: View {
    @Environment(\.dismiss) private var dismiss
    @Environment(\.scenePhase) private var scenePhase

    let action: PatientCommunicationRoutingAction
    let candidates: PatientCommunicationRouteCandidatesData
    let isWorking: Bool
    let onConfirm: (String?, PatientCommunicationRoutingReasonOption) -> Void

    @State private var selectedTargetUUID: String?
    @State private var selectedReasonCode: String?
    @State private var showConfirmation = false

    private var reasons: [PatientCommunicationRoutingReasonOption] {
        candidates.reasons(for: action)
    }

    private var selectedReason: PatientCommunicationRoutingReasonOption? {
        reasons.first { $0.code == selectedReasonCode }
    }

    private var requiresTarget: Bool { action != .release }

    private var isReady: Bool {
        guard selectedReason != nil else { return false }
        return !requiresTarget || candidates.containsTarget(selectedTargetUUID, for: action)
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                LazyVStack(alignment: .leading, spacing: Z.s4) {
                    guidance
                    if requiresTarget { targetSection }
                    reasonSection
                    reviewButton
                }
                .padding(Z.s4)
            }
            .background { HummingbirdBackdrop(dim: 0.64) }
            .navigationTitle(action.label)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
            }
        }
        .patientCommunicationPrivacySensitive()
        .overlay {
            if scenePhase != .active {
                PatientCommunicationRoutingPrivacyCover()
            }
        }
        .onChange(of: scenePhase) { _, phase in
            if phase != .active { dismiss() }
        }
        .confirmationDialog(
            confirmationTitle,
            isPresented: $showConfirmation,
            titleVisibility: .visible
        ) {
            Button(confirmButtonTitle) {
                guard let selectedReason else { return }
                onConfirm(selectedTargetUUID, selectedReason)
                dismiss()
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text(confirmationMessage)
        }
        .presentationDetents([.large])
        .interactiveDismissDisabled(isWorking)
    }

    private var guidance: some View {
        PatientCommunicationCard {
            VStack(alignment: .leading, spacing: Z.s2) {
                Label("Review before changing ownership", systemImage: "checkmark.shield.fill")
                    .font(.headline)
                    .foregroundStyle(Z.ink)
                Text(guidanceCopy)
                    .font(.subheadline)
                    .foregroundStyle(Z.inkMuted)
                    .fixedSize(horizontal: false, vertical: true)
            }
        }
    }

    @ViewBuilder private var targetSection: some View {
        VStack(alignment: .leading, spacing: Z.s2) {
            Text(action == .reassign ? "Eligible responder" : "Authorized care team")
                .font(.headline)
                .foregroundStyle(Z.ink)

            if action == .reassign {
                ForEach(Array(candidates.reassignCandidates.enumerated()), id: \.element.id) { index, candidate in
                    selectionButton(
                        title: candidate.label,
                        detail: candidate.membershipRole.label,
                        selected: selectedTargetUUID == candidate.membershipUuid,
                        identifier: "patientCommunications.routing.target.\(index)"
                    ) {
                        selectedTargetUUID = candidate.membershipUuid
                    }
                }
            } else {
                ForEach(Array(candidates.rerouteCandidates.enumerated()), id: \.element.id) { index, candidate in
                    selectionButton(
                        title: candidate.label,
                        detail: scopeCopy(candidate),
                        selected: selectedTargetUUID == candidate.poolUuid,
                        identifier: "patientCommunications.routing.target.\(index)"
                    ) {
                        selectedTargetUUID = candidate.poolUuid
                    }
                }
            }
        }
    }

    private var reasonSection: some View {
        VStack(alignment: .leading, spacing: Z.s2) {
            Text("Reason")
                .font(.headline)
                .foregroundStyle(Z.ink)
            Text("The selected reason is recorded in the staff routing history.")
                .font(.caption)
                .foregroundStyle(Z.inkMuted)
                .fixedSize(horizontal: false, vertical: true)

            ForEach(Array(reasons.enumerated()), id: \.element.id) { index, reason in
                selectionButton(
                    title: reason.label,
                    detail: nil,
                    selected: selectedReasonCode == reason.code,
                    identifier: "patientCommunications.routing.reason.\(index)"
                ) {
                    selectedReasonCode = reason.code
                }
            }
        }
    }

    private var reviewButton: some View {
        Button {
            showConfirmation = true
        } label: {
            HStack(spacing: Z.s2) {
                if isWorking { ProgressView().tint(.white) }
                Image(systemName: "checkmark.shield.fill")
                    .accessibilityHidden(true)
                Text(isWorking ? "Working…" : "Review \(action.rawValue)")
            }
            .font(.body.weight(.semibold))
            .foregroundStyle(.white)
            .frame(maxWidth: .infinity)
            .padding(.vertical, Z.s3)
            .background(
                RoundedRectangle(cornerRadius: 10, style: .continuous)
                    .fill(isReady && !isWorking ? Z.primary : Z.primary.opacity(0.48))
            )
        }
        .buttonStyle(.plain)
        .disabled(!isReady || isWorking)
        .accessibilityIdentifier("patientCommunications.routing.reviewButton")
    }

    private func selectionButton(
        title: String,
        detail: String?,
        selected: Bool,
        identifier: String,
        select: @escaping () -> Void
    ) -> some View {
        Button(action: select) {
            HStack(alignment: .top, spacing: Z.s3) {
                Image(systemName: selected ? "checkmark.circle.fill" : "circle")
                    .font(.title3)
                    .foregroundStyle(selected ? Z.primary : Z.inkMuted)
                    .accessibilityHidden(true)
                VStack(alignment: .leading, spacing: 2) {
                    Text(title)
                        .font(.body.weight(.semibold))
                        .foregroundStyle(Z.ink)
                        .fixedSize(horizontal: false, vertical: true)
                    if let detail {
                        Text(detail)
                            .font(.caption)
                            .foregroundStyle(Z.inkMuted)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                }
                Spacer(minLength: Z.s1)
            }
            .padding(Z.s3)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(
                RoundedRectangle(cornerRadius: 10, style: .continuous)
                    .fill(selected ? Z.primary.opacity(0.14) : Z.surface.opacity(0.84))
            )
            .overlay(
                RoundedRectangle(cornerRadius: 10, style: .continuous)
                    .strokeBorder(selected ? Z.primary : Z.border, lineWidth: selected ? 2 : 1)
            )
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(selected ? .isSelected : [])
        .accessibilityIdentifier(identifier)
    }

    private func scopeCopy(_ candidate: PatientCommunicationRerouteCandidate) -> String {
        if let unit = candidate.unit { return "\(candidate.scopeType.label) · \(unit.label)" }
        return candidate.scopeType.label
    }

    private var guidanceCopy: String {
        switch action {
        case .release:
            return "Return the conversation to its current team's shared queue. You will no longer be the accountable owner."
        case .reassign:
            return "Transfer accountability to one server-authorized responder."
        case .reroute:
            return "Move accountability to one server-authorized care team."
        }
    }

    private var confirmationTitle: String {
        switch action {
        case .release: return "Release to the team queue?"
        case .reassign: return "Reassign this conversation?"
        case .reroute: return "Reroute this conversation?"
        }
    }

    private var confirmButtonTitle: String {
        switch action {
        case .release: return "Confirm release"
        case .reassign: return "Confirm reassignment"
        case .reroute: return "Confirm reroute"
        }
    }

    private var confirmationMessage: String {
        let reason = selectedReason?.label ?? "Selected reason"
        switch action {
        case .release:
            return "The current team becomes accountable. Reason: \(reason)."
        case .reassign:
            let target = candidates.reassignCandidates.first { $0.membershipUuid == selectedTargetUUID }?.label
                ?? "Selected responder"
            return "\(target) becomes accountable. Reason: \(reason)."
        case .reroute:
            let target = candidates.rerouteCandidates.first { $0.poolUuid == selectedTargetUUID }?.label
                ?? "Selected care team"
            return "\(target) becomes accountable. Reason: \(reason)."
        }
    }
}

private struct PatientCommunicationRoutingPrivacyCover: View {
    var body: some View {
        ZStack {
            Z.bg.ignoresSafeArea()
            Label("Ownership controls hidden", systemImage: "lock.shield.fill")
                .font(.headline)
                .foregroundStyle(Z.ink)
        }
        .accessibilityElement(children: .combine)
    }
}
