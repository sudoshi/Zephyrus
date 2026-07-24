# Eddy — Making the Local Agent Path Functional (Eddy-plan Phase 3, local provider)

> **Status (2026-07-05):** WS-1 (proxy), WS-2 (config), WS-7 (serving) **DONE + verified**. **WS-3 loop +
> WS-4 dispatcher BUILT & TESTED on dev** — 6 unit + 1 live multi-turn (qwen3:8b) green, full eddy suite
> 36 passed, **gated OFF** (`EDDY_AGENT_LOCAL_ACTIONS_ENABLED=false` → inert stub). Remaining gated: run
> persistence (`ops.agent_runs`), full-stack live E2E, WS-5 queue, WS-6 policy, prod enablement.
> **Committed + pushed to main (`1e053c4`); gated OFF + not deployed.** Claude Agent SDK
> switchover is documented in the last section.
> **Parent:** [EDDY-AI-AGENT-PLAN.md](./EDDY-AI-AGENT-PLAN.md) Part F, **Phase 3 —
> Action-taking with approval + audit (write tools)**. This is the *Eddy* roadmap's
> Phase 3, **not** the Zephyrus 2.0 master plan's "P8" (Mount-Anywhere Cockpit) — two
> separate phase tracks.

## The problem

Eddy's local **agent / tool-calling** path (subsystem B) is non-functional today:

1. `eddy/app/routers/agent.py` is a **Phase-0 stub** — `actions_enabled=False`, no tools
   registered, `run_turn` returns `{"stub": true}`.
2. The configured `EDDY_AGENT_LOCAL_MODEL=qwen2.5-coder:32b` **is not pulled**, and is a
   *coder* model pressed into general tool-calling.
3. The local-agent transport `EDDY_AGENT_LOCAL_BASE_URL` points at `claude-router:8787`,
   but on this systemd host **port 8787 is `darkstar`** (the OHDSI R service), not an
   Anthropic-compatible proxy. There is no proxy at all.

Net: the same operator-facing agent can only run on the **frontier** (Claude Agent SDK →
Anthropic cloud) path; the **local, air-gapped, PHI-safe, zero-cost** path has no model,
no transport, and no loop.

## What the 2026-07-05 test established (de-risking, resolves plan risk **R8**)

Measured on the live 7900XTX (gfx1100, ROCm 7.2.4, Ollama 0.20.0):

| Finding | Value |
|---|---|
| **qwen3:8b** tool-calling vs. the real 6-action `EddyActionService::CATALOG` | **6/6 correct action, 6/6 well-formed args**, ~95 tok/s |
| qwen3.6:27b (unified candidate) | 3/3 correct, but **28 tok/s** (3.4× slower) |
| MedGemma 27B q4_0 resident VRAM | **18.84 GB** |
| qwen3:8b resident VRAM | 5.96 GB |
| Co-residence sum | **24.8 GB > 24 GB — does NOT co-reside** |
| Cold model load (disk→RAM, ~120 MB/s) | ~134 s; warm reload (RAM→VRAM) ~3 s |

**Decision (R8):** the reliable local tool-caller is **`qwen3:8b`**. Keep **MedGemma 27B**
for clinical chat. On 24 GB they cannot co-reside → use **warm-swap** (`MAX_LOADED_MODELS=1`,
~3 s warm reload, GGUFs RAM-locked), not forced co-residence.

## Workstreams

### WS-0 — Preconditions & decisions
- [ ] Caveat pass (plan §6 L142): re-confirm every backing-method name in the Part C tool
      catalog against `app/Services/{RtdcService,BedPlacementService,HuddleService}.php`.
- [ ] **R1:** add `HasApiTokens` to `App\Models\User` (additive); confirm no policy forbids
      `personal_access_tokens` on `prod.users`.
- [x] **Proxy engine:** LiteLLM (Anthropic `/v1/messages` → `ollama_chat/qwen3:8b`).
- [ ] **Queue driver (R3):** plain `queue:work` (recommended; matches `ReconcileRtdcPredictions`).

