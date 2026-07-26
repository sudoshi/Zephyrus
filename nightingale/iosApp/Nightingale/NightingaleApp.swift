import SwiftUI

@main
struct NightingaleApp: App {
    var body: some Scene {
        WindowGroup {
            NightingalePrivacyProtectedRoot()
                .nightingaleTestAccessibilityTextSize()
        }
    }
}

private extension View {
    @ViewBuilder
    func nightingaleTestAccessibilityTextSize() -> some View {
        #if DEBUG
        if ProcessInfo.processInfo.environment[
            "NIGHTINGALE_TEST_ACCESSIBILITY_TEXT_SIZE"
        ] == "1" {
            dynamicTypeSize(.accessibility5)
        } else {
            self
        }
        #else
        self
        #endif
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
    @Environment(\.accessibilityReduceMotion) private var systemReduceMotion
    @Environment(\.accessibilityReduceTransparency) private var systemReduceTransparency
    @Environment(\.colorSchemeContrast) private var colorSchemeContrast
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.colorScheme) private var colorScheme
    @StateObject private var volatileInputState = NightingaleVolatileInputState()
    @StateObject private var presentationPreferences = NightingalePresentationPreferences()

    var body: some View {
        ZStack {
            NightingaleFoundationView(
                presentationPreferences: presentationPreferences,
                policy: presentationPolicy
            )
                .accessibilityHidden(privacyCoverVisible)

            if privacyCoverVisible {
                NightingalePrivacyCoverView(policy: presentationPolicy)
                    .transition(presentationPolicy.reduceMotion ? .identity : .opacity)
                    .zIndex(100)
            }
        }
        .animation(
            presentationPolicy.reduceMotion ? nil : .easeOut(duration: 0.12),
            value: privacyCoverVisible
        )
        .animation(
            presentationPolicy.reduceMotion
                ? nil
                : .easeInOut(duration: presentationPolicy.transitionDuration),
            value: presentationPreferences.snapshot
        )
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

    private var presentationPolicy: NightingaleSceneAccessibilityPolicy {
        NightingaleSceneAccessibilityPolicy.resolve(
            preferences: presentationPreferences.snapshot,
            systemReduceMotion: systemReduceMotion,
            systemReduceTransparency: systemReduceTransparency,
            increasedContrast: colorSchemeContrast == .increased,
            accessibilityTextSize: dynamicTypeSize.isAccessibilitySize,
            darkMode: colorScheme == .dark
        )
    }
}

private struct NightingaleFoundationView: View {
    @ObservedObject var presentationPreferences: NightingalePresentationPreferences
    let policy: NightingaleSceneAccessibilityPolicy

    var body: some View {
        ZStack {
            NightingaleScenicBackground(policy: policy)

            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    if policy.showDecorativeImagery {
                        Image("BrandMark")
                            .resizable()
                            .scaledToFit()
                            .frame(width: 88, height: 88)
                            .accessibilityHidden(true)
                    }

                    Text(NightingaleProductBoundary.productName)
                        .font(.largeTitle.weight(.semibold))
                        .accessibilityAddTraits(.isHeader)
                        .accessibilityIdentifier("nightingale-product-heading")
                    Text("A calm place to understand, prepare, and connect with your care team.")
                        .font(.title3)
                        .foregroundStyle(.secondary)

                    NightingaleFoundationStatusCard(cardOpacity: policy.cardOpacity)
                    NightingaleDisplayComfortCard(
                        presentationPreferences: presentationPreferences,
                        policy: policy
                    )
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
    @Environment(\.colorSchemeContrast) private var colorSchemeContrast
    let cardOpacity: Double

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Label("Your privacy comes first", systemImage: "hand.raised.fill")
                .font(.headline)
                .foregroundStyle(NightingalePalette.forest)
                .accessibilityElement(children: .combine)
                .accessibilityIdentifier("nightingale-privacy-status-heading")
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
    }
}

private struct NightingaleDisplayComfortCard: View {
    @ObservedObject var presentationPreferences: NightingalePresentationPreferences
    let policy: NightingaleSceneAccessibilityPolicy

    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            Text("Display comfort")
                .font(.headline)
                .accessibilityAddTraits(.isHeader)
                .accessibilityIdentifier("nightingale-display-comfort-heading")

            Text("These settings are stored by Nightingale, not your care account. They never change your care information.")
                .font(.footnote)
                .foregroundStyle(.secondary)

            Toggle(
                "Reduce motion in Nightingale",
                isOn: Binding(
                    get: {
                        presentationPreferences.snapshot.reduceMotionRequested
                    },
                    set: presentationPreferences.setReduceMotionRequested
                )
            )
            .frame(minHeight: 44)
            .contentShape(Rectangle())
            .accessibilityIdentifier("nightingale-reduce-motion-toggle")

            Text(
                policy.reduceMotion
                    ? "Motion is reduced. Nightingale changes views without decorative movement."
                    : "Gentle transitions are enabled. Nightingale also follows your system Reduce Motion setting."
            )
            .font(.footnote)
            .foregroundStyle(.secondary)
            .accessibilityIdentifier("nightingale-motion-status")

            Toggle(
                "Hide decorative imagery",
                isOn: Binding(
                    get: {
                        presentationPreferences.snapshot.hideDecorativeImageryRequested
                    },
                    set: presentationPreferences.setHideDecorativeImageryRequested
                )
            )
            .frame(minHeight: 44)
            .contentShape(Rectangle())
            .accessibilityIdentifier("nightingale-hide-imagery-toggle")

            Text(
                policy.showDecorativeImagery
                    ? "The Nightingale artwork is shown softly behind the page."
                    : "Decorative imagery is hidden. Essential text and controls remain available."
            )
            .font(.footnote)
            .foregroundStyle(.secondary)
            .accessibilityIdentifier("nightingale-imagery-status")
        }
        .padding(20)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(
            Color(uiColor: .systemBackground).opacity(policy.cardOpacity),
            in: RoundedRectangle(cornerRadius: 22)
        )
        .overlay {
            RoundedRectangle(cornerRadius: 22)
                .stroke(Color.primary.opacity(0.1))
        }
    }
}
