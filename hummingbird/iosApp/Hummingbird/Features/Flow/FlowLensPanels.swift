import SwiftUI

/// The executive forward half (P9): next-24h posture as two defensible numbers — predicted
/// ED arrivals (the hourly forecast summed) and surge probability — each citing its source.
/// Tapping surge opens the standard projection detail (provenance chip included).
struct FlowForecastStrip: View {
    let arrivalsTotal: Int?
    let arrivalsSource: String?
    let surge: FlowProjection?
    var onSelect: ((FlowSelection) -> Void)? = nil

    var body: some View {
        Panel(padding: Z.s3) {
            HStack(spacing: 0) {
                cell(value: arrivalsTotal.map { "\($0)" } ?? "—",
                     label: "ED arrivals · next 24h",
                     source: arrivalsSource)
                Rectangle().fill(Z.border).frame(width: 1, height: 44)
                surgeCell
            }
        }
    }

    @ViewBuilder
    private var surgeCell: some View {
        if let surge {
            Button {
                onSelect?(.projection(surge))
            } label: {
                cell(value: "\(Int(surge.value ?? 0))%",
                     label: "Surge probability",
                     source: surge.provenance?.service)
                    .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .accessibilityLabel("Surge probability \(Int(surge.value ?? 0)) percent, \(surge.confidence). Shows source detail.")
        } else {
            cell(value: "—", label: "Surge probability", source: nil)
        }
    }

    private func cell(value: String, label: String, source: String?) -> some View {
        VStack(spacing: 2) {
            Text(value)
                .font(.system(size: 22, weight: .semibold)).monospacedDigit()
                .foregroundStyle(Z.ink)
            Text(label)
                .font(.system(size: 11))
                .foregroundStyle(Z.inkMuted)
            if let source {
                Text("Source: \(source)")
                    .font(.system(size: 9, weight: .medium))
                    .foregroundStyle(Z.inkMuted)
                    .lineLimit(1)
            }
        }
        .frame(maxWidth: .infinity)
    }
}

/// The active curve filter (P6): which slice of the house the curve is summing, with a
/// 44pt clear affordance back to the wider scope.
struct FlowFilterChip: View {
    let label: String
    let onClear: () -> Void

    var body: some View {
        Button(action: onClear) {
            HStack(spacing: Z.s1) {
                Text(label)
                    .font(.system(size: 12, weight: .medium))
                    .foregroundStyle(Z.ink)
                Image(systemName: "xmark")
                    .font(.system(size: 9, weight: .semibold))
                    .foregroundStyle(Z.inkMuted)
            }
            .padding(.horizontal, Z.s3)
            .frame(minHeight: 32)
            .background(Capsule().fill(Z.surface))
            .overlay(Capsule().strokeBorder(Z.border, lineWidth: 1))
            .frame(minHeight: 44)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel("Curve filtered to \(label). Clear filter.")
    }
}