### WS-1 — Local inference proxy (the missing transport)
- [x] Port: **8788** (8787 = darkstar). Dedicated host port for the systemd host (eddy runs
      as `eddy.service`, not in the compose network, so the `claude-router:8787` service-name
      assumption doesn't apply here).
- [x] LiteLLM running as `zephyrus-eddy-router.service` (enabled) on `127.0.0.1:8788`, mapping
      `/v1/messages` (Anthropic) → `ollama_chat/qwen3:8b`. **Gotcha:** the box has only Python 3.14,
      on which LiteLLM's `orjson` dep has no wheel and fails to build — venv built on **CPython
      3.12.12 via `uv`** (`/opt/eddy-router/venv`, LiteLLM 1.91.0, config `/opt/eddy-router/config.yaml`).
- [x] `EDDY_AGENT_LOCAL_BASE_URL=http://127.0.0.1:8788` in `/etc/zephyrus-eddy.env` (+ `.env.example` note).
- [x] Proven end-to-end 2026-07-05: Anthropic `/v1/messages` + tools → proxy → qwen3:8b → **4/4
      well-formed `tool_use`** (`/tmp/eddy_proxy_test.py`). *Non-streaming path;* SSE streaming for the
      Claude Agent SDK is validated in WS-3.

### WS-2 — Model configuration (qwen3:8b)
- [x] `config/eddy.php` default `agent_local` → `qwen3:8b`.
- [x] `.env.example` `EDDY_AGENT_LOCAL_MODEL=qwen3:8b` + base-url note.
- [x] `/etc/zephyrus-eddy.env`: `EDDY_AGENT_LOCAL_MODEL=qwen3:8b`, `EDDY_AGENT_LOCAL_BASE_URL=http://127.0.0.1:8788` (backup `.bak.20260705`).
- [x] `qwen3:8b` pulled; `tools` capability confirmed.

### WS-3 — Real agent loop (replace the stub) — **loop DONE (dev/tested); persistence + SDK gated**
- [x] Local tool-loop `eddy/app/agent/local_loop.py` (`LocalAgentLoop`) over the proxy `/v1/messages`,
      **dependency-light (httpx)** — no `claude-agent-sdk` (not installable on this box; see WS-1 gotcha).
      Bounded by `max_turns`; injected `dispatcher` (DI) for testability. Frontier Claude Agent SDK path deferred.
- [x] 6 CATALOG actions registered as Anthropic tools `eddy/app/agent/tools.py` (mirrors the PHP `CATALOG`).
- [x] "Never approve" enforced **structurally**: the scoped token carries only `ops:draft`; `/agent/.../approve`
      is human-only via the ops ledger (no SDK `can_use_tool` needed for the local path).
- [x] `run_turn()` replaces the `routers/agent.py` stub, **gated by `EDDY_AGENT_LOCAL_ACTIONS_ENABLED`**
      (default off → inert stub, unchanged behavior).
- [x] Tests: 6 unit (`respx`) + **1 LIVE multi-turn** (qwen3:8b through the proxy — model finalizes after
      the tool_result, no runaway) in `eddy/tests/test_local_agent_loop.py`; **full eddy suite 36 passed / 1 skipped**.
- [ ] Persist runs to `ops.agent_runs`/`ops.agent_tool_calls` (currently an in-memory session registry) — GATED.
- [ ] Full-stack live E2E (dev eddy running this code → real Laravel callback → real `ops.actions` draft) — GATED.

### WS-4 — Scoped-token handshake + write execution — **dispatcher DONE; live E2E gated**
- [x] `make_laravel_dispatcher` POSTs each tool call to the EXISTING `/api/eddy/agent/actions/propose` with the
      `ops:draft` bearer (**never** `ops:approve`); payload mapping unit-tested. Mint endpoint
      `/api/eddy/agent/token` + the propose controller pre-existed (Phase 3, PHPUnit-covered).
- [ ] Live: real scoped token → real `ops.actions` draft + `ops.approvals` pending (Laravel side already tested;
      the end-to-end wiring against a running dev stack is GATED).
- [ ] Dry-run preview; human approve → backing service executes; override recorded.

### WS-5 — Async runtime (R3) — **gated**
- [ ] `queue-work.service` (prod) / `database` queue (dev); verify `systemctl status reverb queue-work eddy`.

### WS-6 — Provider policy / routing
- [ ] `ops_agent` surface policy → local profile, `transport=ollama_chat`/proxy, `entitlement=local`
      (validation already in `EddyProviderPolicyService`).

### WS-7 — Serving / VRAM (from the test)
- [x] `OLLAMA_KV_CACHE_TYPE=q8_0` + `OLLAMA_KEEP_ALIVE=-1` on `ollama.service`.
- [ ] `vmtouch`-lock MedGemma + qwen3:8b GGUFs in RAM (warm swap ≈ 3 s).
- [ ] Move Ollama blobs to fast/dedicated NVMe (model-load I/O measured ~120 MB/s → 134 s cold loads).

### WS-8 — Tests & evals — **loop tests DONE; evals gated**
- [x] `eddy/tests/test_local_agent_loop.py` — 6 unit (respx) + 1 opt-in live (`EDDY_LIVE_PROXY_TEST=1`).
- [ ] Promote `/tmp/eddy_toolcall_smoke.py` into the repo as the **R8 tool-call reliability eval** (CI gate).
- [ ] Port `approved-action.spec`, `override.spec`; evals `no_write_tools`, `phi_minimized`.
- [ ] Assert Eddy **cannot self-approve**.

### WS-9 — Deploy & runbook — **gated**
- [ ] systemd units + deploy notes for the new host port; update Part F living status table.

**Dependency spine:** WS-0 → WS-1 → WS-2 → (WS-3 ∥ WS-6) → WS-4 → WS-5 → WS-8 → WS-9.
WS-7 independent. **`[x]` = done 2026-07-05; gated items await explicit go (agent-surface + scoped-token code).**

---

## Switching the agent runtime to the Claude Agent SDK

The shipped local loop (`LocalAgentLoop`, WS-3) is a **dependency-light** direct
Anthropic-Messages tool-loop. The Eddy plan's north-star for subsystem B is the
**Claude Agent SDK** (plan §4.4) as the *default* runtime. They are **not**
exclusive — because of the proxy, the **provider** (which model) and the **runtime**
(which loop) are *independent axes*:

|                          | Local model (`qwen3:8b`)            | Frontier (`claude-opus-4-8`)         |
|--------------------------|-------------------------------------|--------------------------------------|
| **Direct loop** (shipped)| ✅ via proxy `/v1/messages`         | possible (point at Anthropic direct) |
| **Claude Agent SDK** (§4.4)| ✅ `ANTHROPIC_BASE_URL`=proxy     | ✅ `ANTHROPIC_API_KEY`, default base |

`zephyrus-eddy-router.service` (WS-1) is the pivot: the SDK speaks the Anthropic
Messages API, so pointing `ANTHROPIC_BASE_URL` at the proxy makes the SDK drive the
**local** `qwen3:8b` with the *same* code it uses for the cloud. **Switching provider
is a config act; switching runtime is a code act** — keep them separate.

### Why switch (what the SDK buys)

- Native **`can_use_tool` permission gating** (§4.4.3): a per-tool-call approval hook —
  a stronger, in-loop version of Eddy's structural "propose-only" (the direct loop
  enforces that only via the scoped token lacking `ops:approve`).
