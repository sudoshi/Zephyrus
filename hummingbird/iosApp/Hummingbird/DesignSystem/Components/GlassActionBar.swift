import SwiftUI

// Liquid Glass lives in the functional layer only (floating actions, chrome, overlays);
// content panels stay solid Z tokens. Everything here gates on iOS 26 and falls back to
// the pre-26 ultra-thin-material strip so the iOS 17 deploy target keeps working.

/// Bottom action cluster for detail screens. On iOS 26 the actions float as glass
/// capsules in a shared `GlassEffectContainer`; earlier OSes keep the frosted strip.
struct HBActionBar<Content: View>: View {
    @ViewBuilder let content: Content

    var body: some View {
        if #available(iOS 26.0, *) {
            GlassEffectContainer {
                VStack(spacing: Z.s2) { content }
            }
            .padding(.horizontal, Z.s4)
            .padding(.vertical, Z.s2)
        } else {
            VStack(spacing: Z.s2) { content }
                .padding(Z.s4)
                .background(.ultraThinMaterial)
        }
    }
}

/// The one big primary action on a detail screen (Claim / Start / Complete / Place…).
struct HBPrimaryActionButton: View {
    let title: String
    var working: Bool = false
    let action: () -> Void

    var body: some View {
        Group {
            if #available(iOS 26.0, *) {
                Button(action: action) { label.padding(.vertical, Z.s1) }
                    .buttonStyle(.glassProminent)
                    .controlSize(.large)
                    .tint(Z.primary)
            } else {
                Button(action: action) {
                    label
                        .padding(.vertical, Z.s3)
                        .background(RoundedRectangle(cornerRadius: 12)
                            .fill(working ? Z.primary.opacity(0.5) : Z.primary))
                }
            }
        }
        .disabled(working)
    }

    private var label: some View {
        HStack(spacing: Z.s2) {
            if working { ProgressView().tint(.white) }
            Text(title).font(.system(size: 17, weight: .semibold))
        }
        .frame(maxWidth: .infinity)
        .foregroundStyle(.white)
    }
}

/// Quieter companion action (Reject / cancel-tier), clear glass on 26+.
struct HBSecondaryActionButton: View {
    let title: String
    var tint: Color = Z.primary
    var working: Bool = false
    let action: () -> Void

    var body: some View {
        Group {
            if #available(iOS 26.0, *) {
                Button(action: action) {
                    Text(title)
                        .font(.system(size: 15, weight: .medium))
                        .foregroundStyle(tint)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, Z.s1)
                }
                .buttonStyle(.glass)
            } else {
                Button(action: action) {
                    Text(title).font(.system(size: 15, weight: .medium)).foregroundStyle(tint)
                }
            }
        }
        .disabled(working)
    }
}

extension View {
    /// Soft scroll-edge diffusion so content dissolves under the floating glass tab bar
    /// instead of clipping behind it (iOS 26+); no-op on earlier OSes.
    @ViewBuilder
    func hbScrollEdge() -> some View {
        if #available(iOS 26.0, *) {
            self.scrollEdgeEffectStyle(.soft, for: .bottom)
        } else {
            self
        }
    }
}

/// Terminal-state banner ("Trip complete") — a floating glass capsule on 26+.
struct HBCompletionBanner: View {
    let icon: String
    let text: String
    var tone: CapacityStatus = .success

    var body: some View {
        if #available(iOS 26.0, *) {
            label
                .padding(.vertical, Z.s3)
                .padding(.horizontal, Z.s5)
                .glassEffect()
                .frame(maxWidth: .infinity)
                .padding(.vertical, Z.s2)
        } else {
            label
                .frame(maxWidth: .infinity)
                .padding(Z.s4)
                .background(.ultraThinMaterial)
        }
    }

    private var label: some View {
        HStack(spacing: Z.s2) {
            Image(systemName: icon).foregroundStyle(Z.status(tone))
            Text(text).font(.system(size: 15, weight: .semibold)).foregroundStyle(Z.ink)
        }
    }
}
