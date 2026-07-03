# Hummingbird Platform Reconciliation TODO

**Status:** Execution checklist
**Created:** 2026-07-02
**Scope:** Bring Android and iOS Hummingbird to equal product, contract, visual, and validation parity.

## Purpose

This TODO converts the Android/iOS reconciliation assessment into tracked work.
The immediate problem is not just "Android is behind." Android now has a substantial
generic Altitude implementation, while iOS remains the more elegant role-specific
product baseline. The reconciliation goal is to preserve Android's new contract
coverage while reshaping it into the same role-first, glance-and-act experience as
iOS.

## Source Documents

- `IMPLEMENTATION-PLAN.md`: authoritative architecture, design parity, BFF, auth,
  notification, security, and program definition of done.
- `PERSONA-SCREENS-PLAN.md`: persona screen catalog, BFF paths, seed validation,
  and iOS baseline.
- `ALTITUDE-PERSONA-OPERATING-PLAN.md`: governing Altitude 2.0 direction,
  patient/encounter lens, activity ledger, relay, and Eddy awareness.
- `api-contract/hummingbird-bff.v1.yaml`: intended BFF contract, currently behind
  implemented Laravel routes.
- Current code:
    - iOS product baseline: `hummingbird/iosApp/Hummingbird/App/MainTabView.swift`,
      `Features/Onboarding/RoleExperience.swift`, and role packages under
      `Features/{Transport,EVS,RTDC,OR,Capacity,Executive,Staffing,Improvement}`.
    - Android current implementation:
      `hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/MainScreen.kt`,
      `ui/altitude/AltitudeScreens.kt`, `data/ApiClient.kt`, and
      `data/Models.kt`.
    - Backend BFF: `routes/api.php`, `app/Http/Controllers/Api/Mobile/*`,
      `app/Services/Mobile/*`.

## Parity Definitions

Use these definitions when reviewing work. A task is not done if it only satisfies
one platform or one layer.

- **Product parity:** the same persona can complete the same glance -> action ->
  drill -> patient context journey on iOS and Android.
- **Contract parity:** Laravel routes, OpenAPI, iOS DTO/client, Android DTO/client,
  and tests describe the same endpoints, payloads, errors, and write semantics.
- **Visual parity:** both platforms use the same token values, information
  hierarchy, status vocabulary, typography intent, density, and calm/urgent
  thresholds. Android may be Android-native; it must not feel like a developer
  console.
- **Operational parity:** every allowed mobile write emits an activity-ledger event,
  appears in the right role-filtered activity feed, and is visible to Eddy in
  PHI-safe form.
- **Validation parity:** every persona is manually and automatically validated on
  visible iOS Simulator and Android Emulator against the same seeded data.

## Current Assessment Snapshot

- [ ] Treat iOS as the current product grammar baseline, not as a permanent
      platform owner. iOS has the role-first shell and polished role packages.
- [ ] Treat Android's new Altitude surfaces as valuable contract scaffolding, not
      as the final user experience. The visible `A0`/`A1` navigation, inline role
      chips, and domain chips are debug affordances that should not remain in the
      default app.
- [ ] Treat the backend BFF as ahead of the docs. Laravel now exposes altitude,
      patient context, activity, transport, EVS, command, OR, ops, staffing,
      improvement, and Eddy context routes.
- [ ] Treat OpenAPI as stale until reconciled. It still describes itself as the
      generated-client contract, but does not fully match implemented Laravel
      routes and client usage.
- [ ] Treat `PERSONA-SCREENS-PLAN.md` as stale where it says Android has no role
      layer. Android now has a role catalog and generic Altitude model, but not
      the iOS-style role package architecture.

## Non-Negotiable Product Target

- [ ] First tab is role-specific Home, labeled by role/domain:
      `Trips`, `Turns`, `House`, `OR`, `Capacity`, `Brief`, `Staffing`,
      `Improve`, or equivalent platform-native labels.
- [ ] Second tab is the universal `For You` queue.
- [ ] Third tab is `Activity`.
- [ ] `A0`, `A1`, `A2`, `A2P`, and `A3` are model/flow states inside the product,
      not primary bottom-tab labels for normal users.
- [ ] Role switching happens during onboarding, in profile/settings, or through a
      debug harness. It does not appear inline on every production screen.
- [ ] Domain switching is role-derived. A transporter does not manually pick an
      A1 workspace domain during normal operation.
- [ ] Every role package includes:
    - [ ] Home glance.
    - [ ] Primary workspace/list.
    - [ ] A2 drill for explainability.
    - [ ] A2P patient/encounter lens when authorized.
    - [ ] Inline primary action where the action is safe and role-authorized.
    - [ ] Activity trail and relay context.
    - [ ] Eddy context entry where useful.
    - [ ] `Open in Zephyrus` deep link for study/desktop-grade work.

## Phase 0 - Freeze Truth And Stop Drift

### P0.1 Route and Contract Inventory

