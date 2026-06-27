# 03 — Technical Architecture

Grounded in [research/07-mobile-best-practices.md](../research/07-mobile-best-practices.md) and
the platform reality in [research/05-platform-auth-realtime-design.md](../research/05-platform-auth-realtime-design.md).

---

## 1. Guiding decisions

| # | Decision | Choice | Why |
|---|----------|--------|-----|
| D1 | UI | **Native per platform** — Jetpack Compose (Android), SwiftUI (iOS) | Glanceability and platform feel are the product; we want native widgets, Live Activities, Critical Alerts, Watch/Wear |
| D2 | Shared code | **Kotlin Multiplatform (KMP)** domain + data layer, bridged to Swift with **SKIE** | Clinical/status/sync logic is "defensible" — it must not drift between two codebases |
| D3 | Backend access | **Mobile BFF** (`/api/mobile/v1/*`) | The web `/api` is chatty, inconsistently authed, PHI-rich; mobile needs aggregated, shaped, role-scoped responses |
| D4 | Auth | **OAuth2 + PKCE / Sanctum tokens**, additive | Move off session cookies without touching the locked auth flow |
| D5 | Real-time | **Push-first; Reverb WS when foregrounded; poll fallback** | Battery + correctness; never hold a background socket |
| D6 | Offline | **Offline-aware**, not full offline-first | Read caches + queued non-critical writes; block safety-critical writes offline |
| D7 | Design | **One DTCG token source → Compose + SwiftUI + CSS** | Zero drift across three platforms |

> **The KMP boundary is the single most important architectural decision.** Committing to a
> shared Kotlin domain core (with KMMBridge + SKIE) puts Kotlin/Native in the iOS build but
> guarantees one source of truth for status rules, sync, auth, and models. Deciding late
> forces an expensive re-architecture. **Recommendation: commit to KMP for `domain` +
> `data` + `designsystem-tokens` now; keep all UI native.** If the org rejects KMP, the floor
> is two fully-separate apps bound by a mandated **OpenAPI contract** + a shared
> **status-rule/sync conformance test spec** — see [api-contract/](../api-contract/).

---

## 2. The layered model (shared shape, native rendering)

```
┌──────────────────────────────────────────────────────────────────────┐
│  PRESENTATION  (native, per platform)                                  │
│  Android: Compose + ViewModel (MVVM-UDF; MVI for dense screens)        │
│  iOS:     SwiftUI + @Observable ViewModels (TCA only for true machines)│
│  → widgets · Live Activities/Dynamic Island · Watch/Wear complications │
├──────────────────────────────────────────────────────────────────────┤
│  SHARED (KMP)                                                          │
│  ┌── domain ──────────────────────────────────────────────────────┐   │
│  │ models · use-cases · status rules (the "defensible" logic) ·    │   │
│  │ earned-urgency scoring · validation · result/error types        │   │
│  └─────────────────────────────────────────────────────────────────┘  │
│  ┌── data ────────────────────────────────────────────────────────┐   │
│  │ repositories · BFF client (Ktor) · DTO↔domain mappers ·         │   │
│  │ cache (SQLDelight) · outbox (queued writes) · sync engine ·     │   │
│  │ realtime client (Reverb/Pusher) · auth/token store (expect/actual)│ │
│  └─────────────────────────────────────────────────────────────────┘  │
│  ┌── designsystem-tokens ─────────────────────────────────────────┐   │
│  │ generated from DTCG JSON: colors, type, spacing, motion         │   │
│  └─────────────────────────────────────────────────────────────────┘  │
├──────────────────────────────────────────────────────────────────────┤
│  PLATFORM (expect/actual)                                              │
│  Keychain / Keystore+EncryptedSharedPrefs · BiometricPrompt/LocalAuth  │
│  APNs/FCM tokens · BGTasks/WorkManager · secure logging                │
└──────────────────────────────────────────────────────────────────────┘
```

### Android stack
- **UI:** Jetpack Compose, Material3 themed to Zephyrus tokens (not default Material).
  Navigation-Compose. MVVM with unidirectional data flow; MVI for the dense boards.
- **DI:** Hilt. **Async:** Coroutines/Flow. **HTTP:** Ktor (shared) over OkHttp engine with
  cert pinning. **Local:** SQLDelight (shared cache), DataStore (prefs).
