# 08 — Hummingbird: The Real-Time Mobile Companion for Frontline Staff

**Dossier section for Zephyrus** — the real-time hospital demand/capacity platform.
Scope: the design of **Hummingbird**, the iOS + Android companion app (committed stack:
**Expo / React Native + TypeScript**) that puts Zephyrus's live census, bed board, task
queues, and capacity intelligence in the pocket of every charge nurse, supervisor, bed
manager, hospitalist, case manager, transporter, and EVS tech. Hummingbird must be
**constantly updated, interactive, and in lockstep with every aspect of the web platform**.
This section establishes the workflows, the competitive lessons, the sync and alerting
architecture, the offline and HIPAA posture, the Expo build/code-sharing strategy, and the
v1 MVP.

The guiding thesis from the research: **the web platform is the command center; Hummingbird
is the floor-facing, role-filtered window into it.** The phone is not a shrunken dashboard —
it is a glanceable, action-first surface optimized for the minute-to-minute decisions made
while walking a unit, often one-handed, sometimes gloved, frequently in a dead zone.

---

## 1. Frontline mobile workflows — what each role acts on, minute to minute

Across all roles the unifying pattern is the **capacity/command-center model**: a single
shared data plane — bed availability, acuity, pending discharges, staffing, EVS status — fed
by EHR/ADT and housekeeping over HL7, updating the instant reality changes on the floor.
Command centers have added the equivalent of 60+ beds with no construction by acting on this
data faster ([Sickbay][s1], [HFM][s2]). Hummingbird's job is to deliver each role a
**filtered slice** of that plane plus a thumb-zone action queue.

