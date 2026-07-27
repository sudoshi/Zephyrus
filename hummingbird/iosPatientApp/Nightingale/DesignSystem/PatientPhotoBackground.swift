import SwiftUI

enum PatientPhotoScene: String, CaseIterable {
    case welcome
    case today
    case pathway
    case careTeam
    case messages
    case sessions
    case loading
    case empty
    case error

    var assetName: String {
        switch self {
        case .welcome, .loading: "PatientAiryFlight"
        case .today, .sessions: "PatientCalmGreen"
        case .pathway, .empty: "PatientWarmMotion"
        case .careTeam, .messages, .error: "PatientCareConnection"
        }
    }
}

struct PatientPhotoBackground: View {
    let scene: PatientPhotoScene
    @Environment(\.colorSchemeContrast) private var colorSchemeContrast
    @Environment(\.accessibilityReduceTransparency) private var reduceTransparency
    @Environment(\.colorScheme) private var colorScheme
    @Environment(\.patientPresentationPreferences) private var presentationPreferences

    var body: some View {
        GeometryReader { proxy in
            ZStack {
                if reduceTransparency || presentationPreferences.highContrast {
                    Color(uiColor: .systemBackground)
                } else {
                    Image(scene.assetName)
                        .resizable()
                        .scaledToFill()
                        .frame(width: proxy.size.width, height: proxy.size.height)
                        .clipped()
                        .saturation(colorScheme == .dark ? 0.72 : 0.88)

                    LinearGradient(
                        colors: scrimColors,
                        startPoint: .top,
                        endPoint: .bottom
                    )
                }
            }
        }
        .accessibilityHidden(true)
        .allowsHitTesting(false)
    }

    private var scrimColors: [Color] {
        let background = Color(uiColor: .systemBackground)
        if colorSchemeContrast == .increased {
            return [background.opacity(0.93), background.opacity(0.98)]
        }
        return [background.opacity(0.68), background.opacity(0.92)]
    }
}

struct PatientPhotoStateCard: View {
    let scene: PatientPhotoScene
    let icon: String
    let title: String
    let message: String
    var actionTitle: String?
    var action: (() -> Void)?

    @Environment(\.accessibilityReduceTransparency) private var reduceTransparency
    @Environment(\.colorSchemeContrast) private var colorSchemeContrast
    @Environment(\.patientPresentationPreferences) private var presentationPreferences

    var body: some View {
        ZStack {
            Image(scene.assetName)
                .resizable()
                .scaledToFill()
                .accessibilityHidden(true)

            Color(uiColor: .systemBackground)
                .opacity(
                    reduceTransparency || presentationPreferences.highContrast
                        ? 1
                        : (colorSchemeContrast == .increased ? 0.96 : 0.86)
                )

            VStack(alignment: .leading, spacing: 10) {
                Label(title, systemImage: icon)
                    .font(.headline)
                    .foregroundStyle(scene == .error ? PatientPalette.rose : PatientPalette.blue)
                Text(message)
                    .font(.body)
                    .foregroundStyle(PatientPalette.ink)

                if let actionTitle, let action {
                    Button(actionTitle, action: action)
                        .buttonStyle(.borderedProminent)
                        .padding(.top, 3)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(18)
        }
        .clipShape(RoundedRectangle(cornerRadius: 20))
        .overlay {
            RoundedRectangle(cornerRadius: 20)
                .stroke(
                    Color.primary.opacity(
                        colorSchemeContrast == .increased || presentationPreferences.highContrast
                            ? 0.28
                            : 0.08
                    ),
                    lineWidth: colorSchemeContrast == .increased || presentationPreferences.highContrast ? 2 : 1
                )
        }
        .accessibilityElement(children: .contain)
    }
}

struct PatientLoadingStateView: View {
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Environment(\.patientPresentationPreferences) private var presentationPreferences

    var body: some View {
        ZStack {
            PatientPhotoBackground(scene: .loading)
                .ignoresSafeArea()

            VStack(spacing: 16) {
                ProgressView()
                    .controlSize(.large)
                    .accessibilityHidden(true)
                Text("Opening your care view")
                    .font(.title3.bold())
                Text("Only information released to Hummingbird Patient will appear.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
            }
            .padding(24)
            .frame(maxWidth: 340)
            .background(
                Color(uiColor: .systemBackground).opacity(0.94),
                in: RoundedRectangle(cornerRadius: 22)
            )
            .accessibilityElement(children: .combine)
            .accessibilityLabel("Opening your care view. Only information released to Hummingbird Patient will appear.")
        }
        .transition(
            reduceMotion || presentationPreferences.reducedMotion
                ? .opacity
                : .opacity.combined(with: .scale(scale: 0.98))
        )
        .accessibilityAddTraits(.isModal)
        .accessibilityIdentifier("patient-loading-state")
    }
}
