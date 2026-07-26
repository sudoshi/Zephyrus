import SwiftUI

enum NightingalePalette {
    static let forest = Color(red: 0.20, green: 0.38, blue: 0.29)
    static let warmMist = Color(red: 0.98, green: 0.95, blue: 0.89)
    static let coolMist = Color(red: 0.90, green: 0.96, blue: 0.94)
}

struct NightingaleScenicBackground: View {
    @Environment(\.colorScheme) private var colorScheme
    let policy: NightingaleSceneAccessibilityPolicy

    var body: some View {
        GeometryReader { proxy in
            ZStack(alignment: .bottomTrailing) {
                Color(uiColor: .systemBackground)

                if policy.showDecorativeImagery {
                    LinearGradient(
                        colors: gradientColors,
                        startPoint: .topLeading,
                        endPoint: .bottomTrailing
                    )

                    Image("BrandMark")
                        .resizable()
                        .scaledToFit()
                        .frame(width: min(proxy.size.width * 1.15, 560))
                        .offset(x: proxy.size.width * 0.18, y: proxy.size.height * 0.08)
                        .opacity(policy.decorativeImageOpacity)
                }
            }
        }
        .ignoresSafeArea()
        .accessibilityHidden(true)
        .allowsHitTesting(false)
    }

    private var gradientColors: [Color] {
        if colorScheme == .dark {
            return [Color(uiColor: .systemBackground), NightingalePalette.forest.opacity(0.22)]
        }
        return [NightingalePalette.warmMist, NightingalePalette.coolMist]
    }
}

struct NightingalePrivacyCoverView: View {
    let policy: NightingaleSceneAccessibilityPolicy

    var body: some View {
        ZStack {
            NightingaleScenicBackground(policy: policy)

            VStack(spacing: 14) {
                Image(systemName: "hand.raised.fill")
                    .font(.system(size: 44))
                    .foregroundStyle(NightingalePalette.forest)
                    .accessibilityHidden(true)
                Text("Nightingale")
                    .font(.title.bold())
                Text("Your care information is covered while the app is not active.")
                    .font(.body)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
                    .padding(.horizontal, 36)
            }
            .padding(28)
            .background(
                Color(uiColor: .systemBackground).opacity(0.96),
                in: RoundedRectangle(cornerRadius: 24)
            )
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel("Privacy cover. Your care information is hidden while Nightingale is not active.")
        .accessibilityIdentifier("nightingale-privacy-cover")
    }
}