- First-class **streaming** of assistant deltas + tool events → Reverb (§4.4.4), for the
  dock/Hummingbird, vs. the direct loop's single synchronous return.
- **Session management, subagents, MCP tools, budget/turn controls** out of the box —
  `eddy_agent_max_turns` / `eddy_agent_max_budget_usd` / `eddy_agent_approval_timeout_seconds`
  already exist in `eddy/app/config.py` for exactly this.
- The **frontier model** (`claude-opus-4-8`, effort `xhigh`) for hard multi-step
  reasoning beyond qwen3:8b — the plan's default.

### Switchover — concrete steps (WS-3b: SDK runtime)

- [ ] **Install** `claude-agent-sdk>=0.2.86` + `anthropic>=0.69.0` (both already declared
      in `eddy/requirements.txt`, lazy-imported) into the eddy venv. **Blocker:** the same
      Python-3.14 `orjson` build failure that hit LiteLLM — build the eddy venv on **CPython
      3.12 via `uv`** (mirror `/opt/eddy-router/venv`) or wait for 3.14 wheels. This is *why*
      the shipped loop is dependency-light.
- [ ] **Harness** `EddyAgentService` per plan §4.4.2 `_options()` — build
      `ClaudeAgentOptions(model=…, system_prompt=AGENT_SYSTEM_PROMPT, max_turns=…, …)`.