| Role | Real-time data they act on | Minute-to-minute decisions | Home-screen tiles |
|------|----------------------------|----------------------------|-------------------|
| **Charge nurse** | Unit census, open/dirty/pending beds, per-nurse acuity load, incoming admits, pending discharges | Assign patients by acuity, rebalance nurse load, accept/hold admits, escalate to supervisor ([Catalyst][s3], [AACN acuity][s4]) | Census + bed states; per-nurse acuity bars with imbalance flag; incoming/outgoing; one-tap escalate |
| **Nursing/house supervisor** | House-wide bed grid across units/facilities, units at/over capacity, staffing gaps, surge/diversion alerts | Allocate beds across units, fill staffing gaps, run the "2 a.m." escalation path ([Medical Solutions][s5]) | House bed grid; units over capacity; staffing-gap-by-unit; pending placements; surge alerts |
| **Bed manager / flow coordinator** | Live bed board (EHR/ADT + EVS + RTLS), placement queue, bed-clean status, predicted discharges | Match pending placements to beds, sequence turnover, clear non-clinical bottlenecks (imaging, consults, transport) ([Mapsted][s6], [WellSky][s7]) | Color+icon bed board; placement queue; ADT feed; EVS-clean status |
| **Hospitalist** | Dynamic rounding list reflecting live ADT, discharge-ready flags, new consults/admits | Sequence rounds (discharges first), confirm dispositions, answer consults ([Medaptus][s8], [Today's Hospitalist][s9]) | Prioritized rounding list; discharge-ready flags; new consults; pending tasks |
| **Case manager / discharge planner** | Per-patient discharge barriers (placement, auth, transport, imaging), EDD vs LOS, today's dischargeables | Clear same-day barriers, push placement/auth, flag destination early — cuts LOS 0.5–1 day ([PMC barriers][s10], [WellSky][s7]) | Barrier list per patient; EDD-vs-LOS; today's dischargeables |
| **Patient transport** | Assigned/queued trips with priority, pickup/destination, status | Mark en route / in progress / complete from the phone, no pager ([TeleTracking mobileXT][s11], [PMC transport][s12]) | Job queue by priority; big status buttons |
| **EVS** | Dirty-bed queue by priority/STAT, in-progress cleans, bed-ready confirmation | Claim a clean, mark in-progress, confirm bed-ready back to flow ([TruAsset][s13], [HFT][s14]) | Dirty-bed queue (STAT first); in-progress; mark-clean → bed-ready |

**Design implications for Hummingbird.** Build **role-based home screens**, not one menu.
Each role gets a top-of-screen status summary (the glanceable "is my unit on fire?" answer)
and an action queue in the bottom-third thumb zone. The first three roles to ship — **charge
nurse, bed manager, EVS/transport** — because they are the densest in real-time, low-data-
entry, queue-and-acknowledge interactions that a phone serves better than a desktop.

---

## 2. Comparable clinical mobile apps — what to copy, what to avoid

| App | What it does well | What it does poorly (lessons) |
|-----|-------------------|-------------------------------|
| **Epic Rover / Haiku / Canto** | Fast, focused, read-mostly chart/task views clinicians love when they stay thin ([Rover][e1], [Haiku][e2]) | App-store ratings sag (Haiku 2.5, Canto 1.9) on **feature gaps vs desktop** and, critically, **lost push notifications when locked** and **failed critical-alert audio on Apple Watch** ([Rover reviews][e3]) |
| **TigerConnect** | **Role-based routing** — search by clinical role, not name; dynamic on-call teams; priority + auto-escalation ([TigerConnect][t1]) | End-user app ~2.8/5: **missed/duplicate push for urgent messages**, forced logouts, alerts arriving during "do not disturb" ([reviews][t2]) |
| **Vocera / Voalte (Stryker)** | Hands-free voice badge (no-hands call for help); Engage middleware routes nurse-call/alarms with context to fight alarm fatigue ([Stryker][v1]) | Apps rate 2.1–2.6/5: **battery drain, ~10-min message lag, notifications not arriving until the app is opened** ([Voalte One][v2]) |
| **PerfectServe** | Best-in-KLAS **Dynamic Intelligent Routing** by time-of-day + on-call; scheduling integration ([PerfectServe][p1], [KLAS 2025][p2]) | App freezes, **no notification sound, duplicate push+text, alerts that keep firing after being read, binary all-or-none availability** ([reviews][p3]) |
| **TeleTracking** (capacity mobile) | The most mature **role-specific** mobile: distinct Charge Nurse, Bedside Nurse, Patient-Flow, transport, and EVS views; 2024 Best in KLAS ([TeleTracking][c1]) | Operational-command category is otherwise dashboard-first; standalone frontline mobile is rare — a differentiation opening |
| **Qventus / GE Command Center / LeanTaaS iQueue** | Powerful predictive "mission control" dashboards (GE cut bed-request-to-assignment 66%; iQueue scores 95/100 KLAS) ([GE/Duke][c2], [iQueue][c3]) | **Dashboard/EHR-embedded, thin mobile story** — they prove the analytics value but leave the phone experience open |

**The make-or-break lesson, repeated across every vendor: notification reliability.** Epic,
TigerConnect, Voalte, and PerfectServe are *all* dragged down by missed, delayed, duplicated,
or silent push. Secondary themes: **login friction kills adoption** (SSO/badge-tap redirected
3.3M clinician-hours/year in one 55-hospital study, [Imprivata][u1]); **role-based home
screens win** ([KLAS Arch Collaborative][u2]); **speed and reliability beat feature breadth**;
and **"read ≠ actioned"** — time-sensitive items need closed-loop acknowledgment with
automatic escalation ([JMIR][u3]).

**Design implications for Hummingbird.** Treat push delivery + closed-loop acknowledgment as
a **safety-grade feature with its own SLO and instrumentation**, not a convenience. Adopt
**role/team-based routing** (à la TigerConnect/PerfectServe) but avoid their **all-or-none
availability** — give tiered, schedule-aware quiet hours. Differentiate on the gap nobody
fills well: a **genuinely role-tailored, real-time frontline capacity experience on the
phone** (TeleTracking-style breadth, with reliability the incumbents lack).

---

## 3. Real-time sync architecture — keeping the phone in lockstep with the web

Zephyrus's web tier already broadcasts live events via **Laravel Reverb** (first-party
WebSocket server speaking the **Pusher protocol**, drop-in compatible with Echo/pusher-js,
supporting public/private/presence channels authorized in `routes/channels.php`
([Reverb][r1], [Broadcasting][r2])). Hummingbird should **ride the same Reverb event bus** —
one broadcast feeds both web and mobile.

**Transport choice.** Polling is simplest but trades latency for load; **WebSocket/Reverb**
gives sub-second push; **FHIR R5 / R4-Backport topic Subscriptions** are the standards-
compliant interop path, and notably the **websocket channel needs no client endpoint and is
explicitly recommended for mobile** ([FHIR Backport channels][r3]). **Recommendation:** use
**Reverb as the live transport now**, and later expose the same events through a FHIR
Subscription topic *façade* for EHR interop — keep Reverb as the engine.

**RN client gotchas (load-bearing).** pusher-js assumes a browser: shim `global.Pusher`
before instantiating Echo, restrict `enabledTransports: ['ws','wss']`, and pass the
Sanctum/JWT Bearer via `auth.headers` (RN has no session cookie) ([Devio][r4]). The native
`@pusher/pusher-websocket-react-native` TurboModule is more robust but needs a dev-client
(not Expo Go). **Most important:** the OS suspends JS-thread WebSockets in the background, so
**events broadcast while backgrounded are lost** — detect foreground via RN `AppState` and
trigger catch-up.

**Optimistic UI** via TanStack Query (already the house stack): `onMutate` →
`cancelQueries` (mandatory, or an in-flight refetch clobbers the optimistic write), snapshot
for rollback, `setQueryData`; `onError` restores; `onSettled` → `invalidateQueries`
([TanStack][r5]).

**Conflict resolution.** A bed board is mostly **independent status cells**, so default to
**field-level last-write-wins with a version/`updated_at` column and optimistic-concurrency
rejection** (reject stale base version → refetch). Reserve **CRDTs (Yjs)** strictly for
genuinely co-edited free text like a shared handoff note ([Yjs][r6]) — don't pay CRDT cost
for a grid of independent cells.

**Presence** ("who's viewing/editing this board") via Reverb **presence channels**.

**Reliable delivery — the critical fact:** Pusher/Reverb auto-reconnect and auto-resubscribe
but **do not replay messages missed while disconnected** ([Pusher protocol][r7]). The
required pattern is **snapshot-on-reconnect**: on every `AppState→active` and Echo
`connected`, run `invalidateQueries(['census'])` to pull a fresh REST snapshot, sidestepping
per-message sequencing. If snapshots get heavy, attach a monotonic sequence ID to broadcasts
and add a `GET /events?after_id=` catch-up endpoint, de-duping live + backfill.

**Design implications for Hummingbird.** Reverb (wss, `REVERB_SCALING_ENABLED` + Redis,
`ext-uv`, tuned `ulimit`) as transport; **TanStack Query as the single cache**; optimistic
mutations with rollback; **snapshot-on-reconnect wired to `AppState` + Echo `connected`** to
absorb missed-while-backgrounded events; field-level LWW + version column for the board;
presence channels for editor rosters.

---

## 4. Push notifications & alerting — the safety-grade tier

**Expo Push** registers an `ExponentPushToken[…]` via `getExpoPushTokenAsync`, batches ≤100
to `exp.host/--/api/v2/push/send`, fans out to **APNs / FCM V1**, and returns a **ticket**
(Expo received it) then a **receipt** ~15 min later (APNs/FCM accepted it) — and **even `ok`
receipts do not guarantee device delivery** ([Expo sending][n1]). Use `priority: 'high'` for
clinical alerts. **Actionable notifications** register `setNotificationCategoryAsync`
(buttons like "Acknowledge" with `opensAppToForeground: false`), captured via
`useLastNotificationResponse()` (more reliable than the listener for cold start) ([Expo
SDK][n2]).

**iOS Critical Alerts** need the `com.apple.developer.usernotifications.critical-alerts`
entitlement — **not self-service**; you justify it to Apple for health/safety use ([Apple
entitlement][n3], [request flow][n4]). Once granted they **bypass Do Not Disturb / Focus and
the mute switch** with independent volume — achievable in Expo via a config plugin + EAS dev
build. **Android:** notification **channels** with `IMPORTANCE_HIGH` for heads-up + sound
(channel settings are immutable — version the channel ID); **Full-Screen Intent** is
restricted on Android 14+ to calling/alarm apps, so plan on `IMPORTANCE_HIGH` as the
realistic ceiling ([Android 14][n5]).

**Alarm fatigue is a patient-safety constraint, not a UX nicety:** 80–99% of monitor alarms
are false/insignificant; it's a Joint Commission **NPSG.06.01.01** ([AHRQ][n6], [Joint
Commission][n7]). **Tier the alerts:**

