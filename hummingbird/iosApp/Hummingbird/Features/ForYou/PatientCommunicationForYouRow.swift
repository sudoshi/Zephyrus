import SwiftUI

/// A type-safe navigation value keeps restricted communication UUIDs out of the
/// generic String drill route. It can be created only from the exact governed
/// type/domain/canonical-id triple retained by `ForYouItem`.
struct PatientCommunicationForYouRoute: Hashable {
    let workItemUUID: String

    init?(item: ForYouItem, canView: Bool) {
        guard canView,
              item.isPatientCommunicationAttention,
              let workItemUUID = item.patientCommunicationWorkItemUUID else {
            return nil
        }
        self.workItemUUID = workItemUUID
    }
}

/// Dedicated, read-only attention card. All displayed copy other than the
/// governed unit label is client-owned and fixed by the sanitized DTO policy.
/// Mutations remain exclusively inside `PatientCommunicationDetailView`.
struct PatientCommunicationForYouRow: View {
    let item: ForYouItem
    let navigable: Bool

    @Environment(\.colorSchemeContrast) private var contrast
    @Environment(\.accessibilityReduceTransparency) private var reduceTransparency

    private var status: CapacityStatus { item.capacity }
    private var hasValidRoute: Bool { item.patientCommunicationWorkItemUUID != nil }

    var body: some View {
        HStack(spacing: 0) {
            Rectangle()
                .fill(Z.status(status))
                .frame(width: contrast == .increased ? 6 : 4)
                .accessibilityHidden(true)

            HStack(alignment: .top, spacing: Z.s3) {
                Image(systemName: urgencySymbol)
                    .font(.title3.weight(.semibold))
                    .foregroundStyle(Z.status(status))
                    .frame(width: 30)
                    .accessibilityHidden(true)

                VStack(alignment: .leading, spacing: Z.s2) {
                    Text(cardTitle)
                        .font(.headline)
                        .foregroundStyle(Z.ink)
                        .fixedSize(horizontal: false, vertical: true)

                    Text(cardSubtitle)
                        .font(.subheadline)
                        .foregroundStyle(Z.inkMuted)
                        .fixedSize(horizontal: false, vertical: true)

                    ViewThatFits(in: .horizontal) {
                        HStack(spacing: Z.s2) { metadata }
                        VStack(alignment: .leading, spacing: Z.s1) { metadata }
                    }
                }

                Spacer(minLength: Z.s2)
                if navigable {
                    Image(systemName: "chevron.right")
                        .font(.caption.weight(.bold))
                        .foregroundStyle(Z.inkMuted)
                        .accessibilityHidden(true)
                }
            }
            .padding(Z.s3)
        }
        .background {
            RoundedRectangle(cornerRadius: Z.radius, style: .continuous)
                .fill(reduceTransparency ? Z.surface : Z.surface.opacity(0.82))
        }
        .overlay {
            RoundedRectangle(cornerRadius: Z.radius, style: .continuous)
                .strokeBorder(
                    contrast == .increased ? Z.ink.opacity(0.72) : Z.border,
                    lineWidth: contrast == .increased ? 2 : 1
                )
        }
        .clipShape(RoundedRectangle(cornerRadius: Z.radius, style: .continuous))
        .accessibilityElement(children: .combine)
        .accessibilityLabel(accessibilitySummary)
        .accessibilityHint(navigable ? "Opens the secure patient conversation" : "Open Messages to refresh this item")
        .accessibilityIdentifier(
            "forYou.patientCommunication.\(item.patientCommunicationWorkItemUUID ?? "malformed")"
        )
        .patientCommunicationPrivacySensitive()
    }

    @ViewBuilder private var metadata: some View {
        Label(urgencyLabel, systemImage: urgencySymbol)
            .font(.caption.weight(.semibold))
            .foregroundStyle(Z.status(status))

        if let unit = item.unit {
            Label(unit, systemImage: "building.2.fill")
                .font(.caption)
                .foregroundStyle(Z.inkMuted)
                .fixedSize(horizontal: false, vertical: true)
        }

        if let at = item.at {
            Text("Updated \(PatientCommunicationDates.relative(at))")
                .font(.caption)
                .foregroundStyle(Z.inkMuted)
        }
    }

    private var cardTitle: String {
        hasValidRoute ? item.title : "Patient communication link unavailable"
    }

    private var cardSubtitle: String {
        hasValidRoute
            ? item.subtitle
            : "Open Messages to refresh secure patient communications."
    }

    private var urgencyLabel: String {
        guard hasValidRoute else { return "Refresh required" }
        switch status {
        case .critical: return "Escalated"
        case .warning: return "Response due"
        case .success, .info: return "Awaiting response"
        }
    }

    private var urgencySymbol: String {
        guard hasValidRoute else { return "arrow.clockwise.circle.fill" }
        switch status {
        case .critical: return "exclamationmark.triangle.fill"
        case .warning: return "clock.badge.exclamationmark.fill"
        case .success, .info: return "message.badge.fill"
        }
    }

    private var accessibilitySummary: String {
        [cardTitle, urgencyLabel, item.unit, item.at.map { "updated \(PatientCommunicationDates.relative($0))" }]
            .compactMap { $0 }
            .joined(separator: ", ")
    }
}
