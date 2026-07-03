import SwiftUI

struct LoginView: View {
    @EnvironmentObject var auth: AuthStore
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var username = "demo"
    @State private var password = "Password123!"
    @State private var slideIndex = 0
    @FocusState private var focused: Field?

    private enum Field { case username, password }
    private let slideTimer = Timer.publish(every: 9.5, on: .main, in: .common).autoconnect()
    private static let slides: [AuthSlide] = [
        .init(asset: "AuthHummingbird10"),
        .init(asset: "AuthHummingbird05"),
        .init(asset: "AuthHummingbird04"),
        .init(asset: "AuthHummingbird11"),
        .init(asset: "AuthHummingbird12"),
        .init(asset: "AuthHummingbird01"),
        .init(asset: "AuthHummingbird08"),
        .init(asset: "AuthHummingbird09"),
        .init(asset: "AuthHummingbird06"),
        .init(asset: "AuthHummingbird03"),
        .init(asset: "AuthHummingbird02"),
        .init(asset: "AuthHummingbird07"),
    ]

    var body: some View {
        ZStack {
            AuthArtworkBackdrop(slides: Self.slides, activeIndex: slideIndex)

            GeometryReader { proxy in
                ScrollView {
                    VStack(spacing: Z.s5) {
                        VStack(spacing: Z.s2) {
                            Image("BrandMark")
                                .resizable()
                                .scaledToFit()
                                .frame(width: 76, height: 76)
                                .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
                                .shadow(color: .black.opacity(0.36), radius: 14, y: 6)
                                .padding(.bottom, Z.s1)
                                .accessibilityLabel("Hummingbird")
                            Text("Hummingbird")
                                .font(.system(size: 28, weight: .semibold))
                                .foregroundStyle(Z.ink)
                            Text("Zephyrus operations, in your pocket")
                                .font(.system(size: 14))
                                .foregroundStyle(Z.inkMuted)
                        }
                        .padding(.top, Z.s3)

                        formCard

                        #if DEBUG
                        Text("Connected to \(AppConfig.baseURL)")
                            .font(.system(size: 11))
                            .foregroundStyle(Z.inkMuted)
                            .padding(.bottom, Z.s3)
                        #endif
                    }
                    .frame(maxWidth: 430)
                    .frame(maxWidth: .infinity)
                    .padding(.horizontal, Z.s5)
                    .padding(.vertical, Z.s6)
                    .frame(minHeight: proxy.size.height, alignment: .center)
                }
                .scrollDismissesKeyboard(.interactively)
            }
        }
        .onReceive(slideTimer) { _ in
            guard !reduceMotion else { return }
            withAnimation(.easeInOut(duration: 1.6)) {
                slideIndex = (slideIndex + 1) % Self.slides.count
            }
        }
        .sensoryFeedback(trigger: auth.errorMessage) { _, new in new == nil ? nil : .error }
        #if DEBUG
        // Test hook (matches HB_AUTOLOGIN/HB_OPEN_PLACEMENT): HB_FOCUS=1 focuses the
        // username field on launch so focus styling can be screenshot-verified.
        .onAppear {
            if ProcessInfo.processInfo.environment["HB_FOCUS"] == "1" { focused = .username }
        }
        #endif
    }

    /// The sign-in form floats as Liquid Glass over the artwork on iOS 26 (the auth
    /// atmosphere is the one sanctioned glass surface); earlier OSes keep the solid Panel.
    @ViewBuilder
    private var formCard: some View {
        if #available(iOS 26.0, *) {
            formContent
                .padding(Z.s4)
                .glassEffect(.regular, in: RoundedRectangle(cornerRadius: 28, style: .continuous))
        } else {
            Panel { formContent }
        }
    }

    private var formContent: some View {
        VStack(alignment: .leading, spacing: Z.s4) {
            field("Username or email", text: $username, field: .username, secure: false)
            field("Password", text: $password, field: .password, secure: true)

            if let error = auth.errorMessage {
                HStack(spacing: Z.s2) {
                    Image(systemName: "exclamationmark.triangle.fill")
                    Text(error).font(.system(size: 13))
                }
                .foregroundStyle(Z.status(.critical))
            }

            HBPrimaryActionButton(title: auth.isBusy ? "Signing in…" : "Sign in",
                                  working: auth.isBusy,
                                  action: submit)
                .disabled(username.isEmpty || password.isEmpty)
                .opacity(username.isEmpty || password.isEmpty ? 0.6 : 1)
        }
    }

    private func submit() {
        focused = nil
        Task { await auth.login(username: username, password: password) }
    }

    @ViewBuilder
    private func field(_ label: String, text: Binding<String>, field: Field, secure: Bool) -> some View {
        VStack(alignment: .leading, spacing: Z.s1) {
            Text(label.uppercased())
                .font(.system(size: 11, weight: .semibold))
                .tracking(0.5)
                .foregroundStyle(Z.inkMuted)
            Group {
                if secure {
                    SecureField("", text: text).focused($focused, equals: field)
                } else {
                    TextField("", text: text)
                        .focused($focused, equals: field)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                }
            }
            .font(.system(size: 16))
            .foregroundStyle(Z.ink)
            .padding(Z.s3)
            .background(RoundedRectangle(cornerRadius: 10).fill(Z.bg))
            // Gold marks focus (the Acumenus focus layer) — never status, never interaction blue.
            .overlay(RoundedRectangle(cornerRadius: 10)
                .strokeBorder(focused == field ? Z.gold : Z.border, lineWidth: focused == field ? 1.5 : 1))
            .animation(.easeOut(duration: 0.15), value: focused)
        }
    }
}

private struct AuthSlide: Identifiable {
    let asset: String
    var id: String { asset }
}

private struct AuthArtworkBackdrop: View {
    let slides: [AuthSlide]
    let activeIndex: Int

    var body: some View {
        GeometryReader { proxy in
            ZStack {
                Color(red: 0.02, green: 0.04, blue: 0.06)

                ForEach(Array(slides.enumerated()), id: \.element.id) { index, slide in
                    Image(slide.asset)
                        .resizable()
                        .scaledToFill()
                        .frame(width: proxy.size.width, height: proxy.size.height)
                        .clipped()
                        .scaleEffect(index == activeIndex ? 1.04 : 1.0)
                        .opacity(index == activeIndex ? 1 : 0)
                }

                LinearGradient(
                    colors: [
                        Color.black.opacity(0.36),
                        Color(red: 0.02, green: 0.04, blue: 0.07).opacity(0.74),
                    ],
                    startPoint: .top,
                    endPoint: .bottom
                )

                LinearGradient(
                    colors: [
                        Color(red: 0.02, green: 0.07, blue: 0.06).opacity(0.56),
                        Color.black.opacity(0.24),
                        Color(red: 0.04, green: 0.06, blue: 0.13).opacity(0.56),
                    ],
                    startPoint: .leading,
                    endPoint: .trailing
                )
            }
            .animation(.easeInOut(duration: 1.6), value: activeIndex)
        }
        .ignoresSafeArea()
        .allowsHitTesting(false)
        .accessibilityHidden(true)
    }
}
