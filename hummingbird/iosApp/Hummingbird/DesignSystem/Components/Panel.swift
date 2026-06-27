import SwiftUI

/// The single surface primitive — quiet-lift resting panel (surface + 1px border + soft
/// shadow), matching the web `Surface`/`Card`. Floating elements would use a heavier shadow.
struct Panel<Content: View>: View {
    var padding: CGFloat = Z.s4
    @ViewBuilder var content: Content

    var body: some View {
        content
            .padding(padding)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).fill(Z.surface))
            .overlay(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).strokeBorder(Z.border, lineWidth: 1))
            .shadow(color: .black.opacity(0.25), radius: 8, x: 0, y: 2)
    }
}
