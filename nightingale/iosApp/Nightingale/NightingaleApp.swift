import SwiftUI

@main
struct NightingaleApp: App {
    var body: some Scene {
        WindowGroup {
            NightingalePrivacyProtectedRoot()
                .nightingaleTestAccessibilityTextSize()
                .nightingaleTestLayoutDirection()
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

    @ViewBuilder
    func nightingaleTestLayoutDirection() -> some View {
        #if DEBUG
        if ProcessInfo.processInfo.environment[
            "NIGHTINGALE_TEST_LAYOUT_DIRECTION"
        ] == "RTL" {
            environment(\.layoutDirection, .rightToLeft)
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
                    foundationHeader

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

    private var foundationHeader: some View {
        VStack(alignment: .leading, spacing: 12) {
            if policy.showDecorativeImagery {
                Image("BrandMark")
                    .resizable()
                    .scaledToFit()
                    .frame(width: 88, height: 88)
                    .accessibilityHidden(true)
            }

            Text(NightingaleCopyKey.productName)
                .font(.largeTitle.weight(.semibold))
                .accessibilityAddTraits(.isHeader)
                .accessibilityIdentifier("nightingale-product-heading")
            Text(NightingaleCopyKey.foundationMission)
                .font(.title3)
                .foregroundStyle(.secondary)
                .accessibilityIdentifier("nightingale-foundation-mission")
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

private struct NightingaleFoundationStatusCard: View {
    @Environment(\.colorSchemeContrast) private var colorSchemeContrast
    let cardOpacity: Double

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Label {
                Text(NightingaleCopyKey.privacyHeading)
            } icon: {
                Image(systemName: "hand.raised.fill")
            }
                .font(.headline)
                .foregroundStyle(NightingalePalette.forest)
                .accessibilityElement(children: .combine)
                .accessibilityAddTraits(.isHeader)
                .accessibilityIdentifier("nightingale-privacy-status-heading")
            Text(NightingaleCopyKey.foundationUnavailable)
                .font(.body)
            Text(NightingaleCopyKey.foundationNoPatientData)
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
            Text(NightingaleCopyKey.displayComfortHeading)
                .font(.headline)
                .accessibilityAddTraits(.isHeader)
                .accessibilityIdentifier("nightingale-display-comfort-heading")

            Text(NightingaleCopyKey.displayComfortScope)
                .font(.footnote)
                .foregroundStyle(.secondary)

            Toggle(
                isOn: Binding(
                    get: {
                        presentationPreferences.snapshot.reduceMotionRequested
                    },
                    set: { reduced in
                        presentationPreferences.setReduceMotionRequested(reduced)
                        NightingaleAccessibilityAnnouncement.motionStatus(
                            reduced: reduced
                        )
                    }
                )
            ) {
                Text(NightingaleCopyKey.reduceMotionLabel)
            }
            .frame(minHeight: 44)
            .contentShape(Rectangle())
            .accessibilityIdentifier("nightingale-reduce-motion-toggle")

            Text(
                policy.reduceMotion
                    ? NightingaleCopyKey.motionReducedStatus
                    : NightingaleCopyKey.motionStandardStatus
            )
            .font(.footnote)
            .foregroundStyle(.secondary)
            .accessibilityIdentifier("nightingale-motion-status")

            Toggle(
                isOn: Binding(
                    get: {
                        presentationPreferences.snapshot.hideDecorativeImageryRequested
                    },
                    set: { hidden in
                        presentationPreferences.setHideDecorativeImageryRequested(hidden)
                        NightingaleAccessibilityAnnouncement.imageryStatus(hidden: hidden)
                    }
                )
            ) {
                Text(NightingaleCopyKey.hideImageryLabel)
            }
            .frame(minHeight: 44)
            .contentShape(Rectangle())
            .accessibilityIdentifier("nightingale-hide-imagery-toggle")

            Text(
                policy.showDecorativeImagery
                    ? NightingaleCopyKey.imageryShownStatus
                    : NightingaleCopyKey.imageryHiddenStatus
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