- [ ] **Tools:** register the 6 `eddy/app/agent/tools.py` actions as SDK tools via `@tool`
      + `create_sdk_mcp_server` (in-process MCP); each handler calls the EXISTING
      `make_laravel_dispatcher(...)` — reuse the exact dispatcher the direct loop uses so the
      Laravel scoped-token callback + audit trail are unchanged.
- [ ] **Approval gate** `_make_can_use_tool()` (§4.4.3): deny write tools until the human
      approves (the approval-future); never auto-approve.
- [ ] **Streaming** `run_turn()` (§4.4.4): stream SDK events → Reverb channel
      `eddy.session.{uuid}` (PHI-free) → dock/Hummingbird.
- [ ] **Runtime selector:** add `EDDY_AGENT_RUNTIME=direct_loop|claude_sdk` (default
      `direct_loop`). `eddy/app/routers/agent.py` picks the runtime; the **provider** (local
      vs frontier) stays an `EddyProviderPolicyService` decision (WS-6), not a code branch.

### Provider selection (the env the SDK sees)

| Target | `ANTHROPIC_BASE_URL` | model | key | gate |
|---|---|---|---|---|
| **Local** (PHI-safe, free) | `http://127.0.0.1:8788` (proxy) | `qwen3:8b` | dummy/none | always allowed |
| **Frontier** (cloud) | *(unset → Anthropic)* | `claude-opus-4-8` | `ANTHROPIC_API_KEY` | BAA + gates ↓ |

Knobs already in `config.py`: `eddy_agent_local_base_url` / `eddy_agent_local_model`
(local); `eddy_agent_model` / `eddy_agent_effort` / `anthropic_api_key` (frontier);
`eddy_agent_max_turns` / `_max_budget_usd` / `_approval_timeout_seconds` (both).

### Guardrails that MUST survive the switch

- **PHI-egress is authoritative and runs first.** Frontier requires ALL of:
  `EDDY_ALLOW_CLOUD=true` **and** surface policy `allow_cloud=true` **and** profile
  `patient_level_context_allowed=true` (BAA). The runtime switch must never bypass
  `EddyProviderPolicyService`; local `qwen3:8b` stays the default for any patient-level /
  PHI context.
- **Propose-only holds either way.** Direct loop or SDK, tools DRAFT via the `ops:draft`
  scoped token; `ops:approve` is never minted; approval stays human via the ops ledger. The
  SDK's `can_use_tool` is an *additional* in-loop gate, not a replacement for token scoping.
- **Same catalog + dispatcher.** Both runtimes share `eddy/app/agent/tools.py` and
  `make_laravel_dispatcher` → identical Laravel callback + `ops.*` audit trail.

### Recommended posture

Keep **local `qwen3:8b` via the direct loop** as the default (zero-dep, PHI-safe, ~95 tok/s,
no cloud cost). Bring up the **Claude Agent SDK** for the **frontier** path
(`claude-opus-4-8`) where its streaming + `can_use_tool` + deeper reasoning earn their keep,
BAA-gated. Optionally let the SDK also drive **local** via the proxy for one unified runtime —
at the cost of the heavier dependency + the 3.12 venv. The proxy already makes all four
quadrants reachable; the **policy + gates** decide which is used, per surface/role/tenant.
