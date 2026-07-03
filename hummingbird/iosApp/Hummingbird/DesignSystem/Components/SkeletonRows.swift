import SwiftUI

/// Placeholder rows for a list screen's first load — panel-shaped stand-ins instead of a
/// bare spinner, so the page keeps its structure while data arrives. Deliberately static
/// (no shimmer): calm is the default state, and it honors Reduce Motion by construction.
struct SkeletonRows: View {
    var count: Int = 4

    var body: some View {
        VStack(spacing: Z.s3) {
            ForEach(0..<count, id: \.self) { _ in row }
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Loading")
    }

    private var row: some View {
        HStack(spacing: Z.s3) {
            RoundedRectangle(cornerRadius: 6, style: .continuous)
                .fill(Z.border.opacity(0.55))
                .frame(width: 26, height: 26)
            VStack(alignment: .leading, spacing: Z.s2) {
                RoundedRectangle(cornerRadius: 4, style: .continuous)
                    .fill(Z.border.opacity(0.55))
                    .frame(width: 170, height: 12)
                RoundedRectangle(cornerRadius: 4, style: .continuous)
                    .fill(Z.border.opacity(0.35))
                    .frame(width: 110, height: 10)
            }
            Spacer()
        }
        .padding(Z.s3)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).fill(Z.surface.opacity(0.5)))
        .overlay(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).strokeBorder(Z.border.opacity(0.5), lineWidth: 1))
    }
}
