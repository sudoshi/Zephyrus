import SwiftUI

@main
struct NightingaleApp: App {
    var body: some Scene {
        WindowGroup {
            NightingalePrivacyProtectedRoot()
        }
    }
}

/// Compile-time guard for the safe, pre-pilot Nightingale foundation.
enum NightingaleProductBoundary {
    static let productName = "Nightingale"
    static let livePatientAccessEnabled = false
    static let staffEndpointsPermitted = false
}

private struct NightingalePrivacyProtectedRoot: View {
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @StateObject private var volatileInputState = NightingaleVolatileInputState()

    var body: some View {
        ZStack {
            NightingaleFoundationView()
                .accessibilityHidden(privacyCoverVisible)

            if privacyCoverVisible {
                NightingalePrivacyCoverView()
                    .transition(reduceMotion ? .identity : .opacity)
                    .zIndex(100)
            }
        }
        .animation(reduceMotion ? nil : .easeOut(duration: 0.12), value: privacyCoverVisible)
        .onChange(of: scenePhase) { _, newPhase in
            if newPhase != .active {
                volatileInputState.clear(.applicationInactive)
            }
        }
    }

    private var privacyCoverVisible: Bool {
        #if DEBUG
        scenePhase != .active
            || ProcessInfo.processInfo.environment["NIGHTINGALE_SHOW_PRIVACY_COVER"] == "1"
        #else
        scenePhase != .active
        #endif
    }
}

private struct NightingaleFoundationView: View {
    var body: some View {
        ZStack {
            NightingaleScenicBackground()

            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    Image("BrandMark")
                        .resizable()
                        .scaledToFit()
                        .frame(width: 88, height: 88)
                        .accessibilityHidden(true)

                    Text(NightingaleProductBoundary.productName)
                        .font(.largeTitle.weight(.semibold))
                        .accessibilityAddTraits(.isHeader)
                    Text("A calm place to understand, prepare, and connect with your care team.")
                        .font(.title3)
                        .foregroundStyle(.secondary)

                    NightingaleFoundationStatusCard()
                }
                .frame(maxWidth: 520, alignment: .leading)
                .padding(.horizontal, 28)
                .padding(.vertical, 56)
            }
        }
        .accessibilityIdentifier("nightingale-safe-shell")
    }
}

private struct NightingaleFoundationStatusCard: View {
    @Environment(\.accessibilityReduceTransparency) private var reduceTransparency
    @Environment(\.colorSchemeContrast) private var colorSchemeContrast

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Label("Your privacy comes first", systemImage: "hand.raised.fill")
                .font(.headline)
                .foregroundStyle(NightingalePalette.forest)
            Text("Live patient access is not available in this foundation build. Please ask your care team for current information.")
                .font(.body)
            Text("No patient information is stored or requested by this build.")
                .font(.footnote)
                .foregroundStyle(.secondary)
        }
        .padding(20)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(
            Color(uiColor: .systemBackground).opacity(cardOpacity),
            in: RoundedRectangle(cornerRadius: 22)
        )
        .overlay {
            RoundedRectangle(cornerRadius: 22)
                .stroke(Color.primary.opacity(colorSchemeContrast == .increased ? 0.32 : 0.1))
        }
        .accessibilityElement(children: .contain)
    }

    private var cardOpacity: Double {
        reduceTransparency || colorSchemeContrast == .increased ? 1 : 0.92
    }
}