- [x] Generate a route inventory for `/api/mobile/v1/*` from Laravel.
- [x] Generate an OpenAPI path inventory from
      `docs/hummingbird/api-contract/hummingbird-bff.v1.yaml`.
- [x] Diff the inventories and classify every mismatch:
    - [x] Implemented in Laravel, missing from OpenAPI.
    - [x] In OpenAPI, missing from Laravel.
    - [x] Implemented but named differently.
    - [x] Implemented but payload shape differs from schema.
    - [x] Client method exists without contract coverage.
    - [x] Contract path exists without any client method.
- [x] Record the diff in a short table in this document or a generated artifact
      under `docs/hummingbird/api-contract/`.

Known mismatches to resolve first:

- [x] OpenAPI has `/command/brief`; Laravel currently exposes `/command/house`
      but not a mobile `/command/brief` route.
- [x] iOS uses `/staffing/overview`; OpenAPI still lists planned
      `/staffing/plans` and `/staffing/requests`.
- [x] iOS and Laravel use `/improvement/pdsa` and
      `/improvement/opportunities`; OpenAPI must explicitly cover them.
- [x] Decide whether `/or/performance` is still planned, implemented now, or
      deferred.
- [x] Confirm `/ops/actions/{id}/{transition}` is either implemented for mobile
      or removed/deferred from the contract.
- [x] Keep `/for-you` as the canonical path unless there is a strong reason to
      churn working clients.

### P0.2 OpenAPI Reconciliation

- [x] Update `hummingbird-bff.v1.yaml` to match implemented routes.
- [x] Add `staffing` and `improvement` tags if missing.
- [x] Add request/response schemas for:
    - [x] `StaffingOverview`.
    - [x] `StaffingFill`.
    - [x] `PdsaCycle`.
    - [x] `ImprovementOpportunity`.
    - [x] `HouseBrief`.
    - [x] `ORBoard`.
    - [x] `OpsApproval`.
    - [x] `MobileAltitudeHome`.
    - [x] `MobileAltitudeWorkspace`.
    - [x] `MobileAltitudeDrill`.
    - [x] `PatientOperationalContext`.
    - [x] `ActivityEvent`.
    - [x] `EddyContextPacket`.
- [x] Ensure all list/detail envelopes have:
    - [x] `data`.
    - [x] `meta.as_of`.
    - [x] `meta.stale`.
    - [x] `meta.version`.
    - [x] `links.web` when a web handoff exists.
- [x] Add error response coverage for:
    - [x] `401` unauthenticated.
    - [x] `403` missing ability or forbidden persona.
    - [x] `404` entity/detail not found.
    - [x] `409` stale write or illegal transition.
    - [x] `422` invalid action body.

### P0.3 Contract Tests

- [x] Extend `tests/Feature/MobileBffTest.php` so every documented mobile read
      route is auth-gated and returns the uniform envelope.
- [x] Add tests for every mobile write route requiring `mobile:act`.
- [x] Add tests that a `password:change` token cannot call mobile read or write
      routes.
- [x] Add tests that raw patient identifiers are rejected by
      `/patients/{contextRef}/operational-context`.
- [x] Add tests that list payloads do not expose raw patient identifiers.
- [x] Add a route-vs-OpenAPI drift test or script that fails CI when paths diverge.
- [x] Add one seeded-shape test per high-value endpoint:
    - [x] `/transport/queue`.
    - [x] `/evs/queue`.
    - [x] `/rtdc/house`.
    - [x] `/rtdc/bed-requests`.
    - [x] `/or/board`.
    - [x] `/command/house`.
    - [x] `/ops/inbox`.
    - [x] `/staffing/overview`.
    - [x] `/improvement/pdsa`.
    - [x] `/altitude/home`.
    - [x] `/activity`.

### P0.4 Documentation Truth Update

- [x] Update `PERSONA-SCREENS-PLAN.md` to reflect that Android now has:
    - [x] `MobileRoleCatalog`.
    - [x] `AltitudeViewModel`.
    - [x] `ui/altitude/AltitudeScreens.kt`.
    - [x] altitude, patient context, activity, and Eddy client calls.
- [x] Add a note that Android still lacks final role-package UX parity.
- [x] Update `IMPLEMENTATION-PLAN.md` appendix rows that mention backend bugs or
      unimplemented routes if the current code has superseded them.
- [x] Update `README.md` document map to include this reconciliation TODO.

## Phase 1 - Shared Role, Route, And Status Core

### P1.1 Source Of Truth For Roles

- [x] Decide where the canonical role catalog lives:
    - [ ] Preferred: backend role catalog endpoint or OpenAPI-generated constants.
    - [x] Acceptable interim: checked-in shared JSON under `docs/hummingbird/` or
          `hummingbird/shared-contract/`.
- [x] Include for each role:
    - [x] `role_id`.
    - [x] `title`.
    - [x] `subtitle`.
    - [x] `unit_bound`.
    - [x] `home_kind`.
    - [x] `default_domain`.
    - [x] `queue_filter`.
    - [x] `glance_question`.
    - [x] `web_deeplink`.
    - [x] platform icon names for iOS and Android.
