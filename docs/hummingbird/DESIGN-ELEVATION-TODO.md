# Hummingbird Design Elevation TODO

**Status:** Execution checklist (living document — check items only when functional & verified)
**Created:** 2026-07-03, from the deep design/UX audit (iOS + Android, code + live simulator tour)
**Doctrine:** Apple puts Liquid Glass in the *functional layer* (nav, tab bars, floating action
bars, transient overlays) and explicitly NOT in the content layer — which matches DESIGN.md
("glass only for auth atmosphere and true overlays; working surfaces stay solid").
Glass = chrome/overlays/login. KPI tiles, panels, census lists = solid `Z` tokens, always.

Verification bar for checking an item: builds clean, behavior confirmed in the running app
(simulator screenshot or test), no regression to the calm/earned-urgency rules.

---

## Wave 1 — iOS: Liquid Glass chrome + feel

### W1.1 Glass floating action bars (highest leverage)
- [x] `JobDetailView` bottom action bar: wrap actions in `GlassEffectContainer`; primary action
      `.buttonStyle(.glassProminent)` tinted `Z.primary`, secondary `.glass` — gated
      `if #available(iOS 26.0, *)`, current `.ultraThinMaterial` bar as fallback.
- [x] `TurnDetailView` bottom action bar: same treatment.
- [x] `PlacementDetailView` bottom action bar: same treatment (Place = glassProminent,
      Reject = glass). *Verified in sim: "Place in 5W-22" floats as a tinted glass capsule,
      content reads through; new `HB_OPEN_PLACEMENT=<n>` test hook drills into the nth placement.*
- [x] Shared helper so the three bars stay identical (`DesignSystem/Components/GlassActionBar.swift`:
      `HBActionBar` / `HBPrimaryActionButton` / `HBSecondaryActionButton` / `HBCompletionBanner`).

### W1.2 Login: glass card over the photography
- [x] Replace the flat slate form panel in `LoginView` with a Liquid Glass card
      (`.glassEffect(.regular, in: RoundedRectangle(cornerRadius: 28))`, iOS 26 gated;
      fallback = solid Panel). *Verified in sim — artwork reads through the card.*
- [x] Gate the "Connected to <host>" footer line to `#if DEBUG` (still visible in Debug builds
      by design; stripped from Release/TestFlight).
- [x] Verify with Reduce Transparency that the form remains fully legible (fields are solid
      `Z.bg` fills with AA ink — legible over the darkest and brightest slides).

### W1.3 Chrome & scroll-edge
- [x] Adopt `scrollEdgeEffectStyle(.soft)` via `hbScrollEdge()` on the tab shell (iOS 26 gated,
      no-op earlier) so content diffuses under the floating tab bar.
- [x] Unify per-tab chrome: profile button now on every tab (For You + Activity gained the
      toolbar button + ProfileView sheet, matching all role homes).

### W1.4 Haptics (state changes deserve a pulse)
- [x] `.sensoryFeedback(.success)` on transport lifecycle advance (trigger: status), EVS
      lifecycle advance (trigger: status), placement Place/Reject (trigger: decided counter).
      *Wired + builds; physical feel needs a device pass (simulator has no haptic engine).*
- [x] `.sensoryFeedback(.error)` on failed placement submissions and failed logins.
- [x] Selection feedback on onboarding role/unit pick; success feedback on persona switch
      (ProfileView, trigger: profile.roleId).

### W1.5 Loading skeletons
- [x] Shared skeleton component (`DesignSystem/Components/SkeletonRows.swift`) — static
      panel-shaped placeholders (no shimmer; Reduce-Motion-safe by construction).
- [x] Replaced first-load spinners on Home, For You, Transport, EVS, House Capacity, Activity,
      Executive, Staffing, Improvement, OR Board, Capacity Demand (11 screens).

### W1.6 Craft bugs (from the live tour)
- [x] Pressured-unit rows: "Emergency Departm…" truncation fixed — name wraps to two lines,
      count + chip keep layout priority. *Verified in sim.*
- [x] Executive House Brief strain: outlined 10pt gauge-track segments (instrument at rest)
      instead of 26pt gray slabs; accessibility label "Surge level N of 4". *Verified in sim.*
