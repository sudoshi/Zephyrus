import SwiftUI

/// A status pill that pairs the rationed color with an icon AND a label — never color alone.
struct StatusChip: View {
    let status: CapacityStatus

    var body: some View {
        HStack(spacing: Z.s1) {
            Image(systemName: status.symbol)
                .font(Z.scaledFont(11, weight: .semibold))
            Text(status.label.uppercased())
                .font(Z.scaledFont(11, weight: .semibold))
                .tracking(0.4)
        }
        .foregroundStyle(Z.status(status))
        .padding(.horizontal, Z.s2)
        .padding(.vertical, Z.s1)
        .background(Capsule().fill(Z.status(status).opacity(0.15)))
        .overlay(Capsule().strokeBorder(Z.status(status).opacity(0.35), lineWidth: 1))
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(status.label)
    }
}