- [x] Generate or verify parity for:
    - [x] iOS `Role.swift`.
    - [x] iOS `RoleExperience.swift`.
    - [x] Android `MobileRoleCatalog`.
    - [x] Backend `MobilePersonaCatalog`.
- [x] Add tests that all four role catalogs contain the same 14 role ids.

### P1.2 Shared Home Kind And Feature Route Model

- [x] Define one canonical `HomeKind` list:
    - [x] `census`.
    - [x] `transportJobs`.
    - [x] `evsTurns`.
    - [x] `houseCapacity`.
    - [x] `orBoard`.
    - [x] `capacityDemand`.
    - [x] `houseBrief`.
    - [x] `staffing`.
    - [x] `improvement`.
- [x] Define one canonical `FeatureRoute` list for workspaces and details.
- [x] Ensure backend persona descriptions use the same `home` values as clients.
- [x] Ensure Android bottom tab labels derive from `HomeKind`, not hardcoded
      `A0`/`A1`.
- [x] Ensure iOS and Android map `capacity_lead`, `periop_manager`, `or_nurse`,
      and `executive` consistently.

### P1.3 Shared Status Vocabulary

- [x] Lock the status enum:
    - [x] `success`.
    - [x] `warning`.
    - [x] `critical`.
    - [x] `info`.
- [x] Lock secondary urgency tiers separately:
    - [x] `T1`.
    - [x] `T2`.
    - [x] `T3`.
    - [x] `T4`.
- [x] Stop overloading `tier` with both notification tier and visual status when
      avoidable.
- [x] Ensure status labels are product-specific:
    - [x] capacity: `Within capacity`, `Near capacity`, `At capacity`, `No data`.
    - [x] task queue: `Routine`, `At risk`, `Overdue`, `STAT`.
    - [x] approval: `Pending`, `Approved`, `Rejected`, `Expired`.
- [x] Add Android/iOS tests or snapshots for `StatusChip` label/icon/color parity.

### P1.4 Shared DTO Generation Path

- [x] Decide whether immediate DTO generation uses:
    - [ ] OpenAPI generator for Kotlin/Swift.
    - [ ] KMP `shared` module with SKIE later.
    - [x] Interim manually maintained DTOs with drift tests.
- [ ] Replace Android's high-risk hand-parsed JSON incrementally:
    - [ ] Start with `MobileAltitudeHome`.
    - [ ] Then `ForYouItem`.
    - [ ] Then `ActivityEvent`.
    - [ ] Then `PatientOperationalContext`.
    - [ ] Then domain packages.
- [x] Add decode tests using captured fixture JSON for both platforms.
- [x] Store fixtures under a shared location, not inside one platform only.

## Phase 2 - Android Product Shell Rebuild

### P2.1 Keep Altitude Explorer, But Move It Out Of Production UX

- [x] Rename the current generic Android altitude flow as a debug/developer
      feature, for example `DebugAltitudeExplorer`.
- [x] Gate it behind:
    - [ ] debug build type, or
    - [x] profile developer switch, or
    - [x] explicit intent extra for QA.
- [x] Remove inline `RoleSelector` from default Home.
- [x] Remove inline `DomainSelector` from default Workspace.
- [x] Keep A0/A1/A2/A2P copy in breadcrumbs or detail metadata, not in bottom tabs.

### P2.2 Android Main Shell

- [x] Replace `AltitudeTopTab { Home, Workspace, Activity }` with:
    - [x] role home tab.
    - [x] `For You`.
    - [x] `Activity`.
- [x] Role home tab label must derive from `HomeKind`.
- [x] Role home tab icon must derive from the role/home catalog.
- [x] Add profile/settings entry for:
    - [x] role confirmation.
    - [x] unit assignment.
    - [x] notification preferences placeholder.
    - [x] sign out.
    - [x] debug role switcher for broad-access demo users.
- [x] Add Android equivalent of iOS test affordances:
    - [x] `HB_AUTOLOGIN`.
    - [x] `HB_ROLE`.
    - [x] `HB_TAB`.
    - [x] `HB_OPEN_UNIT` or role-specific open target.
    - [x] `HB_FORCE_ERROR`.
- [x] Ensure admin/demo accounts can explore personas without polluting normal
      production role selection.

### P2.3 Android Onboarding And Profile

- [x] Build Android role confirmation screen matching iOS `OnboardingView`.
- [x] Build Android unit confirmation for unit-bound roles.
- [x] Persist confirmed profile locally.
- [x] Use server `/me` role data for preselection when available.
- [x] Add a broad-access demo path that defaults to `house_supervisor`.
- [x] Move role switching to profile for demo/admin users.
- [x] Add accessibility labels and 48dp minimum touch targets.

### P2.4 Android Visual System Polish