- **Tier 1 (life-critical, actionable):** iOS Critical Alert + Android `IMPORTANCE_HIGH`/FSI,
  background "Acknowledge" action, **escalation chain** if unacked in N seconds.
- **Tier 2 (urgent ops):** high-priority push, respects quiet hours, escalates on no-ack.
- **Tier 3 (informational):** default channel, batched, silent during quiet hours.

Over-using bypass-DND tiers **recreates the fatigue the standards warn against** — reserve
Tier 1 strictly. Escalation delays (e.g. +15s before notifying) cut alarms >80%.

**Design implications for Hummingbird.** Expo Push high-priority + a **3-tier model**;
Tier-1 obtains the iOS Critical-Alerts entitlement; closed-loop **Acknowledge** actions with
**schedule-aware escalation chains** and per-role **quiet hours** (avoiding PerfectServe's
all-or-none trap). Instrument ticket→receipt→ack as an explicit delivery SLO.

---

## 5. Offline & poor-connectivity — surviving the dead zone

Hospitals have flaky Wi-Fi handoffs and physical dead zones, so a binary online flag is
insufficient. The mental model is **Cache → Queue → Database → Sync**: every write is a
persisted **outbox** item, replayed on reconnect — and the outbox belongs in **SQLite**, not
AsyncStorage, which has no transactional guarantees ([SQLite queue][o1]).