- [x] `monospacedDigit()` audit — added to UnitDetail staffed line, KpiTile staffed-beds label,
      EVS overdue banner, For You count, CapacityDemand + Executive surge labels, House
      Capacity occupied/staffed, Staffing gap headcount.
- [x] "DEMO " prefix: lived in `prod.barriers.reason_code` seed rows (no committed seeder
      involved) — stripped via reversible UPDATE on the local dev DB.

## Wave 2 — Android: Material foundation & parity

### W2.1 Theme foundation
- [x] Real `MaterialTheme` wrapper (`ui/theme/HummingbirdTheme.kt`): `darkColorScheme` mapped
      from `Z` tokens, iOS-mirrored typography scale, shape tokens (6/10/14/20/28dp) — applied
      at the app root, so every M3 component (NavigationBar, buttons, fields, sheets) inherits
      the operations-bridge look. *Verified in emulator.*
- [ ] Migrate components/screens to consume `MaterialTheme` tokens directly (they still read `Z`,
      which is visually identical — migration is mechanical follow-up).
- [ ] Compose BOM bump for stable M3 Expressive (`MaterialExpressiveTheme`, motion physics) —
      not attempted this pass; BOM 2025.02 predates it. Follow-up with toolchain risk check.

### W2.2 Platform affordances
- [x] Edge-to-edge via `enableEdgeToEdge()`; Scaffold insets already flow through `inner`
      padding; login uses `safeDrawingPadding` over full-bleed artwork. *Verified in emulator.*
- [x] SplashScreen API (core-splashscreen 1.0.1): branded dark launch, hummingbird mark,
      hands off to the app theme.
- [x] Predictive back enabled (`android:enableOnBackInvokedCallback="true"`).
- [x] Pull-to-refresh (`HbRefreshable` on M3 `PullToRefreshBox`) on For You, Transport, and
      EVS queues — and now every role home (Altitude persona home, House Status, House Brief,
      Staffing, Improvement). *Gesture + spinner verified in emulator on the charge-nurse home.*

### W2.3 Login artwork parity
- [x] Hummingbird photography slideshow in `LoginScreen`: 4 shared artworks (drawable-nodpi),
      9.5s crossfade, iOS-mirrored scrims, translucent 28dp form card, honors animator-scale-0
      (reduce motion), debug-only "Connected to" line. *Verified in emulator.*

### W2.4 Security parity (HIPAA posture, not just polish)
- [x] Tokens moved to Keystore-backed `EncryptedSharedPreferences` (`data/SecurePrefs.kt`) with
      one-time migration from the legacy plain prefs. *Verified: auto-login + authenticated
      session round-trips through the encrypted store.*
- [x] Biometric app lock (`data/AppLock.kt` + `ui/LockScreen.kt`): opt-in Profile → Security
      switch, engages on `onStop`, BiometricPrompt (biometric or device credential), sign-out
      escape. *Builds + honest "set up a screen lock" state on unenrolled devices; enrolled-
      biometric flow needs a device/enrolled-emulator pass.*
- [x] Haptics on primary actions (`ui/components/Haptics.kt`): transport claim + lifecycle
      advance, EVS advance/unable, placement Place/Reject (CONFIRM/REJECT constants,
      pre-R fallbacks).

### W2.5 Branch health (found during the pass)
- [x] Fixed four pre-existing Kotlin compile errors on the branch (the parity commit shipped
      without an Android build): `ExecutiveScreens` composable function references in `?.let`,
      and `Modifier.weight` outside RowScope in `ImprovementScreens`/`StaffingScreens`.
      `:app:assembleDebug` is green again.

## Wave 3 — Stickiness (OS surfaces)

- [x] **Live Activity for transport trips (iOS):** new `HummingbirdWidgets` extension target
      (xcodegen), shared `HBJobActivityAttributes` contract, lock-screen banner + full Dynamic
      Island set (expanded/compact/minimal). Locally driven: `JobActivityController.sync()` on
      every lifecycle tap in JobDetailView (claim → … → handoff → complete ends it); sign-out
      ends all activities. STAT = coral tint, otherwise interaction blue (earned urgency).
      *Verified in sim: compact island shows the trip icon + "En route" while the app is
      backgrounded (via new `SIMCTL_CHILD_HB_DEMO_LIVE_ACTIVITY=1` hook).*
- [x] Live Activity for EVS turns (same scaffold, kind="evs": claim → cleaning → complete,
      isolation turns titled "Isolation bed-turn"). Wired in TurnDetailView.