- [x] Add the missing 4px spacing scale to Android theme:
    - [x] `s1 = 4.dp`.
    - [x] `s2 = 8.dp`.
    - [x] `s3 = 12.dp`.
    - [x] `s4 = 16.dp`.
    - [x] `s5 = 20.dp`.
    - [x] `s6 = 24.dp`.
- [ ] Add Android typography wrappers to avoid ad hoc `sp` values everywhere.
- [ ] Add tabular numeric styling for all metrics where Compose supports it.
- [x] Add Android `Panel` shadow/elevation parity with iOS quiet-lift panels.
- [x] Keep panel radius aligned to the current iOS token or explicitly revise
      both platforms together.
- [x] Replace generic section labels like `A0 glance tiles` and `For You head`
      with worker-facing labels.
- [ ] Ensure all urgent color is paired with icon and label.
- [ ] Reduce visible development jargon:
    - [x] `Altitude Home`.
    - [x] `Workspace domain`.
    - [x] `Patient lens ptok...`.
    - [x] endpoint strings in action rows.
    - [x] raw event ids in normal views.
- [x] Add empty/error/loading states matching iOS `RetryableMessage` tone.
- [ ] Add visual pass for small devices and large font settings.

## Phase 3 - Android Role Package Parity

Build Android packages in the same order as proven iOS screens and live backend
readiness. Do not start a later package until the previous package passes the
shared acceptance loop.

### P3.1 Shared Android Package Structure

- [x] Create package folders:
    - [x] `ui/transport`.
    - [x] `ui/evs`.
    - [x] `ui/rtdc`.
    - [x] `ui/capacity`.
    - [x] `ui/or`.
    - [x] `ui/executive`.
    - [x] `ui/staffing`.
    - [x] `ui/improvement`.
    - [x] `ui/altitude`.
- [x] Move generic A2/A2P/activity pieces into `ui/altitude`.
- [x] Keep common row/chip/panel components under `ui/components`.
- [ ] Introduce one ViewModel per package unless shared KMP repositories land
      first.

### P3.2 Transport Package

- [x] Build `TransportJobsScreen`.
- [x] Build `JobDetailScreen`.
- [x] Build `HandoffSheet`.
- [x] Render metrics:
    - [x] active.
    - [x] STAT.
    - [x] at risk.
    - [x] completed today.
- [x] Render STAT/at-risk banner only when earned.
- [x] Split queue into:
    - [x] my trips.
    - [x] available/offered jobs.
- [x] Add inline `Claim` where allowed.
- [x] Add lifecycle actions:
    - [x] claim/assign.
    - [x] arrived.
    - [x] picked up.
    - [x] en route.
    - [x] arrived destination.
    - [x] handoff.
    - [x] complete.
- [x] Add A2 drill link from each job.
- [x] Add A2P link only when `patient_context_ref` is available and authorized.
- [ ] Validate against seeded transport data.

Acceptance:

- [x] Transport role lands on `Trips`, not generic Altitude.
- [ ] Shows same count and priority semantics as iOS against the seed.
- [ ] Claim/progress action updates backend and refreshes Home and For You.
- [x] No raw patient identifiers appear.

### P3.3 EVS Package

- [x] Build `BedTurnsScreen`.
- [x] Build `TurnDetailScreen`.
- [x] Render metrics:
    - [x] pending.
    - [x] overdue.
    - [x] isolation.
    - [x] completed today.
- [x] Show next dirty bed first.
- [x] Show isolation badge with icon and text.
- [x] Add lifecycle actions:
    - [x] claim.
    - [x] start.
    - [x] complete.
    - [x] blocked/unable when backend supports it.
- [x] Show PPE/SOP callout for isolation start.
- [x] Add A2 drill link.
- [x] Add A2P dependency context where available.

Acceptance:

- [x] EVS role lands on `Turns`.
- [x] Overdue and isolation semantics match iOS.
- [x] Complete removes item from queue after refresh.
- [x] Bed manager receives resulting activity event.

### P3.4 House Capacity / Bed Manager Package

- [x] Build `HouseCapacityScreen`.
- [x] Build `PlacementDetailScreen`.
- [x] Render house rollup:
    - [x] occupancy.
    - [x] net bed need.
    - [x] pending placements.
    - [x] ED boarding.
- [x] Render pending placements oldest/highest-risk first.
- [x] Render pressured units.
- [x] Add placement recommendation review:
    - [x] chosen bed.
    - [x] score.
    - [x] rationale.
    - [x] safety chips.
    - [x] runner-up when available.
- [x] Add `Place` and `Reject` actions where authorized.
- [x] Add A2 drill and A2P patient context.

Acceptance:

- [x] Bed manager role lands on `House`.
- [x] Recommendation and decision flow matches iOS.
- [x] Decision writes event and clears/refreshed placement state.

### P3.5 Android For You Parity

- [x] Replace old standalone `ForYouScreen.kt` implementation or merge it into
      the new shell.
- [x] Make Android For You role-filtered using the same server contract as iOS.
- [x] Add inline actions:
    - [x] resolve barrier.
    - [x] claim transport.
    - [x] claim EVS turn.
    - [x] approve/reject ops action when supported.
    - [x] fill staffing request when supported.