- **Background:** WorkManager (delta sync on silent push, outbox flush). **Push:** FCM.
- **At-a-glance:** Glance app widgets; Wear OS tiles/complications (phased).

### iOS stack
- **UI:** SwiftUI, `@Observable` (Observation) view models; **The Composable Architecture
  (TCA)** only where a screen is a genuine state machine (e.g., the transport trip runner).
- **Async:** Swift Concurrency (async/await, actors). **HTTP:** Ktor client via shared module
  (or URLSession in a thin actual) with cert pinning. **Local:** SQLDelight via shared;
  Keychain for secrets.
- **Background:** BGTaskScheduler (BGAppRefresh + BGProcessing). **Push:** APNs (incl.
  Critical Alerts entitlement for the true-breach tier; Live Activities via ActivityKit).
- **At-a-glance:** WidgetKit widgets, Live Activities + Dynamic Island, watchOS
  complications (phased).

### SKIE
Generates idiomatic Swift APIs over the KMP module (sealed classes → Swift enums, suspend →
async/await, Flow → AsyncSequence) so the iOS team consumes the shared core naturally.

---

## 3. Data flow & sync (offline-aware)

### Reads — cache-then-network
1. UI observes a repository `Flow`/`AsyncSequence`.
2. Repository emits **cached** (SQLDelight) immediately, tagged with an **as-of timestamp**.
3. Repository fetches the BFF; on success updates cache → re-emits fresh.
4. On failure, UI keeps cached data and shows a **stale badge** (never a blank screen).

### Writes — outbox with explicit criticality
- **Non-critical writes** (acknowledge, claim a routine job, advance a routine status) →
  **optimistic** local apply + enqueue in the **outbox**; WorkManager/BGTask flushes when
  connectivity returns; reconcile on server response.
- **Safety-critical writes** (approve an operational action, place a bed, complete an
  isolation bed-turn, advance a case past a clinical gate) → **require connectivity**; if
  offline, the action is **disabled with an explicit reason** rather than silently queued.
  We never let a clinically-consequential decision sit invisibly in a queue.
- **Conflict policy:** last-write-wins with **field-level merge** and a server **version/ETag**;
  on 409 the UI surfaces "changed since you loaded — review" rather than blindly overwriting.
  No CRDTs (overkill for this domain).

### Real-time — the three-mode model
The web uses **Laravel Reverb** websockets (`CensusUpdated`, `HuddleUpdated`,
`BedMeetingUpdated`) on **public, PHI-free** channels. Reverb does **not replay** missed
frames, so the web re-snapshots on reconnect. Mobile mirrors and extends this:

| App state | Transport | Behavior |
|-----------|-----------|----------|
| **Foreground, screen on** | **Reverb WS** (native Pusher protocol client) | Live tiles; **re-snapshot all visible queries on (re)connect** (no replay) |
| **Background / locked** | **Push (APNs/FCM)** | Silent (`content-available`) push → WorkManager/BGTask **delta sync**; user-facing push for NOTIFY-tier events |
| **No connectivity** | **Poll on resume** + cached | Show stale badge; reconcile outbox |

We **never hold a websocket in the background** (battery, OS suspension). Public WS channels
need no auth today; any future PHI-on-wire requires `PrivateChannel` + token channel auth,
which depends on the auth work in [04](04-backend-requirements.md).

---

## 4. Module structure (KMP + native)

Recommended **two-repo** layout (mobile apps live outside the Laravel repo; shared
**contracts** + **tokens** are versioned and published as artifacts the apps consume):

