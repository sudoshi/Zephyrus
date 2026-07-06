import SwiftUI

/// The single surface primitive — quiet-lift resting panel (surface + 1px border + soft
/// shadow), matching the web `Surface`/`Card`. Floating elements would use a heavier shadow.
///
/// Frosted glass so the persistent Hummingbird backdrop (RootView) reads through the app:
/// an ultraThinMaterial frost blurs the photography, with a translucent operational tint
/// on top so metrics and labels stay legible. Text/border/shadow are unchanged.
struct Panel<Content: View>: View {
    var padding: CGFloat = Z.s4
    @ViewBuilder var content: Content

    var body: some View {
        content
            .padding(padding)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background {
                RoundedRectangle(cornerRadius: Z.radius, style: .continuous)
                    .fill(Z.surface.opacity(0.5))
                    .background(.ultraThinMaterial, in: RoundedRectangle(cornerRadius: Z.radius, style: .continuous))
            }
            .overlay(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).strokeBorder(Z.border, lineWidth: 1))
            .shadow(color: .black.opacity(0.25), radius: 8, x: 0, y: 2)
    }
}