- [x] Add navigation:
    - [x] A2 drill for supported item ids.
    - [x] A2P patient context when authorized.
    - [x] unit detail for unit-scoped capacity/barrier items.
- [x] Add honest empty states by role.
- [x] Add stale/error state that never reads as "All clear."

Acceptance:

- [ ] For You count and ordering match iOS for the same role and seed.
- [x] Each inline action round-trips and refreshes queue.
- [x] Role filters are not implemented differently across platforms.

### P3.6 Android Activity And A2/A2P Parity

- [x] Keep `ActivityFeedScreen`, but polish it as a user-facing relay feed.
- [x] Add event grouping by day or role if the feed becomes dense.
- [x] Add acknowledge action only when meaningful.
- [x] Add A2 drill screen with:
    - [x] explanation.
    - [x] dependencies.
    - [x] allowed actions.
    - [x] activity trail.
    - [x] Eddy context.
    - [x] web deep link.
- [x] Add A2P patient context screen with:
    - [x] header.
    - [x] status spine.
    - [x] dependencies.
    - [x] recommendations.
    - [x] timeline.
    - [x] allowed actions.
    - [x] PHI policy.
    - [x] web handoff.
- [x] Hide raw refs unless the label is intentionally framed as a token.

Acceptance:

- [x] A user can move from role Home -> For You/workspace item -> A2 -> A2P.
- [x] A2P rejects unauthorized raw patient ids and handles `403` cleanly.
- [x] Screen hierarchy feels like product, not JSON inspection.

### P3.7 Android Wave 2 And Wave 3 Packages

- [x] OR package:
    - [x] `ORBoardScreen`.
    - [x] `CaseDetailScreen`.
    - [x] safety-note acknowledgement path when backend supports it.
    - [x] room/case status and delay affordances.
- [x] Capacity package:
    - [x] `CapacityDemandScreen`.
    - [x] approvals inbox.
    - [x] approval detail.
    - [x] approve/reject actions.
- [x] Executive package:
    - [x] `HouseBriefScreen`.
    - [x] strain detail.
    - [x] "one thing" material breach.
    - [x] calm default state.
- [x] Staffing package:
    - [x] `StaffingScreen`.
    - [x] request detail.
    - [x] fill action.
    - [x] below-safe display.
- [x] Improvement package:
    - [x] `ImprovementScreen`.
    - [x] PDSA list/detail.
    - [x] opportunity board.
    - [x] read-only honest framing until write API exists.

Acceptance:

- [x] Every role in `MobileRoleCatalog` has a non-generic Home.
- [x] Every unfinished backend write path has an honest disabled state.
- [ ] Android role screenshots are comparable to iOS role screenshots.

## Phase 4 - iOS Altitude 2.0 Retrofit

iOS should absorb the new Altitude contract without losing the existing role
package elegance.

### P4.1 A2 Drill Integration

- [x] Confirm every For You item that supports drill navigates to
      `DrillDetailView`.
- [ ] Add drill links from:
    - [x] transport job rows.
    - [x] EVS turn rows.
    - [x] placement rows.
    - [ ] OR case/room rows.
    - [x] ops approvals.
    - [x] staffing gaps/requests.
    - [x] improvement opportunities where useful.
- [x] Make `DrillDetailView` use role/persona and payload status consistently.
- [x] Add allowed action execution from drill payload where body-free actions are
      safe.
- [x] Keep endpoint strings hidden from normal users.

### P4.2 A2P Patient Context Integration

- [ ] Add patient context links to:
    - [x] bed placements.
    - [x] transport jobs.
    - [x] EVS turns.
    - [ ] OR cases.
    - [x] relevant activity events.
    - [x] relevant drills.
- [x] Ensure the UI labels the context as operational, not EHR chart detail.
- [x] Ensure PHI-minimized list rows remain PHI-minimized.
- [ ] Add clear `403` state for unauthorized contexts.

### P4.3 Activity And Relay Integration

- [x] Keep `ActivityFeedView` as the third tab.
- [ ] Add small activity summaries inside high-value role package detail screens.
- [ ] Link activity events back to A2 drills and A2P patient context where allowed.
- [x] Add acknowledge flow for events that should be acked.
- [x] Confirm activity rows do not show raw patient identifiers.

### P4.4 iOS DTO And Route Cleanup

- [ ] Remove or mark unused iOS API methods after OpenAPI reconciliation.
- [ ] Add missing iOS client methods only after the route is in OpenAPI and
      Laravel.
- [ ] Move duplicated status/string helpers toward shared generation.
- [x] Add fixture decode tests once shared fixtures exist.

## Phase 5 - Backend Product Specialization

### P5.1 Persona-Specific Altitude Home

- [ ] Upgrade `MobileAltitudeService::home()` from reordered house metrics to
      true role-specific composition.