```
hummingbird/                          # new mobile repo
├─ shared/                            # KMP
│  ├─ domain/                         # models, use-cases, status rules, urgency scoring
│  ├─ data/                           # repos, Ktor BFF client, SQLDelight, outbox, sync, realtime
│  ├─ designsystem-tokens/            # generated from DTCG (see /docs/hummingbird/design-tokens)
│  └─ platform/                       # expect/actual: secure store, biometrics, push tokens, bg
├─ androidApp/                        # Compose UI, Hilt, Glance widgets, Wear module
│  ├─ feature-foryou/ feature-rtdc/ feature-or/ feature-ops/ feature-transport/ feature-evs/
│  └─ core-ui/ (token-themed Compose components: KpiTile, StatusChip, Panel…)
├─ iosApp/                            # SwiftUI, widgets, Live Activities, watchOS
│  ├─ Features/ (ForYou, RTDC, OR, Ops, Transport, EVS)
│  └─ DesignSystem/ (token-driven SwiftUI components)
└─ build-logic/ (convention plugins), fastlane/, ci/

zephyrus/ (this repo)
└─ docs/hummingbird/
   ├─ design-tokens/    # the source of truth for tokens (DTCG) + Style Dictionary build
   └─ api-contract/     # hummingbird-bff.v1.yaml (OpenAPI) — generates Ktor client + Laravel stubs
```

**Feature module pattern (both platforms):** each domain feature is a vertical slice
(screens + view models) depending only on `shared/domain` + `shared/data` + `core-ui`. The
**For-You** feature composes items from every domain repository through a single
`ForYouFeed` use-case in `shared/domain` — the one place the earned-urgency ranking lives.

---

## 5. Design-system parity pipeline

One source, three platforms, zero drift. Implemented now under
[design-tokens/](../design-tokens/):

```
tokens.json (DTCG)  ──Style Dictionary──┬──▶ Compose: Color.kt / Type.kt / Spacing.kt
 (colors, type,                          ├──▶ SwiftUI: Colors.swift / Typography.swift
  spacing, motion,                       └──▶ web: verify against tailwind.config.js / design.json
  status ramp)
```

The tokens encode the **Two-System Rule** (operational blue/slate vs. brand crimson/gold),
the **rationed status ramp** (teal `#2DD4BF` / amber `#E5A84B` / coral `#E85A6B` / sky
`#60A5FA` dark; light variants), **Figtree weights 400/500/600 only**, dark-default, and the
4px spacing grid — exactly matching `.impeccable/design.json` and `tailwind.config.js`. A CI
check diffs generated values against the web config so the three platforms can't silently
diverge. Native components (`KpiTile` with its 3px status stripe + arrow + label, `StatusChip`
with dot-not-color-alone, `Panel` with the quiet-lift) are built **once per platform** from
these tokens to match the web signatures.

---

## 6. Cross-cutting concerns

| Concern | Approach |
|---------|----------|
| **Auth/session** | Tokens in Keychain/Keystore, gated by biometrics; short-lived access + rotating refresh; idle auto-lock; AppAuth + system browser for OIDC. → [04](04-backend-requirements.md), [06](06-security-hipaa.md) |
| **Error/empty/stale states** | Every screen has explicit loading / empty / error / **stale** states; never a silent blank. Staleness is first-class (the "defensible" principle). |
| **Accessibility** | Dynamic Type / font scaling, VoiceOver/TalkBack labels on every status, 44pt targets, status-never-by-color, `prefers-reduced-motion` honored. WCAG 2.2 AA pragmatic. |
| **Observability** | Crash + non-PHI analytics (a BAA-covered SDK only); structured client logs with a **hard PHI filter**; feature flags for staged rollout. |
| **Theming** | Dark default; light fully supported; both from the same tokens. |
| **Testing** | Shared: unit tests for status rules + sync + urgency scoring (the conformance spec). Native: UI/snapshot tests for token-themed components; instrumented flows for the critical journeys (claim trip, approve action, place bed). |
| **CI/CD** | build-logic convention plugins; Gradle + Fastlane; KMMBridge publishes the shared XCFramework; per-platform store pipelines; contract tests against the BFF OpenAPI. |

---

## 7. What this architecture deliberately avoids

- **No shrunk-desktop port.** Feature modules implement the *glance-and-act* slices only;
  analytics deep-link out.
- **No background websocket** (battery/OS suspension) — push-triggered delta sync instead.
- **No PHI on public WS channels or in notifications** — mirrors the web's PHI-free broadcast
  posture and the HIPAA rules in [06](06-security-hipaa.md).
- **No divergent clinical logic** — status transitions, SLA timers, and urgency scoring live
  once in `shared/domain` and are conformance-tested.
- **No GraphQL** for v1 — a small, versioned REST BFF is simpler to secure, cache, and shape
  for these screens.
