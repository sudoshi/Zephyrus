# Hummingbird — Mobile Best-Practices Research

**Companion native mobile app for Zephyrus** (Laravel + React hospital-operations command center).
Targets: **Android — Kotlin / Jetpack Compose**, **iOS — Swift / SwiftUI**.
Users: ED clinicians, charge nurses, bed managers, OR staff, transporters, EVS, ops leaders, executives.

- **Date:** 2026-06-26
- **Scope:** Grounded in current (2024–2026) best practices via web research. Opinionated recommendations, not "it depends."
- **Status:** Research input to the implementation plan. No app source files were modified.

> **Reading note for the plan author:** Every section ends with a **Recommendation** block. The "Top 10" and "Biggest risks/decisions" at the bottom are the executive summary. Anchor citations are inline; full source list is at the end.

---

## 0. Executive thesis (one paragraph)

Build **two native apps** (Compose + SwiftUI) sharing a **Kotlin Multiplatform (KMP) domain/data layer** behind a **purpose-built mobile BFF** on the Laravel side. Authenticate with **OAuth2 Authorization-Code + PKCE via Laravel Passport** (not raw Sanctum tokens), store tokens in **Keychain / Android Keystore** gated by **biometrics**, and **never put PHI in a notification, log, or screen-capture surface**. Keep the command-center view live with a **push-first, websocket-when-foregrounded, poll-as-fallback** model so you don't drain batteries. Treat **"earned urgency" as a notification-routing architecture** (tiered severity → channel → escalation), with **iOS Critical Alerts + Android full-screen intents reserved for true breaches only**. Drive all three platforms from **one design-token source (Style Dictionary / DTCG JSON)** so the blue/slate `healthcare-*` system and crimson/gold heritage layer never drift.

---

## 1. Native vs cross-platform — validate the Kotlin + Swift decision, and add a shared KMP domain layer

### The landscape (2024–2026)
- Adoption: React Native still leads installed base (~42% share), Flutter strong on Google's backing, **KMP roughly doubled in a year** (7% → 18% in the JetBrains Developer Ecosystem survey) and is now Stable (since Nov 2023) with **official Google support (Google I/O 2024)** for sharing business logic between Android and iOS. ([kotlinlang.org][kmp-rn], [kmpship.app][kmp-vs])
- Performance: 2025 benchmarks give **KMP the leanest binaries and lowest idle memory**; Flutter the fastest cold-start/frame-rate; React Native's new Fabric/Bridgeless architecture improved but still trails under heavy UI. ([mvpappforge.com][kmp-bench])

### Why native UI is right for Hummingbird specifically
A clinical command center is **glanceability- and platform-affordance-heavy**: Dynamic Island / Live Activities, widgets/complications, full-screen intents, biometric prompts, Focus/Critical-Alert behavior, VoiceOver/TalkBack, Dynamic Type. These are exactly the surfaces where **native UI pays off** and where cross-platform UI toolkits lag or need per-platform escape hatches. The user's instinct (native Kotlin + Swift) is correct for this product.