- [ ] For `charge_nurse`, include:
    - [ ] pinned unit capacity.
    - [ ] open barriers.
    - [ ] inbound bed requests.
    - [ ] dirty/blocked beds.
    - [ ] staffing gap.
    - [ ] active transports.
- [ ] For `bedside_nurse`, include:
    - [ ] assigned patient operational actions.
    - [ ] transport/EVS dependencies.
    - [ ] discharge barriers.
    - [ ] handoff/huddle tasks.
- [ ] For `bed_manager`, include:
    - [ ] house occupancy.
    - [ ] net bed need.
    - [ ] pending placements.
    - [ ] ED boarding.
    - [ ] dirty beds.
    - [ ] blocked capacity.
- [ ] For `transport`, include:
    - [ ] active trip.
    - [ ] next STAT/urgent job.
    - [ ] pickup readiness.
    - [ ] SLA timers.
- [ ] For `evs`, include:
    - [ ] next dirty bed.
    - [ ] overdue turns.
    - [ ] isolation turns.
    - [ ] placement-blocking bed turns.
- [ ] For `or_nurse` and `periop_manager`, include:
    - [ ] live rooms.
    - [ ] cases.
    - [ ] delays.
    - [ ] turnovers.
    - [ ] safety-note state.
- [ ] For `capacity_lead`, include:
    - [ ] strain.
    - [ ] forecast.
    - [ ] pending approvals.
    - [ ] unowned actions.
    - [ ] stale feeds.
- [ ] For `executive`, include:
    - [ ] strain.
    - [ ] hero KPIs.
    - [ ] one material breach.
    - [ ] brief status.
    - [ ] confidence/freshness.
- [ ] For `staffing_coordinator`, include:
    - [ ] open requests.
    - [ ] units below safe.
    - [ ] critical gaps.
    - [ ] total gap headcount.
- [ ] For `pi_lead`, include:
    - [ ] active PDSA cycles.
    - [ ] due stages.
    - [ ] opportunities.
    - [ ] recurring barrier patterns.

### P5.2 Server-Side For You Filtering

- [ ] Move role filtering out of clients and into `MobileForYouService`.
- [ ] Accept `persona` and assignment scope in `/for-you`.
- [ ] Add unit-scoped filtering for unit-bound roles.
- [ ] Add critical-care filtering for intensivists.
- [ ] Add service filtering for hospitalists when assignment data exists.
- [ ] Add "activity only" vs "needs action" separation.
- [ ] Add primary action metadata:
    - [ ] label.
    - [ ] method.
    - [ ] endpoint.
    - [ ] body schema/ref.
    - [ ] requires online.
    - [ ] safety-critical flag.
- [ ] Add tests that each persona gets the right item classes.

### P5.3 Operational Activity Ledger Completion

- [ ] Confirm every current mobile write records one event:
    - [ ] barrier resolve.
    - [ ] bed placement decision.
    - [ ] transport status.
    - [ ] transport handoff.
    - [ ] EVS status.
    - [ ] ops approval decision.
    - [ ] staffing fill.
- [ ] Add missing events for:
    - [ ] OR status/safety note actions.
    - [ ] huddle action item actions.
    - [ ] PDSA stage advance when write API exists.
    - [ ] patient operational state changes.
- [ ] Ensure every event includes:
    - [ ] event uuid.
    - [ ] event type.
    - [ ] occurred at.
    - [ ] actor user.
    - [ ] actor role.
    - [ ] source surface.
    - [ ] domain.
    - [ ] scope.
    - [ ] prior/current status.
    - [ ] affected roles.
    - [ ] push tier.
    - [ ] PHI policy.
    - [ ] entity links.
- [ ] Add tests for relay fan-out per event type.
- [ ] Add tests that non-urgent events stay activity-only.

### P5.4 Patient/Encounter Lens Expansion

- [ ] Add OR case context to `MobilePatientContextService`.
- [ ] Add staffing dependency context where staffing blocks movement/admission.
- [ ] Add ops approval/recommendation context.
- [ ] Add huddle/action-item context.
- [ ] Add barrier context with patient link where source data allows it.
- [ ] Add per-role redaction policy tests.
- [ ] Add cache invalidation or freshness semantics for patient context cache.
- [ ] Ensure raw `patient_ref` cannot be used as a mobile URL detail key.

### P5.5 Eddy Operational Awareness

- [ ] Ensure Eddy context packets are populated for:
    - [ ] activity events.
    - [ ] A2 drills.
    - [ ] A2P patient context.
    - [ ] house/persona scopes.
- [ ] Ensure Eddy receives PHI-minimized context by default.
- [ ] Ensure Eddy can cite:
    - [ ] current snapshot.
    - [ ] relevant event trail.
    - [ ] remaining blockers.
    - [ ] affected roles.
    - [ ] recommended next action.
- [ ] Add tests that Eddy cannot self-approve or perform safety-critical writes.
- [ ] Add tests that human decisions are recorded back into the event trail.

## Phase 6 - Security, Offline, Push, And Platform Foundations

