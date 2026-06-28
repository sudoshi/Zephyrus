# 08 — Eddy on Mobile (Hummingbird)

How Eddy — Zephyrus's process-aware AI agent — embeds in the native Hummingbird
apps. The **backend** (the Eddy Mobile BFF) lives in this repo and is implemented;
the **native UI** (Compose / SwiftUI) lives in the separate `hummingbird/` repo and
builds against the contract below.

> Architecture fit. Eddy follows the same split as the rest of Hummingbird
> ([03-architecture.md](03-architecture.md)): a small, role-scoped, PHI-minimized
> **BFF** (`/api/mobile/v1/eddy/*`) shaped for the screens, consumed by the shared
> KMP **Ktor client** generated from
> [`api-contract/hummingbird-bff.v1.yaml`](../api-contract/hummingbird-bff.v1.yaml).
> There is **no React Native** and no shared TypeScript — the web dock and the
> native app share a *contract*, not a codebase.

---

## 1. What the BFF gives the apps

| Endpoint | Scope | Purpose |
|---|---|---|
| `POST /eddy/chat` | `mobile:read` | One turn → assistant reply (+ optional draft action). Mobile envelope. |
| `POST /eddy/chat/stream` | `mobile:read` | SSE token stream (Ktor consumes natively). |
| `GET /eddy/conversations` | `mobile:read` | The user's recent conversations. |
| `GET /eddy/conversations/{uuid}` | `mobile:read` | One conversation + messages (user-scoped). |
| `GET /eddy/approvals` | `mobile:read` | Pending **Eddy-proposed** approvals the user may act on. |
| `GET /eddy/approvals/{uuid}` | `mobile:read` | Fetch-on-open dry-run preview. |
| `POST /eddy/approvals/{uuid}/decision` | **`mobile:act`** | Approve / reject. A human decision — never the agent. |

Conversations opened on mobile are persisted with `origin = hummingbird` (the web
dock uses `origin = web`); both share the same `eddy.*` store and a user's history
is continuous across surfaces.

Eddy is **stateless** — Laravel owns all persistence and the cloud-usage ledger.
The same provider policy, PHI gate, and "advice-not-autopilot" governance the web
dock uses apply unchanged; mobile is a second presentation, not a second brain.

---

## 2. Native surfaces (build once per platform from the design tokens)

| Mobile component | Web analog | Notes |
|---|---|---|
| `EddyChatScreen` | `EddySlideOver` | Full-screen chat; role-aware quick-action header. |
| `EddyMessageList` | `EddyMessageList` | Markdown assistant bubbles; `tabular-nums` for metrics. |
| `EddyApprovalSheet` (bottom sheet) | `EddyApprovalCard` | Dry-run preview + rationale + runner-up; Approve / Deny. |
| `EddyVoiceButton` | (none) | On-device STT → text into the composer. |
| `EddyQuickActions` | `EddyAskButton` chips | Role-keyed seed prompts (§4). |

Components are token-themed (operational **blue/slate** `healthcare-*`; crimson/gold
is the Acumenus mark + focus only), status is **never color alone** (pair the tier
with an icon + label), dark-default. Same Two-System Rule and rationed status ramp
as the web ([03-architecture.md §5](03-architecture.md)).

---

## 3. Streaming, push, offline (PHI discipline)

### 3.1 SSE frame contract (`/eddy/chat/stream`)

```
data: {"conversation_id":"<uuid>"}     ← first frame
data: {"token":"…"}                     ← N passthrough token frames
data: {"persisted":true,"message_id":<id>,"proposed_action":{…}|null}   ← terminal
data: [DONE]
```

Render tokens as they arrive; render the approval sheet from the **`proposed_action`
on the terminal `persisted` frame** (it is catalog-validated + tier/risk-enriched),
not from any block parsed out of the token text.

### 3.2 The PHI-free doorbell (`B.8` fetch-on-open)

When an Eddy proposal lands pending, the backend rings a push whose payload carries
**only** ids + a server-derived tier + a deep link — **no params, rationale, or
patient detail**:

```json
{ "kind": "eddy_approval", "approval_uuid": "…", "action_uuid": "…",
  "action_type": "propose_bed_placement", "surface": "rtdc",
  "tier": "tier_1", "deep_link": "zephyrus://eddy/approvals" }
```

On tap → biometric unlock → `GET /eddy/approvals/{uuid}` fetches the real dry-run.
The push is a **doorbell, not a letter**. (Backend seam: `EddyApprovalNotifier` →
the `PushNotifier` binding; gated by `EDDY_PUSH_ENABLED`, off by default.)

### 3.3 Earned-urgency tiering (derived server-side)

| Catalog risk | Push tier | Channel |
|---|---|---|
| `critical` / `high` | **tier_1** | iOS Critical Alert / high-priority FCM — **reserved for capacity breaches** |
| `medium` | tier_2 | Standard |
| `low` | tier_3 | Quiet |

The app stays presentation-only — it never computes the tier. Tier-1 is rationed:
a routine `flag_barrier` is tier_3, a breach-relieving `propose_surge_plan` is tier_1.

### 3.4 Offline

The approval **decision is safety-critical → require connectivity**. If offline,
disable Approve/Deny with an explicit reason ("Reconnect to approve") rather than
queuing it; re-fetch the approval on reconnect (Reverb does not replay). Composing a
chat message offline may enqueue in the outbox; an **approval never queues**.

---

## 4. Role-aware quick actions

Seed `EddyQuickActions` from the user's role (same role switcher as the web):

| Role | Seed prompts | Typical approval tier |
|---|---|---|
| Charge nurse | "Summarize my unit's next-shift risks" · "Who's ready for discharge?" | tier_2 |
| Bed manager | "Propose bed assignments for ED boarders" · "Where's house-wide strain?" | tier_1 on a breach |
| EVS / Transport | "What's my next priority turnover?" · "Which orders breach SLA?" | tier_2 / tier_3 |

---

## 5. Security invariants (do not regress)

- **`mobile:act` gates the decision** — a read-only token (`mobile:read`) gets `403`.
- **Eddy's scoped token never reaches mobile** — it carries `ops:draft`, never
  `ops:approve`, and is not a mobile session. The human on the phone approves.
- **Inbox is user-scoped** — non-admins see only approvals they requested; admins
  see all pending Eddy approvals. (Approver-routing policy is a future refinement.)
- **No PHI in pushes**, ever; preview params are operational (unit codes/counts).
- **`FLAG_SECURE` + app-switcher blur** on the chat + approval screens; transcripts
  evicted from the local store on background-timeout.

---

## 6. Status

- **BFF + contract + PHI-free doorbell seam:** implemented in this repo (Phase 5),
  tested (`tests/Feature/Mobile/Eddy`, `tests/Unit/Eddy/EddyApprovalNotifierTest`).
- **Native screens:** to be built in `hummingbird/` against §1–§4.
- **Reverb live streaming on mobile** (foreground WS) rides the same shared bus when
  the Reverb server is provisioned (web Phase 4 / mobile [03 §3](03-architecture.md));
  until then the SSE stream above is the transport.