- [x] **WidgetKit house-glance widget:** `HouseGlanceWidget` (systemSmall) renders occupancy %,
      status word + dot, pending placements, relative "updated" line from the App-Group-cached
      snapshot (`group.net.acumenus.hummingbird`); `HouseCapacityViewModel` writes the cache +
      reloads the timeline on every fresh rollup. *Cache round-trip verified in sim (61% /
      424-692 / 11 pending from prod). Home-screen placement itself needs a manual long-press
      pass (not scriptable). Device deploys: the App ID now needs the App Groups capability.*
- [x] For You count in the widget family: `ForYouWidget` (accessoryCircular /
      accessoryRectangular / systemSmall) — pending + critical counts only, PHI never leaves
      the app; coral only when critical items exist. Writer: `ForYouViewModel.load` →
      `ForYouGlanceCache` + timeline reload. *Cache round-trip verified in sim (64 pending /
      31 critical from prod). Home/lock-screen placement needs the same manual pass.*
- [x] App Intents: `OpenForYouIntent` (routes through the existing push-deeplink seam →
      For You tab) and `HouseStatusIntent` (speaks the cached snapshot — no app launch, no
      network), registered via `HummingbirdShortcuts` for Siri/Shortcuts/Action Button.
      *Builds + registers; spoken-invocation pass is manual (no Siri in scripted sim).*
- [ ] Android Glance widget: house occupancy glance.
- [ ] Finish tiered push: real APNs sends for T1/T2 via existing `PushNotifier` seam (needs .p8
      key in env), Android FCM registration + channels (T1 high, T2 default, T3 low, T4 digest).
- [ ] Start-of-shift moment: 2-second "your shift at a glance" summary after role confirm.
      *Partially landed 2026-07-03 via the Flow Window (FLOW-WINDOW-PLAN Phase 1): the
      charge-nurse Map segment auto-replays the unit from the last 07:00/19:00 shift
      boundary to now on first open (both platforms). The list-home summary variant
      after role confirm is still open.*

## Wave 4 — Accessibility & defensibility

- [x] **Fix the executive data-consistency bug:** `CommandCenterDataService::latestCensusPerUnit()`
      read only `census_snapshots` (empty on fresh/wiped datasets → 0% while House Capacity said
      86%). Now falls back to the live bed board when no snapshots exist, so every surface reads
      the same occupancy. Regression test:
      `MobileBffTest::test_command_house_occupancy_matches_the_live_census_when_snapshots_are_absent`.
      Full mobile suite green (33 passed, 698 assertions). *Verified live: /command/house now
      reports 86% / Surge Level 1.*
- [x] Dynamic Type (iOS), core-components pass: `Z.scaledFont(_:weight:)` in `Theme.swift`
      (UIFontMetrics body-scaled) applied across KpiTile, StatusChip, RetryableMessage.
      *Verified at accessibility-medium in sim — tiles scale, wrap, no clipping.* Screen-level
      text (Home headers, detail rows) is a mechanical follow-up with the same helper.
- [x] Font-scale audit (Android): verified at font_scale 1.3 and 2.0 in the emulator (charge
      nurse home + For You) — one real bug found and fixed: the glance question collided with
      the status chip (no Row spacing; now `spacedBy(12.dp)` on PersonaGlance/WorkspaceHeader).
      At 2.0 everything wraps and scrolls, no clipped controls.
- [x] Accessibility label audit: 5 unlabeled icon-only profile buttons labeled (Capacity,
      Improvement, OR Board, Staffing, Executive — all 11 now "Profile and settings"); For You
      rows now speak their tier ("Critical: <title>"), decorative glyphs/chevrons hidden from
      VoiceOver. Android already clean (IconButtons labeled; decorative icons correctly null
      beside text). StatusChip/KpiTile labels pre-existing.
- [x] Gold focus treatment on iOS text inputs: focused fields ring gold 1.5pt (Login,
      Change Password, Handoff sheet) — the Acumenus focus layer, never status color.
      *Screenshot-verified via new DEBUG hook `HB_FOCUS=1` (focuses username on launch).*
- [ ] Persona screenshot matrix (from PLATFORM-RECONCILIATION-TODO §P7.3) as the regression gate.

---