**TanStack Query gives offline natively** ([offline example][o2], [RN offline-first][o3]):
`PersistQueryClientProvider` + an MMKV/AsyncStorage persister hydrates cache on launch;
register `setMutationDefaults(['key'], { mutationFn })` at module level so paused mutations
find their `mutationFn` after rehydration; set `networkMode: 'offlineFirst'`; on reconnect
`resumePausedMutations()` then `invalidateQueries()`. **Bridge NetInfo into TanStack** —
mobile doesn't auto-detect online state: `NetInfo.addEventListener(s =>
onlineManager.setOnline(!!s.isConnected))`. **Pin a known-good TanStack version** — 4.24–4.32
had a resume-on-reconnect regression ([GH #5847][o4]) — and integration-test replay.

**Reachability must hit *our* endpoint, not generic internet** — distinguish `isConnected`
from `isInternetReachable` and set a custom `reachabilityUrl` against Zephyrus's `/health`,
because hospital Wi-Fi happily reports "connected" with no route to the API ([NetInfo][o5]).

**Capability matrix:**
- **Offline (read + queue):** read census / bed board / task list from SQLite; acknowledge
  tasks; queue actions (mark clean, mark complete, page request) with clear "pending sync"
  badges.
- **Online-only:** live collaborative edits to the shared board — gate behind connectivity
  with optimistic local echo + server reconciliation; the outbox is for **single-owner**
  actions only.

**Design implications for Hummingbird.** `PersistQueryClientProvider` + MMKV persister +
**SQLite outbox** with `UNIQUE` idempotency keys; NetInfo→`onlineManager`;
`resumePausedMutations` on reconnect; reachability tested against `/health`; **read +
acknowledge + queue offline, shared-board live edits online-only.**

---

## 6. Secure messaging & HIPAA on mobile

The Security Rule's technical safeguards (**45 CFR §164.312**) map directly to mobile
controls — Access Control incl. **Automatic Logoff §164.312(a)(2)(iii)** and **Encryption
§164.312(a)(2)(iv)**, **Audit Controls §164.312(b)**, **Integrity (c)**, **Authentication
(d)**, **Transmission Security (e)** ([§164.312][h1], [eCFR][h2]). "Addressable" ≠ optional —
implement or document a compensating control; regulators expect encryption.

- **Encryption:** AES-256 at rest (encrypted SQLite/SQLCipher, encrypted MMKV; keys in
  Keychain/Keystore via **expo-secure-store**, tokens-only ≤2 KB, not bulk PHI); **TLS 1.2+
  with cert pinning** in transit ([HIPAA Journal][h3], [SecureStore][h4]). Encryption also
  triggers breach **Safe Harbor** on a lost device.
- **No SMS/iMessage:** they lack encryption, access controls, and audit trails — build
  messaging **in-app over the authenticated TLS channel** ([HIPAA Vault][h5]).
- **Biometric gate, not authenticate:** **expo-local-authentication** unlocks short-lived
  tokens after backend auth; **10–15 min automatic logoff** clears in-memory PHI and forces
  re-auth ([Expo local-auth][h6]).
- **PHI off the wire and the glass:** **never put PHI in push payloads** (they cross APNs/FCM
  with no BAA and render on the lock screen) — send a content-free signal, fetch-on-open over
  TLS ([Pushwoosh][h7]); Android **`FLAG_SECURE`** + **blur the iOS app-switcher snapshot**;
  scrub PHI from logs/crash traces/clipboard; **never ship PHI in an OTA bundle** ([Accountable
  HQ][h8]).
- **Audit on device:** log PHI access/views/edits (user, timestamp, record ID), buffer
  locally, **flush to the server audit log on sync** (rides the same outbox).
- **MDM vs BYOD:** prefer MDM-managed devices with remote wipe; for BYOD use app-level
  containerization, encrypted storage, and **remote token revocation** so a lost device's PHI
  is unreachable. There is **no HIPAA "certification" for Expo/EAS** — it's architecture +
  signed **BAAs** with every PHI-touching vendor.

**Design implications for Hummingbird.** Biometric-gated short-lived tokens in secure-store;
10–15 min auto-logoff; AES-256 encrypted local store; TLS + pinning; **PHI-free push +
fetch-on-open**; `FLAG_SECURE` + app-switcher blur; local audit buffer flushed on sync;
MDM-preferred with BYOD token revocation.

---

## 7. Expo / React Native production & code-sharing with Zephyrus web

**EAS Build + EAS Update (OTA).** The app splits into a **native layer baked into the binary**
and a **swappable JS/asset update layer**. **OTA-able:** JS, styling, images. **Requires a
new build + store submission:** any native module, config-plugin change, or SDK upgrade —
because new JS expecting absent native APIs crashes ([EAS Update intro][x1]). Use the
**`fingerprint` runtime-version policy** so a build only accepts compatible updates,
mechanically preventing a bad OTA ([runtime versions][x2]); `eas update:rollback` for
recovery ([rollbacks][x3]). Channels (`production`/`preview`) point to branches.

**Push credentials** via EAS: iOS **APNs .p8 key**, Android **FCM V1 service-account JSON**
(legacy keys retired) ([FCM creds][x4]). **Secure storage** = expo-secure-store
(Keychain/Keystore, `WHEN_UNLOCKED_THIS_DEVICE_ONLY`, optional `requireAuthentication`).
**Deep linking** via Expo Router: put a `url` in the notification `data`, read it in the root
`_layout` (`getLastNotificationResponse()` for cold start) and `router.push(data.url)` to land
on `/bed/[id]` or `/patient/[id]` ([Notifications][x5]). **Role-based routing** via Expo
Router `(auth)`/`(app)` groups and **`<Stack.Protected guard>`** driven by a persisted
`useSession()` ([Expo auth][x6]).

**Code-sharing — the heart of "in lockstep."** Restructure into a monorepo (**pnpm
workspaces + Turborepo**; set **`nodeLinker: hoisted`** for RN's flat-`node_modules`
expectation; run EAS CLI from `apps/mobile`) ([Expo monorepos][x7]):

```
repo/
├─ apps/
│  ├─ web/      # React 19 + Vite + TS (existing Zephyrus web)
│  └─ mobile/   # Expo + Expo Router (Hummingbird)
├─ packages/
│  └─ core/     # SHARED: TS types, Zod schemas, fetch API client,
│               #   TanStack Query hooks, Zustand stores, business logic
```

**Share (platform-agnostic):** TypeScript types, **Zod schemas (single validation truth for
both apps)**, the API client, TanStack Query hooks, Zustand stores, business logic — TanStack
Query is RN-compatible and Zustand/Zod are pure JS ([TanStack RN][x8]). **Do not share** the
UI/routing layer: web renders DOM/Tailwind via React Router; mobile renders `View`/`Text`/
NativeWind via Expo Router. Use Metro **platform extensions** (`.web.ts` / `.native.ts`) for
internal divergence. **Net rule: data + logic + validation are shared; pixels and navigation
are per-platform.** This is exactly what keeps Hummingbird and Zephyrus web validating the
same payloads against the same Zod schemas — change a schema once, both apps update.

**Performance:** New Architecture is default since RN 0.76 (Hermes, JSI); for a census of
hundreds of rows use **FlashList**, not FlatList (Shopify measures up to 10× JS-thread FPS)
([FlashList][x9]).

**Design implications for Hummingbird.** pnpm + Turborepo monorepo with a `packages/core`
consumed by both web and mobile; **Zod schemas + API client + TanStack hooks shared**;
fingerprint runtime versions + EAS Update for constant updates; FlashList for the census.

---

## 8. Interaction & notification UX — glanceable, gloved, one-handed, in glare

- **Three-second rule:** a clinician should read danger within three seconds — progressive
  disclosure (critical first, detail on tap), redundant status encoding (**color + icon +
  label**, never color alone) ([Aufait UX][i1]).
- **Haptics (expo-haptics):** `notificationAsync` (success/warning/error) on every state
  change — a **glove-safe confirmation channel** — but never the *only* channel (iOS disables
  the Taptic Engine in Low Power Mode) ([Expo Haptics][i2]).
- **Widgets vs Live Activities:** standard WidgetKit widgets are timeline-throttled and **not
  real-time**; use **Live Activities** (Lock Screen + Dynamic Island, `activity.update()`)
  for a live census / surge / in-progress transport trip ([Velotio][i3]).
- **Apple Watch:** wrist alerting gave nurses a median 118% faster alarm response — but ack
  latency is a risk; scope the Watch to **one-tap acknowledge/escalate**, not data entry
  ([PMC wearable][i4]).
- **Accessibility:** WCAG 2.2 AA (4.5:1 contrast), honor Dynamic Type, VoiceOver/TalkBack
  labels ([BounDev][i5]).
- **One-handed / gloved:** ~75% of touches are the thumb — primary actions in the **bottom
  third**; **≥48 px targets** (beyond the 44 pt minimum) because gloved capacitive taps are
  error-prone; avoid tiny sliders/long-press, prefer large buttons + swipes ([Parachute][i6],
  [gloved-hand][i7]).
- **Bright-light readability:** high-contrast bold typography, large fonts, bold status colors
  for 500-nit glare ([Teguar][i8]).

**Design implications for Hummingbird.** Glanceable role tiles passing the three-second test;
redundant status encoding for bed states; `notificationAsync` haptic on every action;
**Live Activity** for live census/surge and the transporter's active trip; Watch limited to
acknowledge/escalate; ≥48 px bottom-third targets, large fonts, high contrast.

---

## 9. Recommendation summary — the Hummingbird v1

**Sync architecture (recommended):** **Laravel Reverb / WebSocket** as the shared live
transport (one broadcast feeds web + mobile) + **Expo Push** (3-tier alerting, Tier-1 Critical
Alerts) + **TanStack Query offline cache** (PersistQueryClient + MMKV + SQLite outbox) with
**snapshot-on-reconnect**. Field-level LWW + version column for the board; presence channels
for editor rosters.

**Code-sharing strategy:** pnpm + Turborepo monorepo; `packages/core` (types, **Zod schemas**,
API client, TanStack hooks, Zustand stores) shared by `apps/web` and `apps/mobile`; UI and
routing per-platform; fingerprint runtime versions + EAS Update for constant OTA delivery.

**Roles to build first:** **(1) Charge nurse, (2) Bed manager / flow coordinator, (3) EVS +
Transport** — densest in real-time, low-data-entry, queue-and-acknowledge interactions.

**MVP feature set for v1:**
1. Biometric login over short-lived tokens; SSO/badge-friendly; 10–15 min auto-logoff.
2. Three role-based home screens (charge nurse, bed manager, EVS/transport) — glanceable
   status summary + thumb-zone action queue.
3. Live census + color/icon bed board over Reverb with snapshot-on-reconnect.
4. Offline read of census/board/tasks + queued **acknowledge / mark-clean / mark-complete**
   via SQLite outbox.
5. 3-tier Expo push with closed-loop **Acknowledge** actions and escalation chains; PHI-free
   payloads, fetch-on-open.
6. Presence ("who's on this board") + optimistic single-owner status updates.
7. HIPAA posture: AES-256 local store, TLS + pinning, `FLAG_SECURE` + app-switcher blur,
   local audit buffer flushed on sync.
8. Live Activity for live census/surge; haptic confirmation on every action.

**Defer to v2+:** hospitalist rounding list, case-manager barrier board, in-app secure
messaging, Apple Watch acknowledge app, home-screen widgets, FHIR Subscription interop façade.

---

## Sources

**Frontline workflows**
[s1]: https://sickbay.com/virtual-command-centers-improve-hospital-ops-and-patient-care/
[s2]: https://www.hfmmagazine.com/articles/3262-hospitals-capacity-command-center-integrates-patient-flow-operations
[s3]: https://catalystlearning.com/2026/06/03/what-it-takes-to-be-a-charge-nurse/
[s4]: https://www.aacn.org/nursing-excellence/nurse-stories/acuity-based-staffing
[s5]: https://www.medicalsolutions.com/blog/client/nurse-staffing-standards-2026-what-the-new-joint-commission-goal-means-for-hospital-leaders/
[s6]: https://mapsted.com/blog/why-bed-tracking-is-critical-in-hospitals
[s7]: https://wellsky.com/blog/where-hospital-throughput-breaks-down-insights-from-case-management-leaders/
[s8]: https://www.medaptus.com/automated-patient-assignments/
[s9]: https://todayshospitalist.com/new-app-shares-days-rounding-queue-families-nurses/
[s10]: https://pmc.ncbi.nlm.nih.gov/articles/PMC12166983/
[s11]: https://www.teletracking.com/healthcare-operations-iq-platform/throughput/
[s12]: https://pmc.ncbi.nlm.nih.gov/articles/PMC11207585/
[s13]: https://truasset.com/customer/hospital-housekeeping-software/
[s14]: https://www.healthcarefacilitiestoday.com/posts/Improving-Bed-Turnover--25713

**Comparable apps**
[e1]: https://www.healthcareitleaders.com/blog/what-is-epic-rover/
[e2]: https://apps.apple.com/us/app/epic-haiku-limerick/id348308661
[e3]: https://apps.apple.com/us/app/epic-rover/id583359867
[t1]: https://tigerconnect.com/products/clinical-collaboration-platform/
[t2]: https://www.g2.com/products/tigerconnect-clinical-collaboration-platform/reviews
[v1]: https://www.stryker.com/us/en/smart-care/products/vocera-smartbadge.html
[v2]: https://apps.apple.com/us/app/voalte-one/id1020874743
[p1]: https://www.perfectserve.com/why-perfectserve/platform-overview/
[p2]: https://www.perfectserve.com/news/klas-2025-best-physician-scheduling-and-clinical-communication/
[p3]: https://www.g2.com/products/perfectserve/reviews
[c1]: https://www.teletracking.com/news/teletracking-technologies-named-2024-best-in-klas-for-patient-flow/
[c2]: https://hitconsultant.net/2024/10/17/duke-health-to-deploy-ge-healthcares-hospital-pulse-tile-for-command-center/
[c3]: https://leantaas.com/press-releases/klas-research-reveals-outstanding-95-out-of-100-overall-satisfaction-score-for-the-leantaas-iqueue-for-inpatient-flow-solution/
[u1]: https://www.imprivata.com/company/press/eliminate-login-nightmares-single-sign-technology
[u2]: https://klasresearch.com/archcollaborative/report/clinician-ehr-experience-2025/649
[u3]: https://medinform.jmir.org/2025/1/e66859

**Real-time sync**
[r1]: https://laravel.com/docs/12.x/reverb
[r2]: https://laravel.com/docs/12.x/broadcasting
[r3]: https://build.fhir.org/ig/HL7/fhir-subscription-backport-ig/channels.html
[r4]: https://medium.com/@Devio__/laravel-echo-pusher-and-react-native-f8c089eaa67b
[r5]: https://tanstack.com/query/v5/docs/react/guides/optimistic-updates
[r6]: https://github.com/yjs/yjs
[r7]: https://pusher.com/docs/channels/library_auth_reference/pusher-websockets-protocol/

**Push & alerting**
[n1]: https://docs.expo.dev/push-notifications/sending-notifications/
[n2]: https://docs.expo.dev/versions/latest/sdk/notifications/
[n3]: https://developer.apple.com/documentation/bundleresources/entitlements/com.apple.developer.usernotifications.critical-alerts
[n4]: https://developer.apple.com/contact/request/notifications-critical-alerts-entitlement/
[n5]: https://developer.android.com/about/versions/14/behavior-changes-14
[n6]: https://psnet.ahrq.gov/perspective/reducing-safety-hazards-monitor-alert-and-alarm-fatigue
[n7]: https://www.nurse.com/blog/joint-commission-issues-alert-on-alarm-fatigue/

**Offline-first**
[o1]: https://dev.to/sathish_daggula/react-native-offline-sync-with-sqlite-queue-4975
[o2]: https://tanstack.com/query/v4/docs/framework/react/examples/offline
[o3]: https://dev.to/fedorish/react-native-offline-first-with-tanstack-query-1pe5
[o4]: https://github.com/TanStack/query/issues/5847
[o5]: https://www.npmjs.com/package/@react-native-community/netinfo

**HIPAA & secure messaging**
[h1]: https://www.accountablehq.com/post/hipaa-security-rule-technical-safeguards-the-complete-requirements-list-45-cfr-164-312
[h2]: https://www.ecfr.gov/current/title-45/section-164.312
[h3]: https://www.hipaajournal.com/hipaa-encryption-requirements/
[h4]: https://docs.expo.dev/versions/latest/sdk/securestore/
[h5]: https://www.hipaavault.com/resources/hipaa-compliant-hosting-insights/can-text-messages-be-hipaa-compliant/
[h6]: https://docs.expo.dev/versions/latest/sdk/local-authentication/
[h7]: https://www.pushwoosh.com/blog/hipaa-compliant-push-notifications/
[h8]: https://www.accountablehq.com/post/react-native-hipaa-compliance-guide-step-by-step-checklist-and-best-practices

**Expo / RN production & code-sharing**
[x1]: https://docs.expo.dev/eas-update/introduction/
[x2]: https://docs.expo.dev/eas-update/runtime-versions/
[x3]: https://docs.expo.dev/eas-update/rollbacks/
[x4]: https://docs.expo.dev/push-notifications/fcm-credentials/
[x5]: https://docs.expo.dev/versions/latest/sdk/notifications/
[x6]: https://docs.expo.dev/router/advanced/authentication/
[x7]: https://docs.expo.dev/guides/monorepos/
[x8]: https://tanstack.com/query/latest/docs/framework/react/react-native
[x9]: https://shopify.github.io/flash-list/

**Interaction & notification UX**
[i1]: https://www.aufaitux.com/blog/healthcare-dashboard-ui-ux-design-best-practices/
[i2]: https://docs.expo.dev/versions/latest/sdk/haptics/
[i3]: https://www.velotio.com/engineering-blog/exploring-widgetkit-enhancing-ios-experience-with-widgets
[i4]: https://www.ncbi.nlm.nih.gov/pmc/articles/PMC5955574/
[i5]: https://www.boundev.com/blog/healthcare-app-accessibility-wcag-compliance
[i6]: https://parachutedesign.ca/blog/thumb-zone-ux/
[i7]: https://www.andersdx.com/effective-touchscreen-control-with-medical-gloves/
[i8]: https://teguar.com/consumer-vs-medical-grade-displays/