### P6.1 Android Security Parity

- [ ] Replace SharedPreferences token storage with encrypted storage.
- [ ] Add biometric app lock.
- [ ] Add app background privacy screen / `FLAG_SECURE` policy as appropriate.
- [ ] Add logout token revoke parity.
- [ ] Add idle auto-lock parity.
- [ ] Add root/attestation placeholder or tracked task for GA.
- [ ] Ensure no tokens or PHI are logged.

### P6.2 Push And Device Registration Parity

- [ ] Add Android FCM token registration to `/api/mobile/v1/devices`.
- [ ] Add Android notification channels:
    - [ ] T1 critical/full-screen intent path.
    - [ ] T2 high/actionable.
    - [ ] T3 awareness.
    - [ ] T4 digest.
- [ ] Add notification action handlers for:
    - [ ] claim.
    - [ ] acknowledge.
    - [ ] approve/reject where safe.
- [ ] Add push payload PHI linter tests.
- [ ] Verify quiet hours and per-shift budget are server-controlled.

### P6.3 Realtime And Offline Parity

- [ ] Keep foreground websocket behavior PHI-free.
- [ ] Resnapshot visible queries on reconnect.
- [ ] Keep 15 second poll fallback.
- [ ] Add stale badge handling on both platforms.
- [ ] Add Android cache strategy:
    - [ ] cache last successful read.
    - [ ] show as-of time.
    - [ ] show stale state on fetch failure.
- [ ] Add outbox only for non-critical writes.
- [ ] Block safety-critical writes while offline with explicit reason.
- [ ] Add 409 conflict UX on both platforms:
    - [ ] "Changed since you loaded. Review current state."
    - [ ] re-fetch button.
    - [ ] no blind overwrite.

## Phase 7 - Validation And QA Harness

### P7.1 Backend Verification

- [ ] Run `php artisan test --testsuite=Feature`.
- [ ] Run targeted mobile tests:
    - [x] `php artisan test --filter=MobileBffTest`.
    - [x] `php artisan test --filter=MobileAltitudeContractTest`.
    - [x] `php artisan test --filter=MobileBackendSafetyTest`.
    - [x] `php artisan test --filter=PersonaRelayPolicyTest`.
- [ ] Add missing tests for each new route and write action before client work.
- [x] Run OpenAPI validation.
- [ ] Run PHI scrub tests.

### P7.2 Platform Build Verification

- [ ] Android:
    - [ ] Run Gradle assemble/debug build.
    - [ ] Run Android unit tests when added.
    - [ ] Run Android UI/screenshot tests when harness exists.
- [ ] iOS:
    - [ ] Run Xcode build for simulator.
    - [ ] Run iOS tests when added.
    - [ ] Run iOS screenshot tests when harness exists.
- [ ] No platform-specific PR merges without both platform builds green, unless
      the PR is explicitly platform-infrastructure-only and documented.

### P7.3 Persona Screenshot Matrix

Capture the same surfaces for each role on both platforms:

- [ ] `charge_nurse`.
- [ ] `bedside_nurse`.
- [ ] `bed_manager`.
- [ ] `house_supervisor`.
- [ ] `hospitalist`.
- [ ] `intensivist`.
- [ ] `evs`.
- [ ] `transport`.
- [ ] `or_nurse`.
- [ ] `capacity_lead`.
- [ ] `periop_manager`.
- [ ] `staffing_coordinator`.
- [ ] `pi_lead`.
- [ ] `executive`.

For each role:

- [ ] Home.
- [ ] For You.
- [ ] Activity.
- [ ] A2 drill.
- [ ] A2P patient context where authorized.
- [ ] Error state.
- [ ] Empty state.
- [ ] Stale state.
- [ ] Large text/dynamic type state.

Acceptance:

- [ ] Android and iOS communicate the same top question for the persona.
- [ ] Numbers match the same seeded backend state.
- [ ] Status color, icon, and label semantics match.
- [ ] Primary action is equally prominent.
- [ ] No text overlap on small devices.
- [ ] No raw patient identifiers in list/push/activity-safe surfaces.

### P7.4 End-To-End Validation Scenarios

Validate these on both platforms with the same seed.

- [ ] ED boarder to inpatient bed:
    - [ ] executive sees house strain.
    - [ ] capacity lead drills into alert.
    - [ ] bed manager reviews placement and patient context.
    - [ ] bed manager places bed.
    - [ ] charge nurse receives inbound readiness task.
    - [ ] EVS and transport dependencies appear when needed.
    - [ ] activity feed shows the role-filtered trail.
    - [ ] Eddy context knows what changed.
- [ ] ICU downgrade unlocks capacity:
    - [ ] intensivist readiness action.
    - [ ] bed manager opportunity.
    - [ ] charge nurse inbound dependency.
    - [ ] transport movement.
    - [ ] capacity/executive threshold behavior.
    - [ ] PI aggregate pattern only, not a page.