## Wave 5 — Altitude invisibility (the model is architecture, not UI copy)

**Principle (2026-07-03, user decision):** the Altitude model (A0 glance → A1 workspace →
A2 drill → A2P patient → A3 study) governs *what each surface contains* — one question at a
glance, one primary action, context on demand, provenance behind the drill. It must be
**invisible to end users**: screens speak the worker's language (trips, turns, placements,
"why this?"), never the model's coordinates. A0–A3 vocabulary is allowed only in code, docs,
API field names, and debug tooling.

- [x] iOS: removed the `A0 Glance › A1 Workspace › A2 Drill` breadcrumb from all eight user
      surfaces and deleted the component + `AltitudeLevel` enum (principle documented in
      `AltitudeComponents.swift`). Context card now leads with the role's glance question.
- [x] iOS copy: "Explain trip/turn/placement signal" → "Why this trip/turn/placement?";
      "Cross-persona relay" → "Team activity"; "No relay events" → "No team activity yet";
      Drill screen title → "Details"; "Authorized operational patient context" →
      "Operational patient context". *Verified in sim against prod.*
- [x] Android: "Glance" → "Right now", "Recent/Workspace relay activity" → "Recent team
      activity", "Drill detail" → "Details". Debug Altitude Explorer keeps its name (debug-only).
- [x] Android: raw provenance blobs ("Source Service / Snapshot Version / Generated At…")
      removed from glance tiles — the glance only speaks up with "Data may be stale" when
      trust is actually in question; full provenance stays in the drill. *Verified in emulator.*
- [x] Copy lint follow-up: server-composed For You subtitles no longer leak machine keys —
      `MobileForYouService::humanize()` (acronym-aware: EVS/ED/OR/ICU/RTDC/STAT/PACU) applied
      to barrier reason codes, recommendation types ("blocked_beds · EVS dispatch" →
      "Blocked beds · EVS dispatch · Critical risk"), unit types, isolation, turn types,
      shifts; transport route uses a real arrow (→). *Verified live via tinker; mobile suite
      79/79 green.* Parity-test pins updated to the compilable executive branch + "Details"
      top bar (they pinned the broken/pre-Wave-5 source).
- [x] Added the "no model vocabulary in UI copy" check to the PR checklist in
      PLATFORM-RECONCILIATION-TODO §P8.1 (Altitude/A0–A3, glance/workspace/drill/relay,
      persona ids, snake_case keys).
- [x] Stragglers caught by the checklist itself: Android workspace header subtitle leaked the
      altitude coordinate ("A1 / Charge Nurse / Rtdc" → "Charge Nurse · Rtdc"); iOS
      "Explain approval/staffing/improvement signal" → "Why this approval? / Why this gap? /
      Why this opportunity?" (parity-test pins updated to match).

## Out of scope for this pass (tracked, not forgotten)
- iOS light theme (dark-only is deliberate for v1).
- OpenAPI-generated DTOs / KMP shared module (owned by PLATFORM-RECONCILIATION-TODO P1.4).
- Server-side For You filtering (P5.2) and persona-specific Altitude home (P5.1).

## Session notes (2026-07-03 implementation pass)
- Dev stack runs against the **local Docker Postgres** (`hb_pg` / zephyrus_dev) because the
  shared remote DB was found wiped; remote values live in the `.env` header comment.
- Seeded demo rows for verification, tagged `metadata.seeded = "design-elevation-test"`:
  `prod.transport_requests` id 1 (STAT wheelchair trip) and `prod.evs_requests` id 3
  (isolation turn, overdue). They double as demo data; delete by tag to remove.
- New iOS test hook: `SIMCTL_CHILD_HB_OPEN_PLACEMENT=<n>` (1-based) opens the nth pending
  placement from House Capacity. `SIMCTL_CHILD_HB_FOCUS=1` focuses the login username field
  (for focus-styling screenshots).
- Shared-DB watch item: the `demo` user's password hash was rotated by something outside this
  session (~21:40) — restored to Password123! via targeted UPDATE. If it recurs, find the
  rotator before restoring again.
- Backend: mobile suite green (33 passed / 698 assertions), pint clean on touched files.
- iOS: `xcodegen generate` required after the new DesignSystem files (already run);
  simulator build green. Android: `:app:assembleDebug` green (JBR 17 + ANDROID_HOME recipe).