### But fully-separate codebases double the *logic* cost
The repeated, defensible business logic in a hospital-ops app — bed-state machines, ED acuity/LOS rules, status-color thresholds ("earned urgency"), offline sync/queue, conflict resolution, auth/token refresh, DTO mapping, analytics — is the part most likely to **drift between platforms** and cause clinical-correctness bugs. Companies running the **"shared Kotlin logic + native UI"** model (H&M, McDonald's, Google Workspace, Netflix, Cash App, Forbes) report ~40% cost savings on the iOS expansion by reusing the Android business layer. ([kotlinlang.org][kmp-rn], [batteriesincluded.io][bi-kmp])

### Recommendation
**Two native UIs (Jetpack Compose + SwiftUI) over a shared KMP domain + data layer.** Concretely:

| Layer | Implementation | Shared? |
|---|---|---|
| UI / Navigation / OS integrations | Compose (Android), SwiftUI (iOS) | ❌ per-platform |
| Presentation (ViewModels / state) | KMP shared ViewModel exposing `StateFlow` | ✅ optional but recommended |
| Domain (use cases, status rules, sync engine) | KMP (`commonMain`) | ✅ |
| Data (Ktor client, Room/SQLDelight cache, DTOs, conflict logic) | KMP | ✅ |
| Secure storage, biometrics, push registration | `expect`/`actual` per platform | platform-specific bodies |

- Use **SKIE (Touchlab)** to make the Kotlin API *feel native in Swift* — Flows become `AsyncSequence`, suspend functions become `async`, sealed classes become exhaustively-switchable Swift enums. This removes the worst of the Obj-C-interop friction and is production-proven. ([skie.touchlab.co][skie])
- Use **KMMBridge** to publish the shared module as an XCFramework the iOS app consumes like any SPM/CocoaPods dependency. ([powersync.com][kmmbridge])
- **Do NOT adopt Compose Multiplatform for shared UI** here. CMP for iOS is stable (1.8.0, May 2025) but you specifically want native SwiftUI affordances (Dynamic Island, Live Activities, system widgets, accessibility). Share logic, render native. ([kotlinlang.org/compose-multiplatform][cmp])

**Tradeoff to accept:** a KMP layer adds Kotlin/Native build complexity to the iOS toolchain and requires Swift devs to consume a Kotlin-shaped (even with SKIE) API. **Fallback if the team has zero appetite for KMP build/CI overhead or no Kotlin skills on the iOS side:** ship fully-separate codebases but then **mandate a shared, versioned API contract (OpenAPI) + a shared spec/test suite for the status-color & sync rules** so the two implementations cannot diverge silently. KMP is the better answer; separate-with-contract is the acceptable floor.

---

## 2. Architecture patterns — Android & iOS

### Android (recommended)
**MVVM with Unidirectional Data Flow (UDF), trending toward MVI for the complex screens.** This is Google's current guidance: state flows down from ViewModel to Compose, events flow up. ([medium/androidlab][android-arch], [droidcon][droidcon-15yrs])

Stack:
- **UI:** Jetpack Compose; `collectAsStateWithLifecycle()` to consume state. Compose remains Android's recommended toolkit.
- **State:** ViewModel exposes a single immutable `UiState` via `StateFlow`. Use a **sealed `UiState` (Loading / Content / Error)** — the "LCE" pattern — per screen. ([developer.android.com offline-first][android-offline])
- **Async/reactive:** Kotlin Coroutines + Flow.
- **DI:** **Hilt** (Dagger under the hood, far less boilerplate).
- **Navigation:** Navigation-Compose (or Compose-multiplatform-friendly Decompose/Voyager if state lives in KMP).
- **Networking:** **Ktor client** (so it can live in `commonMain`/KMP). Retrofit is fine for Android-only, but Ktor keeps the door open for sharing.
- **Local cache:** **Room** (or **SQLDelight** if you want the DB in KMP too — SQLDelight is the cross-platform choice).
- **Key-value/prefs/flags:** **DataStore** (Proto or Preferences). Not for tokens — see §5.
- **Background:** **WorkManager** for sync/queue drain (constraint: `NetworkType.CONNECTED`, expedited where appropriate). ([android-offline][android-offline])

Use **MVI (explicit intents → reducer → single ViewState)** for the dense, multi-source screens (the live command-center board) where state determinism and testability matter; plain MVVM-UDF for simpler screens. This MVI/MVVM hybrid is the 2025 consensus. ([medium/hiren][android-ultimate])

### iOS (recommended)
**MVVM + `@Observable` (Observation framework, iOS 17+) + Coordinators + Swift Concurrency, Swift 6 strict concurrency.** This is the 2026 "boring, productive middle" that a rotating clinical-app team can maintain. ([forasoft][ios-mvvmc], [medium/mrhotfix][ios-playbook])

Stack:
- **UI:** SwiftUI (drop to UIKit only where SwiftUI genuinely doesn't fit).
- **State:** `@Observable` macro replaces `ObservableObject`+`@Published` — less ceremony, fewer Combine pipelines. ([medium/minalkewat][ios-modern-mvvm])
- **Async:** `async`/`await` + actors everywhere; structured concurrency for cancellation.
- **Networking:** `URLSession` with async APIs (Alamofire only if you need its conveniences). If KMP owns the data layer, this lives in Ktor instead and iOS just calls the shared API.
- **Local cache:** **SwiftData** (iOS 17+) for iOS-native persistence; **Core Data** if you must support older OS or need its maturity. If the cache is in KMP, it's **SQLDelight** and SwiftData is skipped.
- **Secure storage:** **Keychain** (see §5/§6).
- **Architecture escalation:** Reserve **TCA (The Composable Architecture)** only if a screen is a genuine complex state machine *and* the team already knows TCA — it's worth the learning curve only when state determinism is a product requirement, not by default. ([commitstudio][ios-tca])

### Recommendation
Android = **MVVM-UDF + MVI for dense screens, Compose + Hilt + Coroutines/Flow + Ktor + SQLDelight/Room + WorkManager.** iOS = **MVVM + `@Observable` + Coordinators + async/await + SwiftData (or SQLDelight via KMP) + Keychain.** If the KMP domain layer (§1) is adopted, the **ViewModels and data layer are shared Kotlin**, and Compose/SwiftUI become thin render layers binding to a shared `StateFlow`. That maximizes correctness reuse while keeping native feel.

---

## 3. Offline-first & sync

### Is offline-first worth it here?
**Partially — be selective.** In healthcare, offline access is treated as near-mandatory for *clinical record* surfaces, and "spotty hospital Wi-Fi" is the stated reality. But Hummingbird is an **operations** companion, not the EHR of record. Full bidirectional offline-first (push-sync + CRDT) is expensive and risky for *write*-heavy clinical state. ([think-it.io][offline-health], [accountablehq][phi-mobile])

Decide per data type:

| Data | Strategy | Rationale |
|---|---|---|
| Read-mostly board state (census, bed map, ED track board, OR schedule) | **Offline cache, online-refresh** (cache-then-network, show on launch, refresh in background) | Glanceability under bad Wi-Fi; staleness is tolerable if *visibly indicated* |
| User actions that must not be lost (acknowledge alert, claim task, mark bed clean) | **Lazy write** (write local first → queue → drain via WorkManager/BGTask with exponential backoff) | Avoid losing a transporter's "task done" tap on dead Wi-Fi |
| Safety-critical, must-succeed-now actions (e.g., accept a transfer that allocates a bed) | **Online-only write** (disable control when offline; clear error) | Better to block than to create a phantom allocation |

This three-way split (online-only / queued / lazy) is exactly Google's official offline-first write guidance. ([developer.android.com offline-first][android-offline])

### Core patterns (both platforms)
1. **Single source of truth = the local DB.** UI reads only from the local store (observable `Flow`/`@Observable`); the network layer feeds the DB, never the UI directly. ([android-offline][android-offline])
2. **Separate models** (network DTO / DB entity / domain model) with explicit mappers — prevents API churn from corrupting the cache.
3. **Optimistic updates with rollback.** Reflect the action in UI immediately; reconcile/rollback on sync failure. Requires a rollback path and a visible "pending" state. ([medium/offline-arch][offline-arch])
4. **Delta sync, batched + compressed.** On reconnect, pull only changed records since a server cursor/`updated_at`; batch queued writes rather than firing per-change. ([alphasoftware][offline-field])
5. **Conflict resolution = mostly server-authoritative + last-write-wins by timestamp**, with **field-level merge** where independent fields changed. CRDTs are overkill for ops state and add real complexity — **do not start with CRDTs**; reserve them only if you ever build collaborative free-text. ([adalo][adalo-sync], [android-offline][android-offline])
6. **Background sync:** Android **WorkManager** (connectivity-constrained, unique work, `Result.retry()` for backoff); iOS **BGTaskScheduler** (`BGAppRefreshTask` + `BGProcessingTask`). Push (§4/§7) should *trigger* a sync rather than carry the full payload.

### Stale-data indication (non-negotiable for clinical trust)
Every cached view must show **"as of HH:MM"** and a **degraded/offline badge** when data is older than a threshold or the device is offline. Pair status with **icon + label, never color alone** (matches the Zephyrus design principle). A bed map that silently shows 20-minute-old occupancy is a safety problem; a bed map that says "stale — last synced 22 min ago" is honest.

### Recommendation
**Offline-*aware*, not full offline-first.** Cache read surfaces aggressively (cache-then-network), queue non-critical writes (lazy), block safety-critical writes when offline, **last-write-wins + field merge** for conflicts (no CRDTs initially), background sync via WorkManager / BGTaskScheduler, and **always render an explicit "as-of" timestamp + offline/stale badge.**

---

## 4. Push notifications & "earned urgency" (the hardest product problem)

### Transport architecture
- **Android:** FCM (single shared Google Play Services connection across all apps → battery-efficient). Use **Notification Channels** (user-controllable per category) — one channel per *severity/urgency class*, not per event type. ([spritle][push-deep], [designgurus][push-scale])
- **iOS:** APNs over HTTP/2 (token-based auth, `.p8`). FCM can fan out to APNs so you keep one server-side API if desired. Use **UNNotificationCategory** for actionable buttons. ([spritle][push-deep])
- Server stores a **device registry** (device token ↔ user ↔ role ↔ unit) so you can route by who/where, and prune dead tokens.

### Notification taxonomy — map "earned urgency" to OS primitives
This is where the Zephyrus design principle becomes architecture. Define **severity tiers** and bind each to a transport/channel:

| Tier | Example | iOS mechanism | Android mechanism | Bypasses silent/Focus? |
|---|---|---|---|---|
| **Critical / breach** (life-safety, true breach) | Code, rapid-response, ED LWBS breach on *your* patient | **Critical Alert** (entitlement) **+** consider **Live Activity** | **Full-screen intent** + high-importance channel | **Yes** — overrides mute/DND |
| **Time-sensitive / actionable** | "Bed 12 ready — accept?" assigned to you | **Time Sensitive** interruption level (`UNNotificationInterruptionLevel.timeSensitive`) + **actionable category** | High-importance channel + action buttons | Breaks Focus, respects mute |
| **Routine / informational** | Census crossed a soft threshold unit-wide | Default/Active level | Default channel | No |
| **Passive / ambient** | Trend update for a dashboard you follow | **Live Activity / widget update** only (no alert) | Widget / ongoing notification update | No |

- **Critical Alerts require an Apple entitlement** granted only for genuine health/safety/security use cases via a manual review (days–weeks, resubmission common). User must *also* opt in via `requestAuthorization([... .criticalAlert])`; payload sets `sound: { critical: 1, volume: 1.0 }`. **Do not request it for engagement** or you'll be rejected. ([newly.app][crit-alerts], [Apple docs][apple-crit])
- **Android full-screen intents (`USE_FULL_SCREEN_INTENT`)** are, since Android 14 (enforced Jan 22 2025), **granted-by-default only to calling/alarm apps**; everything else must request user permission. A clinical breach app can justify it, but plan the **runtime permission request UX** and a graceful fallback to a high-importance heads-up notification. ([source.android.com fsi][fsi-limits], [Play Console][fsi-play])

### Actionable notifications (reduce taps for time-pressured staff)
Both platforms support **action buttons directly on the notification** (Acknowledge / Accept / Decline / Snooze) — iOS `UNNotificationAction`, Android `Notification.Action` / `RemoteInput`. For a charge nurse, "Acknowledge" from the lock screen without opening the app is the difference between a usable and an ignored tool. **Round-trip the action to the BFF** and reflect it in the board.

### Triage logic — *which events page whom* (server-side, the real lever)
The literature on alarm/alert fatigue is blunt: **most clinical alerts are non-actionable noise, and untargeted alerting causes clinicians to ignore even the important ones.** Mitigations that work: ([jmir][jmir-alert], [jamia][jamia-alert], [psnet][psnet-alert], [tigerconnect][tiger-alert])
1. **Tier by clinical severity; only the top tier is interruptive.**
2. **Route to the *right single person/role*** (the assigned clinician / on-call charge nurse for *that* unit), not broadcast.
3. **Role + context tailoring** (a transporter and an executive should never get the same page).
4. **Trend/multi-parameter correlation before firing** — page on a *breach or sustained trend*, not every threshold tick (cuts false positives).
5. **Escalation pathways:** if unacknowledged within N minutes, escalate to the next person in the hierarchy (this is also how you justify Critical Alerts/FSI — they back a real escalation contract).
6. **Quiet hours / Focus respect for non-critical tiers**, and per-user/per-role notification preferences. Respect the OS Focus/DND for everything below "critical."

> **FDA caution to design around:** the FDA has warned that phone Focus/DND/"deep sleep" settings cause *missed* critical medical alerts. So the system must (a) detect/encourage correct permission setup, and (b) use Critical Alerts/FSI *only* for the genuinely life-safety tier — and back them with server-side escalation so a muted phone doesn't mean an unhandled breach. ([fda.gov][fda-alert])

### Recommendation
**Build notification routing as a first-class server-side service** keyed on (event severity → role/assignment → user prefs → escalation timer), mapping four tiers onto OS primitives as above. **Reserve Critical Alerts + full-screen intents for the single life-safety/true-breach tier**, request those entitlements early (Apple review is slow), make routine alerts respect Focus/quiet-hours, and make every alert that *implies an action* actionable from the notification. This is the concrete implementation of "earned urgency."

---

## 5. Authentication & security (Laravel backend → mobile)

### The core shift
Web Zephyrus uses **session cookies** (per the repo's auth rules + `session-auth.md`). Mobile must move to **token-based auth**. Two viable Laravel paths:

| Option | What you get | Verdict for Hummingbird |
|---|---|---|
| **Sanctum personal-access tokens** | Simple opaque tokens, default Laravel API stack | **Insufficient alone:** no refresh tokens, no Authorization-Code/PKCE flow, no rotation. Fine for trivial first-party apps, weak for a multi-role healthcare app needing short-lived tokens + refresh + revocation. ([abbacus][sanctum-vs], [onecodesoft][sanctum-pass]) |
| **Passport (OAuth2 server) — Authorization Code + PKCE** | Full OAuth2/OIDC-style flow, **refresh tokens**, PKCE (no client secret on device), revocation, scopes | **Recommended.** PKCE is *the* native-app flow per RFC 8252 / RFC 7636. ([laravel.com/passport][passport-docs], [oauth.net native][oauth-native]) |

**Recommendation: Laravel Passport with Authorization-Code + PKCE** (or an external OIDC IdP — the repo already references Authentik SSO in `DEVLOG-authentik-sso-fleet`; if that IdP is the org standard, **point the mobile apps at Authentik via OIDC + PKCE and have Laravel validate the IdP's tokens**, which is cleaner than running Passport *and* an SSO). Either way: **OAuth2 Authorization-Code + PKCE, short-lived access tokens (~15 min) + rotating refresh tokens.**

### Native-app auth flow (RFC 8252 / RFC 7636)
- Perform the authorize step in an **external user agent / system browser tab** (ASWebAuthenticationSession on iOS, Custom Tabs on Android) — **never an embedded WebView**. Use **AppAuth** libraries (AppAuth-iOS, AppAuth-Android). ([oauth.net native][oauth-native], [auth0][auth0-native])
- **PKCE** with a per-request code verifier (43–128 chars, high entropy). ([RFC 8252][rfc8252])
- **Short-lived access token + rotating refresh token**; consider **DPoP** to bind refresh tokens against replay. ([medium/basak][oauth-bp])

### Token storage
- **iOS:** **Keychain** (Secure Enclave-backed where possible), item accessibility `kSecAttrAccessibleWhenUnlockedThisDeviceOnly`.
- **Android:** **Android Keystore** for keys; **EncryptedSharedPreferences** (or DataStore + Keystore-wrapped key) for the token blob. Hardware-backed (StrongBox/TEE) where available.
- **Never** store tokens in plain `UserDefaults`/`SharedPreferences`/`DataStore`. ([capgo][capgo-storage], [okta][okta-pkce])

### Biometric auth + session policy
- Gate token *use* (or app unlock) behind **Face ID / Touch ID (`LAContext`) / BiometricPrompt**. Bind the decryption key to biometric/passcode with **user-authentication-bound keys**, and **invalidate keys on biometric re-enrollment**. Biometric match stays on-device (Secure Enclave/TEE) — the app only gets a yes/no. ([ostorlab][ostorlab-bio], [securecodinghub][scoding-auth])
- **Idle session timeout** (auto-lock after N minutes inactivity → require biometric re-auth), plus absolute token expiry. (HIPAA "automatic logoff" — see §6.)
- **Certificate pinning** on the API/BFF and IdP hosts to defend against MITM on hospital networks. ([accountablehq][hipaa-checklist])

### What the Laravel backend must add for mobile
1. **Passport (or OIDC-token validation)** with PKCE Authorization-Code grant + refresh-token rotation + token revocation endpoint.
2. **Device registration endpoint** (push token ↔ user ↔ role) + token pruning.
3. **A mobile BFF** (see §10) exposing aggregated, mobile-shaped, versioned endpoints separate from the web session routes (do **not** weaken or bypass the existing session/`must_change_password` auth — *add* alongside it, per the repo's auth-system rules).
4. **Scopes/abilities per role** so a transporter token can't read executive dashboards.
5. **Server-side audit logging** of token issuance, PHI access, and notification actions (§6).
6. **TLS 1.2+/1.3 only**, HSTS, modern cipher suites, support for cert pinning.

> ⚠️ **Repo guardrail:** `.claude/rules/auth-system.md` forbids modifying the existing web auth (temp-password + Resend + `must_change_password` + `ChangePasswordModal`). The mobile token stack must be **purely additive** — new Passport/OIDC + BFF routes — and must **honor `must_change_password`** (e.g., mobile login of a not-yet-reset user returns a "change password on web first" state). Do not regress any existing endpoint.

### Recommendation
**OAuth2 Authorization-Code + PKCE via Laravel Passport (or org OIDC/Authentik), AppAuth + system browser, short-lived access + rotating refresh tokens, Keychain/Keystore storage gated by biometrics, idle auto-lock, certificate pinning.** Add mobile auth + device-registry + BFF routes **additively**; never touch the protected web auth flow.

---

## 6. HIPAA / PHI on mobile — concrete checklist

Hummingbird surfaces PHI-adjacent operational data (patient location, status, names on a board). Treat it as PHI. Checklist (each item is implementable and testable):

**Encryption**
- [ ] **In transit:** TLS 1.2+ (prefer **TLS 1.3**), strong ciphers, **certificate pinning** on API/IdP. ([accountablehq][hipaa-checklist], [kiteworks][hipaa-audit])
- [ ] **At rest:** **AES-256** for any local DB/cache/files; keys in **Keychain / Android Keystore** (hardware-backed). Encrypt the cache DB (SQLCipher / SwiftData+FileProtection / Room+SQLCipher).
- [ ] **Minimize on-device PHI:** cache the least necessary; prefer IDs + fetch-on-demand for sensitive detail; set short cache TTLs.

**Notifications / logs / leakage**
- [ ] **No PHI in push notifications.** Use **generic, non-identifying copy** ("New bed-ready alert — tap to view") and fetch detail in-app after auth. This is the single highest-risk PHI leak on mobile. ([accountablehq][phi-mobile])
- [ ] **No PHI in logs / crash reports / analytics.** Scrub identifiers; disable verbose logging in release; configure crash reporters (e.g., disable PHI capture) and a PII-redaction layer.
- [ ] **No PHI in the app-switcher snapshot.** iOS: blur/replace the view on `scenePhase`→`.background` (cover with a privacy view). Android: set **`FLAG_SECURE`** on PHI windows (also blocks screenshots/screen-record). ([talsec][talsec-screen], [ptkd][ptkd-screen])

**Screen-capture protection**
- [ ] **Android:** `WindowManager.LayoutParams.FLAG_SECURE` on PHI screens → excluded from screenshots & screen recording (true prevention). ([talsec][talsec-screen])
- [ ] **iOS:** no API to *block* screenshots; instead **detect via `UIScreen.isCaptured`/`userDidTakeScreenshotNotification`**, obscure content while captured, use `isSecureTextEntry` for sensitive fields, and rely on **MDM app-protection policies** for managed devices. Set expectations: iOS can deter/respond, not fully prevent. ([ptkd][ptkd-screen], [talsec][talsec-screen])

**Access / session / device**
- [ ] **Automatic logoff:** idle timeout → biometric/passcode re-auth (HIPAA technical safeguard).
- [ ] **Biometric/passcode app lock** (§5).
- [ ] **Role-based access control** end-to-end (scopes on tokens + server enforcement).
- [ ] **Jailbreak/root detection** → block or degrade on compromised devices.
- [ ] **MDM / remote wipe:** support enrollment (Intune/Jamf/etc.) and **selective remote wipe** of app data on device loss/deprovision; document MDM app-protection policies (block copy/paste of PHI, block managed→unmanaged data flow). ([accountablehq][phi-mobile], [o365reports][intune-screen])
- [ ] **Device attestation:** **App Attest (iOS) / Play Integrity (Android)** to ensure requests come from a genuine, unmodified app.

**Audit & process**
- [ ] **Audit logging** of PHI access (who/when/what/which record) and notification actions, **stored encrypted (AES-256 at rest, TLS in transit)**, server-side, tamper-evident, retained per policy. ([kiteworks][hipaa-audit])
- [ ] **BAAs** with any third party touching PHI (push provider, crash/analytics, MDM, cloud).
- [ ] **No PHI to non-BAA third parties** (analytics SDKs are a classic violation — gate them).

### Recommendation
Adopt the checklist as **acceptance criteria** for the plan. The two highest-leverage, lowest-cost wins: **(1) generic notification copy (zero PHI in payloads)** and **(2) `FLAG_SECURE` + app-switcher privacy view + screenshot detection.** Then encryption-at-rest (SQLCipher), auto-logoff, RBAC, attestation, MDM/remote-wipe, and encrypted server-side audit logs.

---

## 7. Real-time data on mobile without draining the battery

### The transport decision (battery-aware)
- **WebSockets (Laravel Reverb + Echo, Pusher protocol):** ~90% less server load and <50 ms latency vs polling for high-frequency updates; **battery-friendly only while foregrounded** with a persistent connection. **The OS suspends sockets in the background**, and frequent heartbeats *do* drain battery. Reverb is a first-party Laravel WS server and works with the Pusher protocol, so native clients can use Pusher-compatible Kotlin/Swift libraries. ([reverb.laravel.com][reverb], [laravel.com/reverb][reverb-docs], [github laravel #52699][reverb-mobile])
- **Push (FCM/APNs):** connectionless from your server's view, delivery handled by Google/Apple's already-open connection → **most battery-efficient for background**. Best for "wake the app / refresh now" and alerts. ([curiosum][curiosum-ws-push])
- **Polling:** simplest, degrades gracefully, but **constant requests drain battery**; acceptable as a low-frequency fallback or for rare updates. ([websocket.org][ws-vs-poll], [getstream][long-poll])

### Recommended hybrid (the "live command center without battery death" pattern)
1. **Foreground & screen-on:** open a **Reverb/Echo websocket** subscribed only to the channels the current screen needs (the unit/board in view). Live, sub-second, efficient *while in use*. ([reverb-docs][reverb-docs])
2. **Backgrounded:** **drop the socket**; rely on **silent/data push (FCM `data` / APNs `content-available`)** to *trigger* a delta sync (WorkManager / `BGAppRefreshTask`), and alert pushes for the notification tiers in §4. Never try to hold a socket open in the background.
3. **Live Activities / widgets:** update via **APNs push to the Live Activity / ActivityKit** or periodic widget refresh — *not* a held socket. (Hospitals also love wall-board displays; a dedicated always-on kiosk view can use the socket since it's plugged in.)
4. **Reconnect/backoff:** on foreground, reconnect socket + run a **catch-up delta sync** so you never rely on having received every in-background event over the socket.
5. **Polling** only as the degraded fallback when sockets fail and as the mechanism for low-frequency followed-metrics.

**Background execution budgets are strict** — iOS `BGTaskScheduler` runs opportunistically (you don't control timing), Android WorkManager respects Doze/battery. So **the source of truth for "you have a new alert" is push, not the socket**; the socket is a *foreground liveness optimization*. ([curiosum][curiosum-ws-push])

### Recommendation
**Push-first, socket-when-foregrounded, poll-as-fallback.** Foreground = Reverb/Echo websocket scoped to the visible board; background = silent push → WorkManager/BGTask delta sync + alert pushes; Live Activities updated by APNs push; never hold a background socket. This keeps the command-center view live in-hand while letting the OS handle battery in-pocket.

---

## 8. Clinical mobile UX

Design rules tuned for time-pressured, gloved, one-handed, night-shift hospital staff:

- **Glanceability first.** The home screen answers "what needs me right now?" in <2 seconds. Status by **icon + arrow + label, never color alone** (matches Zephyrus). Big numbers in **`tabular-nums`**.
- **The 3-tap rule.** Critical functions reachable in ≤3 taps; actionable notifications collapse common actions to **0 taps** (act from lock screen). ([tactionsoft][health-ux])
- **Large tap targets / one-handed.** **Minimum 44×44 pt (WCAG 2.2 SC 2.5.8 Target Size AA = 24×24 CSS px floor; aim 44–48)**; primary actions in the thumb zone (bottom of screen). ([w3.org WCAG 2.5.8][wcag258], [usablenet][a11y-mobile])
- **Role-based home screens.** ED clinician, charge nurse, bed manager, transporter, EVS, ops leader, executive each get a **different default surface** — mirror the web role switcher. The fastest UI is the one that already shows *your* job.
- **Dark mode as a first-class theme** (night shift, dim units) — dual-theme, dark-default per Zephyrus; verify contrast in both. ([a11y-mobile][a11y-mobile])
- **Accessibility = WCAG 2.2 AA (pragmatic):** Dynamic Type / font-scaling without clipping, full **VoiceOver / TalkBack** labels, sufficient contrast, no color-only status, support external keyboard/switch. ([usablenet][a11y-mobile], [wcag258][wcag258])
- **At-a-glance OS surfaces (high value here):**
  - **iOS Live Activities + Dynamic Island** for an in-progress op (transfer in flight, ED LWBS countdown, OR turnover timer) — glanceable, server-updatable via APNs, ≤4 KB payload, "live status not marketing." ([canopas][live-activities], [pushwoosh][pw-live])
  - **iOS Widgets / Lock-Screen widgets + Apple Watch complications**, **Android App Widgets + Wear OS tiles** for ambient ops KPIs (current census, beds available). ([dev.to forge][live-android])
  - **Wearables (Watch/Wear OS):** strong fit for charge nurses/transporters who can't hold a phone — a **glance + acknowledge** wrist surface for the top alert tier is a credible Phase-2 differentiator.
- **Reduce taps / cognitive load:** defaults over choices, persistent filters, optimistic UI (instant feedback), and clear "pending/stale" states (ties to §3).

### Recommendation
**Role-based, glanceable, thumb-reachable, dark-first, WCAG 2.2 AA, 3-tap-max, act-from-notification.** Invest early in **Live Activities/Dynamic Island + widgets** for ambient ops status (this is where native shines and competitors lag), and scope **Watch/Wear "glance + acknowledge"** as a fast-follow.

---

## 9. Design-system parity — one system, three platforms, zero drift

Zephyrus already has a strict token canon (the `healthcare-*` blue/slate system + crimson/gold heritage layer, Figtree 400/500/600, 4px grid, Surface primitive) enforced by the "impeccable design hook." The mobile risk is **the two native apps re-implementing colors/spacing by hand and drifting** from web and each other.

### Approach: a single token source compiled to all three platforms
- **Author tokens once in DTCG JSON** (Design Tokens Community Group / W3C format) and compile with **Style Dictionary v5** → it emits **CSS/SCSS (web), iOS Swift, Android XML, Jetpack Compose, Flutter** from one source. Default export is DTCG JSON; v5 tracks the 2025.10 DTCG spec. ([github style-dictionary][sd-gh], [styledictionary.com][sd-formats], [zeroheight][sd-v5])
- Pipeline: `tokens/*.json` (colors incl. dark pairs, type scale, spacing, radii, shadows) → Style Dictionary build → generated artifacts checked into each platform (`Color.kt`/Compose theme, `Colors.swift`/SwiftUI `Color` set, and the existing Tailwind/CSS vars). Regenerate on token change; the generated files are **build outputs, not hand-edited**.
- **Encode the Two-System Rule in tokens:** name tokens semantically (`color.surface`, `color.status.critical`, `color.brand.heritage`) so the crimson/gold heritage layer is a *named, restricted* token set and can't be accidentally promoted to a dashboard primary — same guardrail the web app enforces, now machine-checked across platforms.
- **Status colors** (teal/amber/coral/sky) become shared semantic tokens with light/dark values, so "earned urgency" coloring is identical web↔iOS↔Android.
- Keep **Figtree** as the single family on mobile too (bundle the font), weights 400/500/600 only — mirrors the no-`font-bold` rule.

### Recommendation
**Style Dictionary + DTCG JSON as the single source of truth for color/type/spacing/radii/shadow tokens, compiled to Compose, SwiftUI, and the existing web CSS vars.** Generated token files are build artifacts. Encode the blue/slate-vs-crimson/gold Two-System Rule and the teal/amber/coral/sky status ramp as semantic tokens so all three platforms stay locked together and the heritage layer can't drift into a primary.

---

## 10. Backend-for-Frontend (BFF) — should mobile hit `/api` directly?

### The problem
A command-center screen is **chatty**: census + bed map + ED track board + alerts + assignments might be 5–8 calls. On hospital cellular/Wi-Fi with high latency, hitting the existing React-oriented `/api` endpoints directly means **many round-trips, over-fetching web-shaped payloads, and client-side aggregation** — bad for battery and perceived speed. A one-size-fits-all API bloats mobile payloads. ([medium/umesh][bff-vs-agg], [aws][aws-bff], [marmelab][marmelab-bff])

### Recommendation: build a thin mobile BFF
**Yes — give mobile a dedicated BFF/aggregation layer, not direct `/api` access.** A Laravel-side mobile BFF should:
- **Aggregate** the chatty board into **one screen-shaped endpoint** per home view (e.g., `GET /mobile/v1/board/{unit}` returns census + beds + alerts + assignments in a single mobile-optimized payload). Fewer round-trips = better battery + latency. ([abstractalgorithms][bff-tailor])
- **Trim & reshape** payloads to exactly what the mobile screen renders (no over-fetch).
- **Own mobile concerns:** token validation, per-role scoping, response caching/ETags, rate limiting, retry/circuit-breaking to internal services, and **PHI minimization** (strip fields the mobile screen doesn't show).
- **Version explicitly** (`/mobile/v1/…`) so app releases and API evolve independently (mobile clients update slowly).
- **Be the contract boundary** the KMP data layer (§1) targets — one OpenAPI spec, both native clients generated/validated against it.

Implementation can be a **dedicated set of Laravel controllers/routes** (simplest, co-located, reuses existing services) rather than a separate microservice — appropriate for a single backend team. Avoid GraphQL here: it handles the data-shaping but is awkward for the file/stream/websocket and notification-action operations you also need, and adds learning cost. A REST BFF is the pragmatic fit. ([medium/umesh][bff-vs-agg])

### Recommendation
**Add a mobile BFF as versioned Laravel routes (`/mobile/v1/*`)** that aggregate each command-center screen into one reshaped, PHI-minimized, role-scoped response. It's the auth/caching/contract boundary for both native apps. **Don't** point mobile at the web `/api` directly, and **don't** reach for GraphQL.

---

## Top 10 opinionated recommendations (executive summary)

1. **Two native UIs (Compose + SwiftUI) over a shared Kotlin Multiplatform domain/data layer**, bridged to Swift with SKIE — share the defensible logic (status rules, sync, auth), render native. ([kotlinlang.org][kmp-rn], [skie.touchlab.co][skie])
2. **Android = MVVM-UDF (MVI for dense screens) + Compose + Hilt + Coroutines/Flow + Ktor + WorkManager; iOS = MVVM + `@Observable` + async/await + SwiftData/Keychain.** Boring, maintainable, current. ([android-arch][android-arch], [ios-mvvmc][ios-mvvmc])
3. **Offline-*aware*, not full offline-first:** cache read surfaces (cache-then-network), lazy-queue non-critical writes, block safety-critical writes offline, last-write-wins + field-merge (no CRDTs), and **always show an "as-of" timestamp + stale badge.** ([android-offline][android-offline])
4. **Make notification routing a server-side, four-tier "earned-urgency" service** (severity → role/assignment → prefs → escalation). Reserve **iOS Critical Alerts + Android full-screen intents for the true-breach tier only.** ([jmir][jmir-alert], [newly.app][crit-alerts], [fsi-limits][fsi-limits])
5. **Actionable notifications** (Acknowledge/Accept from the lock screen) — cut time-pressured staff to 0 taps for the common action. ([spritle][push-deep])
6. **Auth = OAuth2 Authorization-Code + PKCE via Laravel Passport (or org OIDC/Authentik)**, short-lived access + rotating refresh tokens, AppAuth + system browser, tokens in Keychain/Keystore gated by biometrics, idle auto-lock, cert pinning — added **purely additively** to the protected web auth. ([passport-docs][passport-docs], [oauth-native][oauth-native])
7. **No PHI in notifications, logs, or app-switcher snapshots;** generic push copy + `FLAG_SECURE` (Android) + privacy-view/screenshot-detection (iOS) are the two cheapest, highest-impact HIPAA wins. ([phi-mobile][phi-mobile], [talsec][talsec-screen])
8. **Real-time = push-first, websocket-when-foregrounded (Reverb/Echo), poll-as-fallback;** never hold a background socket — silent push triggers WorkManager/BGTask delta sync. ([reverb][reverb], [curiosum][curiosum-ws-push])
9. **One design-token source (Style Dictionary + DTCG JSON) compiled to Compose + SwiftUI + web CSS vars,** encoding the Two-System Rule and teal/amber/coral/sky status ramp so three platforms never drift. ([sd-gh][sd-gh])
10. **Give mobile a versioned Laravel BFF (`/mobile/v1/*`)** that aggregates each command-center screen into one reshaped, PHI-minimized, role-scoped response — don't point mobile at the web `/api`, don't use GraphQL. ([bff-vs-agg][bff-vs-agg], [aws-bff][aws-bff])

## The 3 biggest risks/decisions the implementation plan must resolve

1. **KMP commitment vs team skills/CI cost.** The shared-Kotlin-logic strategy is the highest-leverage architectural bet, but it puts Kotlin/Native in the iOS build and asks Swift devs to consume a (SKIE-smoothed) Kotlin API. **Decision required:** commit to KMP + KMMBridge + SKIE *now*, or ship fully-separate apps with a mandated OpenAPI contract + shared status-rule/sync test spec as the anti-drift floor. Deciding late forces an expensive re-architecture.
2. **"Earned urgency" is a clinical-safety system, not a feature.** Getting Critical Alerts / full-screen-intent entitlements (slow Apple review; Android 14 runtime permission since Jan 2025) **and** the server-side severity/role/escalation routing right is the make-or-break for clinical trust and the single biggest source of alarm-fatigue or, worse, *missed* breaches (per the FDA warning on Focus/DND). **Decision required:** the tiered taxonomy, escalation contract, and entitlement requests must be designed and submitted early — they gate the release.
3. **PHI boundary + auth additivity under the repo's locked auth rules.** Mobile must move web's session-cookie auth to token-based **without touching** the protected temp-password/`must_change_password` flow, *and* must guarantee PHI never leaks via notifications/logs/screenshots/non-BAA SDKs, with MDM/remote-wipe/attestation. **Decision required:** confirm the BFF + Passport/OIDC layer is purely additive (and how it honors `must_change_password`), pick managed-device/MDM posture, and lock the "generic notification copy + FLAG_SECURE/privacy-view + encrypted audit log" baseline as non-negotiable acceptance criteria.

---

## Sources

**1 — Native vs cross-platform / KMP**
- [Kotlin Multiplatform vs React Native (official)][kmp-rn] — https://kotlinlang.org/docs/multiplatform/kotlin-multiplatform-react-native.html
- [KMP vs Flutter vs React Native 2025][kmp-vs] — https://www.kmpship.app/blog/kmp-vs-flutter-vs-react-native-2025
- [KMP vs Flutter vs RN — performance/cost/hiring][kmp-bench] — https://www.mvpappforge.com/blog/kotlin-multiplatform-vs-flutter-vs-react-native
- [Share logic only vs Compose UI too (2026)][bi-kmp] — https://batteriesincluded.io/insights/kotlin-multiplatform-and-compose-multiplatform
- [Compose Multiplatform (official)][cmp] — https://kotlinlang.org/compose-multiplatform/
- [SKIE — Swift Kotlin Interface Enhancer][skie] — https://skie.touchlab.co/
- [KMMBridge + SKIE publishing a native Swift SDK][kmmbridge] — https://powersync.com/blog/using-kotlin-multiplatform-with-kmmbridge-and-skie-to-publish-a-native-swift-sdk

**2 — Architecture patterns**
- [Modern Android Architecture 2025 (MVVM/MVI/Clean)][android-arch] — https://medium.com/@androidlab/modern-android-app-architecture-in-2025-mvvm-mvi-and-clean-architecture-with-jetpack-compose-c0df3c727334
- [Ultimate Guide to Modern Android Architecture 2025][android-ultimate] — https://medium.com/@hiren6997/the-ultimate-guide-to-modern-android-app-architecture-2025-edition-963ce4bc8bfc
- [15 Years of Android App Architectures (droidcon)][droidcon-15yrs] — https://www.droidcon.com/2025/10/20/15-years-of-android-app-architectures/
- [2026 iOS MVVM-C Playbook][ios-mvvmc] — https://www.forasoft.com/blog/article/advanced-ios-app-architecture-explained-on-mvvm-977
- [Architecture Playbook for iOS 2025][ios-playbook] — https://medium.com/@mrhotfix/the-architecture-playbook-for-ios-2025-swiftui-concurrency-modular-design-a35b98cbf688
- [Modern MVVM in SwiftUI 2025 (@Observable)][ios-modern-mvvm] — https://medium.com/@minalkewat/modern-mvvm-in-swiftui-2025-the-clean-architecture-youve-been-waiting-for-72a7d576648e
- [Composable Architecture (TCA) in 2025][ios-tca] — https://commitstudiogs.medium.com/composable-architecture-in-2025-building-scalable-swiftui-apps-the-right-way-134199aff811

**3 — Offline-first & sync**
- [Build an offline-first app (Android official)][android-offline] — https://developer.android.com/topic/architecture/data-layer/offline-first
- [Offline-First Architecture: designing for reality][offline-arch] — https://medium.com/@jusuftopic/offline-first-architecture-designing-for-reality-not-just-the-cloud-e5fd18e50a79
- [Offline + Sync Architecture for Field Operations][offline-field] — https://www.alphasoftware.com/blog/offline-sync-architecture-tutorial-examples-tools-for-field-operations
- [Offline vs Real-Time Sync: managing conflicts][adalo-sync] — https://www.adalo.com/posts/offline-vs-real-time-sync-managing-data-conflicts
- [Building Offline Apps: mobile resilience (healthcare)][offline-health] — https://think-it.io/insights/offline-apps

**4 — Push notifications & alarm fatigue**
- [Push Notifications Deep Dive: APNs & FCM][push-deep] — https://www.spritle.com/blog/push-notifications-deep-dive-the-ultimate-technical-guide-to-apns-fcm/
- [Scaling Push Notifications to 50M devices][push-scale] — https://designgurus.substack.com/p/push-notification-architecture-apns
- [How to get the Apple Critical Alerts entitlement][crit-alerts] — https://newly.app/articles/critical-alerts-entitlement
- [Critical Alerts (Apple Developer docs)][apple-crit] — https://developer.apple.com/documentation/bundleresources/entitlements/com.apple.developer.usernotifications.critical-alerts
- [Full-screen intent limits (AOSP)][fsi-limits] — https://source.android.com/docs/core/permissions/fsi-limits
- [Full-screen intent & foreground service (Play Console)][fsi-play] — https://support.google.com/googleplay/android-developer/answer/13392821
- [Alert fatigue in primary care — qualitative review (JMIR 2025)][jmir-alert] — https://www.jmir.org/2025/1/e62763
- [Medication safety alert fatigue — interaction design & role tailoring (JAMIA)][jamia-alert] — https://academic.oup.com/jamia/article/26/10/1141/5519579
- [Alert Fatigue (AHRQ PSNet primer)][psnet-alert] — https://psnet.ahrq.gov/primer/alert-fatigue
- [How hospitals reduce alert fatigue (TigerConnect)][tiger-alert] — https://tigerconnect.com/resources/blog-articles/how-hospitals-can-reduce-alert-fatigue-and-enhance-patient-safety
- [FDA: missed critical alerts due to phone settings][fda-alert] — https://www.fda.gov/news-events/press-announcements/fda-alerts-patients-potential-miss-critical-safety-alerts-due-phone-settings-when-using-smartphone-compatible-diabetes-devices

**5 — Auth & security**
- [Laravel Sanctum vs Passport: strategy for 2025][sanctum-vs] — https://www.abbacustechnologies.com/laravel-sanctum-vs-passport-authentication-strategy-for-2025/
- [Laravel API Security 2025: Sanctum vs Passport][sanctum-pass] — https://onecodesoft.com/blogs/laravel-api-security-2025-sanctum-vs-passport-guide
- [Laravel Passport (official docs)][passport-docs] — https://laravel.com/docs/13.x/passport
- [OAuth 2.0 for Mobile and Native Apps][oauth-native] — https://oauth.net/2/native-apps/
- [RFC 8252 — OAuth 2.0 for Native Apps][rfc8252] — https://datatracker.ietf.org/doc/html/rfc8252
- [OAuth 2.0 best practices for native apps (Auth0)][auth0-native] — https://auth0.com/blog/oauth-2-best-practices-for-native-apps/
- [Secure Express app with OAuth/OIDC/PKCE (Okta)][okta-pkce] — https://developer.okta.com/blog/2025/07/28/express-oauth-pkce
- [OAuth 2.0 security best practices: PKCE/DPoP][oauth-bp] — https://medium.com/@basakerdogan/oauth-2-0-security-best-practices-from-authorization-code-to-pkce-beccdbe7ec35
- [Secure token storage best practices (mobile)][capgo-storage] — https://capgo.app/blog/secure-token-storage-best-practices-for-mobile-developers/
- [Secure mobile biometric authentication (Ostorlab)][ostorlab-bio] — https://medium.com/@ostorlab/introduction-d481bcc774c8
- [Mobile app authentication best practices (iOS+Android 2026)][scoding-auth] — https://www.securecodinghub.com/blog/mobile-app-authentication-best-practices-ios-android

**6 — HIPAA / PHI**
- [HIPAA compliance for mobile app developers — checklist][hipaa-checklist] — https://www.accountablehq.com/post/hipaa-compliance-for-mobile-app-developers-step-by-step-guide-and-checklist
- [PHI in mobile apps — HIPAA & security best practices][phi-mobile] — https://www.accountablehq.com/post/phi-in-mobile-apps-hipaa-compliance-and-security-best-practices
- [HIPAA audit log requirements 2025][hipaa-audit] — https://www.kiteworks.com/hipaa-compliance/hipaa-audit-log-requirements/
- [Block screenshots/screen recording (Android & iOS) — Talsec][talsec-screen] — https://docs.talsec.app/appsec-articles/articles/how-to-block-screenshots-screen-recording-and-remote-access-tools-in-android-and-ios-apps
- [iOS screen-capture & screenshot detection (PTKD)][ptkd-screen] — https://ptkd.com/journal/ios-screen-capture-screenshot-detection
- [Block screen capture with Intune (MDM)][intune-screen] — https://o365reports.com/2024/05/07/block-screen-capture-on-android-and-ios-devices-with-intune/

**7 — Real-time on mobile**
- [Laravel Reverb (official site)][reverb] — https://reverb.laravel.com/
- [Laravel Reverb (docs)][reverb-docs] — https://laravel.com/docs/13.x/reverb
- [Integrating Laravel Reverb with Android/iOS (discussion)][reverb-mobile] — https://github.com/laravel/framework/discussions/52699
- [Mobile push notifications vs WebSockets (Curiosum)][curiosum-ws-push] — https://curiosum.com/blog/mobile-push-notifications-description-and-comparison-with-web-sockets
- [WebSocket vs long polling — performance & when to use][ws-vs-poll] — https://websocket.org/comparisons/long-polling/
- [Long polling vs WebSockets (GetStream)][long-poll] — https://getstream.io/blog/long-polling-vs-websockets/

**8 — Clinical mobile UX**
- [Healthcare mobile app design best practices][health-ux] — https://www.tactionsoft.com/blog/healthcare-mobile-app-design/
- [WCAG 2.5.8 Target Size (Minimum) — W3C Understanding][wcag258] — https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html
- [Mobile app accessibility guidelines (UsableNet)][a11y-mobile] — https://blog.usablenet.com/mobile-app-accessibility-guidelines
- [Integrating Live Activity & Dynamic Island (Canopas)][live-activities] — https://medium.com/canopas/integrating-live-activity-and-dynamic-island-in-ios-a-complete-guide-d8448fab7201
- [iOS Live Activities — how they work & best practices (Pushwoosh)][pw-live] — https://www.pushwoosh.com/blog/ios-live-activities/
- [Dynamic Island for iOS + Live Notifications/Now Bar on Android][live-android] — https://dev.to/forge-stackobea/how-to-implement-dynamic-island-for-ios-and-live-notifications-now-bar-on-android-155b

**9 — Design-system parity**
- [Style Dictionary (GitHub)][sd-gh] — https://github.com/style-dictionary/style-dictionary
- [Style Dictionary built-in formats (iOS/Compose/CSS)][sd-formats] — https://styledictionary.com/reference/hooks/formats/predefined/
- [Migrating to Style Dictionary v5 + DTCG (zeroheight)][sd-v5] — https://help.zeroheight.com/hc/en-us/articles/48049028236187-Migrating-to-Style-Dictionary-v5-in-tokens-automation

**10 — BFF**
- [BFF vs Aggregation Layer (API gateway patterns)][bff-vs-agg] — https://medium.com/@umesh382.kushwaha/bff-vs-aggregation-layer-choosing-the-right-api-gateway-pattern-for-modern-microservices-9a6c4dab8f12
- [Backends for Frontends pattern (AWS)][aws-bff] — https://aws.amazon.com/blogs/mobile/backends-for-frontends-pattern/
- [Do you need a Backend For Frontend? (Marmelab)][marmelab-bff] — https://marmelab.com/blog/2025/10/01/do-you-need-a-backend-for-frontend.html
- [Backend for Frontend: tailoring APIs for UI][bff-tailor] — https://www.abstractalgorithms.dev/backend-for-frontend-pattern

<!-- Reference link definitions -->
[kmp-rn]: https://kotlinlang.org/docs/multiplatform/kotlin-multiplatform-react-native.html
[kmp-vs]: https://www.kmpship.app/blog/kmp-vs-flutter-vs-react-native-2025
[kmp-bench]: https://www.mvpappforge.com/blog/kotlin-multiplatform-vs-flutter-vs-react-native
[bi-kmp]: https://batteriesincluded.io/insights/kotlin-multiplatform-and-compose-multiplatform
[cmp]: https://kotlinlang.org/compose-multiplatform/
[skie]: https://skie.touchlab.co/
[kmmbridge]: https://powersync.com/blog/using-kotlin-multiplatform-with-kmmbridge-and-skie-to-publish-a-native-swift-sdk
[android-arch]: https://medium.com/@androidlab/modern-android-app-architecture-in-2025-mvvm-mvi-and-clean-architecture-with-jetpack-compose-c0df3c727334
[android-ultimate]: https://medium.com/@hiren6997/the-ultimate-guide-to-modern-android-app-architecture-2025-edition-963ce4bc8bfc
[droidcon-15yrs]: https://www.droidcon.com/2025/10/20/15-years-of-android-app-architectures/
[ios-mvvmc]: https://www.forasoft.com/blog/article/advanced-ios-app-architecture-explained-on-mvvm-977
[ios-playbook]: https://medium.com/@mrhotfix/the-architecture-playbook-for-ios-2025-swiftui-concurrency-modular-design-a35b98cbf688
[ios-modern-mvvm]: https://medium.com/@minalkewat/modern-mvvm-in-swiftui-2025-the-clean-architecture-youve-been-waiting-for-72a7d576648e
[ios-tca]: https://commitstudiogs.medium.com/composable-architecture-in-2025-building-scalable-swiftui-apps-the-right-way-134199aff811
[android-offline]: https://developer.android.com/topic/architecture/data-layer/offline-first
[offline-arch]: https://medium.com/@jusuftopic/offline-first-architecture-designing-for-reality-not-just-the-cloud-e5fd18e50a79
[offline-field]: https://www.alphasoftware.com/blog/offline-sync-architecture-tutorial-examples-tools-for-field-operations
[adalo-sync]: https://www.adalo.com/posts/offline-vs-real-time-sync-managing-data-conflicts
[offline-health]: https://think-it.io/insights/offline-apps
[push-deep]: https://www.spritle.com/blog/push-notifications-deep-dive-the-ultimate-technical-guide-to-apns-fcm/
[push-scale]: https://designgurus.substack.com/p/push-notification-architecture-apns
[crit-alerts]: https://newly.app/articles/critical-alerts-entitlement
[apple-crit]: https://developer.apple.com/documentation/bundleresources/entitlements/com.apple.developer.usernotifications.critical-alerts
[fsi-limits]: https://source.android.com/docs/core/permissions/fsi-limits
[fsi-play]: https://support.google.com/googleplay/android-developer/answer/13392821
[jmir-alert]: https://www.jmir.org/2025/1/e62763
[jamia-alert]: https://academic.oup.com/jamia/article/26/10/1141/5519579
[psnet-alert]: https://psnet.ahrq.gov/primer/alert-fatigue
[tiger-alert]: https://tigerconnect.com/resources/blog-articles/how-hospitals-can-reduce-alert-fatigue-and-enhance-patient-safety
[fda-alert]: https://www.fda.gov/news-events/press-announcements/fda-alerts-patients-potential-miss-critical-safety-alerts-due-phone-settings-when-using-smartphone-compatible-diabetes-devices
[sanctum-vs]: https://www.abbacustechnologies.com/laravel-sanctum-vs-passport-authentication-strategy-for-2025/
[sanctum-pass]: https://onecodesoft.com/blogs/laravel-api-security-2025-sanctum-vs-passport-guide
[passport-docs]: https://laravel.com/docs/13.x/passport
[oauth-native]: https://oauth.net/2/native-apps/
[rfc8252]: https://datatracker.ietf.org/doc/html/rfc8252
[auth0-native]: https://auth0.com/blog/oauth-2-best-practices-for-native-apps/
[okta-pkce]: https://developer.okta.com/blog/2025/07/28/express-oauth-pkce
[oauth-bp]: https://medium.com/@basakerdogan/oauth-2-0-security-best-practices-from-authorization-code-to-pkce-beccdbe7ec35
[capgo-storage]: https://capgo.app/blog/secure-token-storage-best-practices-for-mobile-developers/
[ostorlab-bio]: https://medium.com/@ostorlab/introduction-d481bcc774c8
[scoding-auth]: https://www.securecodinghub.com/blog/mobile-app-authentication-best-practices-ios-android
[hipaa-checklist]: https://www.accountablehq.com/post/hipaa-compliance-for-mobile-app-developers-step-by-step-guide-and-checklist
[phi-mobile]: https://www.accountablehq.com/post/phi-in-mobile-apps-hipaa-compliance-and-security-best-practices
[hipaa-audit]: https://www.kiteworks.com/hipaa-compliance/hipaa-audit-log-requirements/
[talsec-screen]: https://docs.talsec.app/appsec-articles/articles/how-to-block-screenshots-screen-recording-and-remote-access-tools-in-android-and-ios-apps
[ptkd-screen]: https://ptkd.com/journal/ios-screen-capture-screenshot-detection
[intune-screen]: https://o365reports.com/2024/05/07/block-screen-capture-on-android-and-ios-devices-with-intune/
[reverb]: https://reverb.laravel.com/
[reverb-docs]: https://laravel.com/docs/13.x/reverb
[reverb-mobile]: https://github.com/laravel/framework/discussions/52699
[curiosum-ws-push]: https://curiosum.com/blog/mobile-push-notifications-description-and-comparison-with-web-sockets
[ws-vs-poll]: https://websocket.org/comparisons/long-polling/
[long-poll]: https://getstream.io/blog/long-polling-vs-websockets/
[health-ux]: https://www.tactionsoft.com/blog/healthcare-mobile-app-design/
[wcag258]: https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html
[a11y-mobile]: https://blog.usablenet.com/mobile-app-accessibility-guidelines
[live-activities]: https://medium.com/canopas/integrating-live-activity-and-dynamic-island-in-ios-a-complete-guide-d8448fab7201
[pw-live]: https://www.pushwoosh.com/blog/ios-live-activities/
[live-android]: https://dev.to/forge-stackobea/how-to-implement-dynamic-island-for-ios-and-live-notifications-now-bar-on-android-155b
[sd-gh]: https://github.com/style-dictionary/style-dictionary
[sd-formats]: https://styledictionary.com/reference/hooks/formats/predefined/
[sd-v5]: https://help.zeroheight.com/hc/en-us/articles/48049028236187-Migrating-to-Style-Dictionary-v5-in-tokens-automation
[bff-vs-agg]: https://medium.com/@umesh382.kushwaha/bff-vs-aggregation-layer-choosing-the-right-api-gateway-pattern-for-modern-microservices-9a6c4dab8f12
[aws-bff]: https://aws.amazon.com/blogs/mobile/backends-for-frontends-pattern/
[marmelab-bff]: https://marmelab.com/blog/2025/10/01/do-you-need-a-backend-for-frontend.html
[bff-tailor]: https://www.abstractalgorithms.dev/backend-for-frontend-pattern