- [ ] OR delay creates bed and staffing pressure:
    - [ ] OR nurse delay reason.
    - [ ] periop manager drill.
    - [ ] bed manager postop demand shift.
    - [ ] staffing coordinator downstream risk.
    - [ ] capacity forecast impact.
    - [ ] Eddy revised recommendation.
- [ ] Discharge barrier resolved:
    - [ ] hospitalist resolves barrier.
    - [ ] charge nurse sees progression.
    - [ ] bed manager sees bed release forecast.
    - [ ] EVS queued after discharge.
    - [ ] transport receives discharge movement when patient-ready.
    - [ ] PI sees aggregate pattern.
    - [ ] Eddy learns outcome.

## Phase 8 - Development Process Controls

### P8.1 PR Checklist

Every Hummingbird PR must answer:

- [ ] Which personas are affected?
- [ ] Which BFF routes are affected?
- [ ] Is OpenAPI updated?
- [ ] Are iOS and Android DTOs updated or generated?
- [ ] Are both platform builds green?
- [ ] Are backend Feature tests green?
- [ ] Are PHI guard tests green?
- [ ] Does this alter role routing or HomeKind?
- [ ] Does this add a mobile write?
- [ ] If it adds a mobile write, where is the activity-ledger event recorded?
- [ ] If it adds a notification source, what earned-urgency tier and budget apply?
- [ ] Does any user-facing copy leak model vocabulary? (Altitude/A0–A3, "glance",
      "workspace", "drill", "relay", persona ids, snake_case keys — the model is
      architecture, not UI copy; screens speak the worker's language.)
- [ ] Are screenshots/GIFs captured for each affected persona on both platforms?

### P8.2 No-Go Rules

- [ ] Do not add a platform-only user-facing feature unless the other platform has
      a same-sprint parity task and honest placeholder.
- [ ] Do not expose `A0`/`A1` as default bottom-tab labels.
- [ ] Do not add endpoint-specific UI copy that exposes implementation details.
- [ ] Do not add a client-only status derivation when the backend can provide the
      StatusEngine value.
- [ ] Do not add a list payload, notification, log, or activity-safe row with raw
      patient identifiers.
- [ ] Do not implement a safety-critical offline write queue.
- [ ] Do not merge new mobile routes without OpenAPI and conformance coverage.

### P8.3 Weekly Parity Review

- [ ] Review route/OpenAPI/client drift.
- [ ] Review Android/iOS screenshot matrix.
- [ ] Review incomplete persona packages.
- [ ] Review PHI/safety test failures.
- [ ] Review event-ledger gaps.
- [ ] Review stale docs and update the first-page document map.

## Suggested First Five PRs

### PR 1 - Contract Truth

- [ ] Reconcile OpenAPI with Laravel routes.
- [ ] Add route/OpenAPI drift test.
- [ ] Update stale docs and README link.
- [ ] No visual/client changes.

### PR 2 - Android Shell Reset

- [ ] Move current Android Altitude screens behind debug mode.
- [ ] Implement role-derived three-tab shell.
- [ ] Add Android onboarding/profile role confirmation.
- [ ] Add role/home labels and icons from shared catalog.

### PR 3 - Android Transport And EVS Parity

- [ ] Build Android `TransportJobsScreen`, `JobDetailScreen`, and `HandoffSheet`.
- [ ] Build Android `BedTurnsScreen` and `TurnDetailScreen`.
- [ ] Match iOS metrics, row hierarchy, actions, and states.
- [ ] Validate against seed on emulator.

### PR 4 - Android House Capacity, For You, Activity, A2/A2P

- [ ] Build Android House Capacity and Placement detail parity.
- [ ] Replace old Android For You with role-filtered, action-capable For You.
- [ ] Polish Activity, A2 drill, A2P patient context for production use.
- [ ] Validate bed manager and charge/house roles.

### PR 5 - Shared DTO Fixtures And iOS Altitude Threading

- [x] Add shared JSON fixtures for core BFF payloads.
- [x] Add Android/iOS decode tests.
- [ ] Thread A2/A2P/activity into iOS role packages where missing.
- [ ] Hide raw refs and endpoint strings in iOS/Android detail surfaces.

## Definition Of Done

The reconciliation is complete when:

- [ ] OpenAPI, Laravel routes, iOS client, Android client, and tests agree.
- [ ] Every role lands on a role-specific Home on both platforms.
- [ ] `For You` has equal filtering, ordering, action, and drill behavior on both
      platforms.
- [ ] Activity relay appears on both platforms with the same PHI policy.
- [ ] A2 and A2P work from every relevant item on both platforms.
- [ ] Android no longer feels like a generic altitude/debug explorer in normal
      use.
- [ ] iOS has absorbed the new Altitude/activity/patient-context contract without
      losing the role-package elegance.
- [ ] Every mobile write records one operational event.
- [ ] Eddy can reason over current state, event trail, remaining blockers, and
      affected roles without autonomous approval authority.
- [ ] Both platforms pass build, backend, contract, PHI, and persona screenshot
      gates.
