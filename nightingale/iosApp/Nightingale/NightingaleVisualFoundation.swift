import SwiftUI

enum NightingalePalette {
    static let forestUIColor = UIColor { traits in
        if traits.userInterfaceStyle == .dark {
            return UIColor(red: 0.55, green: 0.80, blue: 0.66, alpha: 1)
        }
        return UIColor(red: 0.20, green: 0.38, blue: 0.29, alpha: 1)
    }
    static let forest = Color(uiColor: forestUIColor)
}

struct NightingaleScenicBackground: View {
    let policy: NightingaleSceneAccessibilityPolicy
    let assetName: String

    init(
        policy: NightingaleSceneAccessibilityPolicy,
        date: Date = Date(),
        calendar: Calendar = .autoupdatingCurrent
    ) {
        self.policy = policy
        assetName = NightingaleBackgroundCatalog.assetName(
            for: date,
            calendar: calendar
        )
    }

    var body: some View {
        GeometryReader { proxy in
            ZStack {
                Color(uiColor: .systemBackground)

                if
                    policy.showDecorativeImagery,
                    let backgroundImage
                {
                    Image(uiImage: backgroundImage)
                        .resizable()
                        .scaledToFill()
                        .frame(width: proxy.size.width, height: proxy.size.height)
                        .clipped()
                        .opacity(policy.decorativeImageOpacity)
                }

                LinearGradient(
                    colors: policy.backgroundScrimAlphas.map {
                        Color(uiColor: .systemBackground).opacity($0)
                    },
                    startPoint: .top,
                    endPoint: .bottom
                )
            }
        }
        .ignoresSafeArea()
        .accessibilityHidden(true)
        .allowsHitTesting(false)
    }

    private var backgroundImage: UIImage? {
        guard
            let resourceURL = Bundle.main.url(
                forResource: assetName,
                withExtension: "jpg"
            )
        else {
            return nil
        }

        return UIImage(contentsOfFile: resourceURL.path)
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
                Text(NightingaleCopyKey.productName)
                    .font(.title.bold())
                Text(NightingaleCopyKey.privacyCoverMessage)
                    .font(.body)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
                    .padding(.horizontal, 36)
            }
            .padding(28)
            .background(
                Color(uiColor: .systemBackground),
                in: RoundedRectangle(cornerRadius: 24)
            )
            .overlay {
                RoundedRectangle(cornerRadius: 24)
                    .stroke(Color.primary.opacity(0.12))
            }
            .padding(24)
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel(Text(NightingaleCopyKey.privacyCoverAccessibilityLabel))
        .accessibilityIdentifier("nightingale-privacy-cover")
    }
}
