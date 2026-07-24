# Eddy — Abby Teardown Evidence (raw analysis maps)

> Companion to `docs/plans/EDDY-AI-AGENT-PLAN.md`. These are the nine evidence-based teardown maps (six over Parthenon's Abby, three over Zephyrus) produced by the deep-analysis workflow, with `file:line` citations. The plan is built on these; consult them when an implementing agent needs the exact mechanism, signature, or table column behind a plan statement.


## Index

- **A1** — Abby AI service — provider routing & Claude Agent SDK core
- **A2** — Abby agentic copilots, tool packs, registry & Reverb streaming
- **A3** — Abby agency / action-taking engine + orchestrator guards
- **A4** — Abby memory, RAG, live-context, knowledge capture/surfacing
- **A5** — Abby Laravel backend — proxy, scoped tokens, provider/surface policy, models, migrations
- **A6** — Abby frontend UX/UI inventory
- **Z1** — Zephyrus backend domain services & process surfaces
- **Z2** — Zephyrus frontend layouts, navigation, design system
- **Z3** — Zephyrus infra/config/realtime/auth/deploy

---


<br>

# A1 — Abby AI service — provider routing & Claude Agent SDK core

I now have complete, evidence-based coverage of both subsystems. Here is the architecture map.

---

# Abby AI Service — Provider Routing & Claude Agent SDK Core

**Scope:** `Parthenon/ai` (FastAPI service `app/main.py`, title `"Parthenon AI Service"`, version `0.1.0`). Two distinct LLM subsystems coexist: (A) **Abby chat** — a provider-neutral chat router defaulting to local Ollama/MedGemma with optional cloud (`/abby/*`); (B) the **Claude Agent SDK loop** — a tool-using, approval-gated agent (`/agent/*`) that runs on Anthropic cloud (EE) or a local Anthropic-compatible proxy (CE). They share `app/config.py` settings and the `app.routing.provider_profiles` profile abstraction but are otherwise independent code paths.

---

## 1. LOCAL vs FRONTIER decision

Two separate decision systems.

### A. Abby chat (`/abby/chat`, `/abby/chat/stream`) — capability-driven route
Entry: `decide_abby_chat_route()` (`provider_profiles.py:464`), called via `_resolve_abby_chat_route()` (`abby.py:362`). Inputs: a `RuleRouter`, budget state, `cloud_client_available` callback, surface + required capabilities.

Effective **mode** is resolved by `resolve_abby_chat_policy()` (`provider_profiles.py:374`): explicit `abby_chat_provider_mode` env wins; else `auto_by_complexity` if `abby_cloud_routing_enabled` else **`local_only`** (the shipped default — `abby_cloud_routing_enabled: bool = False`, `config.py:49`). Modes (`ROUTING_STRATEGIES`, `provider_profiles.py:46`): `local_only`, `cloud_only`, `local_first`, `cloud_first`, `auto_by_complexity`, `auto_by_budget`, `disabled`.

Decision ladder (`provider_profiles.py:522-574`): `disabled`→local; `budget_exhausted`→local; `local_only`→local; no cloud profile / cloud capability fail (`cloud_capability_ok`, line 510) → `local("unsupported_capability")`; `cloud_only`/`cloud_first` with key+client → cloud (`RoutingDecision(model="claude")`); otherwise **`RuleRouter.route()`** (`rule_router.py:101`) scores the message — Stage 1 keyword/length rules then Stage 2 complexity scoring (`_CLOUD_SCORE_PER_COMPLEXITY=0.20`, `_LOCAL_SCORE_PER_SIMPLICITY=0.30`, `_CLOUD_TIEBREAKER=0.05`) returning model `"claude"` or `"local"`. The legacy `model` string is **`"claude"` | `"local"`** regardless of which actual cloud transport is chosen.

Runtime fallbacks **force local** even after a cloud decision: cloud-safety filter blocked context (`abby.py:2426`, `:3012`), PHI detected (`abby.py:2488`, `:3039`), or any `ChatAdapterError`/exception from the cloud adapter (`abby.py:2543-2575`) — each calls `force_local_abby_route()` (`provider_profiles.py:577`) with a reason from `_FALLBACK_REASONS` (`abby.py:383`).

**Per-request override:** Laravel may pass `provider_policy` in the `ChatRequest` body. `_effective_chat_config()` (`abby.py:151`) materializes a `SimpleNamespace` config from `settings.model_dump()` overlaid with `provider_type` ∈ `{ollama, anthropic, openai, deepseek/mistral/moonshot/qwen}` (`_OPENAI_COMPATIBLE_BASE_URLS`, `abby.py:109`), profile_id, mode, model, and per-policy api_key/budget. This is the seam by which an admin DB row (not env) drives provider selection per turn.

### B. Agent loop (`/agent/*`) — explicit provider switch
`settings.resolve_agent_provider(profile_provider, request_provider)` (`config.py:227`). Precedence: **`request_provider`** (Laravel `agents.provider_mode`, passed as `CreateSessionRequest.provider`) > `profile.provider` > global `agent_provider` env (default `"anthropic"`, `config.py:168`). Returns `ResolvedAgentProvider` NamedTuple (`config.py:6`): `provider` (`"anthropic"|"local"`), `model`, `effort`, `base_url`, `auth_token`, `actions_enabled`. `local` → `agent_local_model`/`agent_local_effort`/`agent_local_base_url`/`agent_local_auth_token`/`agent_local_actions_enabled`; `anthropic` → `agent_model`/`agent_effort`, no transport override, `actions_enabled=True`.

---

## 2. claude-agent-sdk usage

**Pinned:** `claude-agent-sdk>=0.2.86` (`requirements.txt:23`); authored against **0.2.86** (`service.py:9`). Also `anthropic>=0.42.0` (`requirements.txt:22`) used independently by `ClaudeClient`.

**Imports** (`service.py:28-38`): `AssistantMessage, ClaudeAgentOptions, ClaudeSDKClient, PermissionResultAllow, PermissionResultDeny, ResultMessage, TextBlock, ToolUseBlock, create_sdk_mcp_server`; plus `tool` decorator (`abby_tools.py:17`).

**Options build** — `ParthenonAgentService._options()` (`service.py:186-251`) constructs `ClaudeAgentOptions(...)` with:
- `system_prompt=profile.system_prompt`, `model=resolved.model`, `effort=cast(EffortLevel, resolved.effort)` (`EffortLevel = Literal["low","medium","high","xhigh","max"]`, `service.py:49`).
- `mcp_servers={"parthenon": create_sdk_mcp_server(name="parthenon", version="1.0.0", tools=tools)}`.
- `tools=[]` — **all built-in Claude Code tools removed** (Bash/Read/Edit/Write) for HIGHSEC lockdown.
- `allowed_tools=[f"mcp__parthenon__{t.name}" ...]` — only **read** tools (auto-approved). Writes excluded so the CLI routes them to `can_use_tool`.
- `setting_sources=[]`, `strict_mcp_config=True`, `max_turns=settings.agent_max_turns` (24), `max_budget_usd=settings.agent_max_budget_usd` (5.0), `resume=state.anthropic_session_id` (session continuity).
- `permission_mode`: **`"default"`** when profile has write tools (required for `can_use_tool` to fire) else **`"dontAsk"`** (headless auto-deny). 
- **Local provider redirect** (`service.py:239`): `kwargs["env"] = {"ANTHROPIC_BASE_URL": resolved.base_url, "ANTHROPIC_AUTH_TOKEN": resolved.auth_token}` — merged into the CLI subprocess env, pointing at `agent_local_base_url` (`http://claude-router:8787`, an Anthropic-compatible proxy / claude-code-router/LiteLLM → Ollama).
- **CE actions-disabled hardening** (`service.py:201`): when `local` + `not actions_enabled`, write tools are **removed entirely** from the MCP server (not merely un-gated) so they cannot be auto-approved under `dontAsk`.

**`can_use_tool` hook** — `_make_can_use_tool()` (`service.py:116-184`): async `_can_use(tool_name, input, ctx)` → `PermissionResultAllow | PermissionResultDeny`. Strips `mcp__parthenon__` prefix; reads → Allow (fail-open); unknown → Deny (fail-closed); writes → publishes `agent.approval.request` to Reverb, then `await asyncio.wait_for(asyncio.shield(fut), timeout=agent_approval_timeout_seconds=600)` on an `asyncio.Future` keyed by `ctx.tool_use_id`. The future is resolved by `resolve_approval()` (`service.py:98`) called from `POST /agent/sessions/{id}/approve`. Approve→Allow, reject/timeout→Deny + `agent.approval.denied`.

**Streaming** — `run_turn()` (`service.py:273`): `async with ClaudeSDKClient(options=...) as client: await client.query(text); async for message in client.receive_response():`. `AssistantMessage.content` blocks → `TextBlock` emits `agent.text.delta`, `ToolUseBlock` emits `agent.tool.start`. `ResultMessage` carries `session_id`, `total_cost_usd`, `usage.{input,output}_tokens`, `is_error`; emits `agent.turn.done` (per-turn deltas) and persists to Laravel via `LaravelPersister` (POST to `state.ingest_path`).

**Tools** are MCP tools via the `@tool(name, description, input_schema)` decorator (`abby_tools.py:40`) — thin authenticated httpx clients over Laravel `/api/v1/*` routes (`tool_base.py:request`), results shaped as `{"content":[{"type":"text","text": json}]}` (`tool_base.py:44`). Profiles/writes: `study_design` (no writes), `publish` (`update_draft, create_snapshot`), `abby` (`evaluate_gates, reproject_results, build_study_package, open_in_publisher`) — `tool_packs.py:29`.

---

## 3. Ollama invocation & unified adapter

**Unified abstraction:** all providers implement `async chat(ChatAdapterRequest) -> ChatAdapterResponse` and `async stream(...) -> AsyncGenerator[ChatStreamEvent]` (`chat_adapters.py`). Adapter classes: `OllamaChatAdapter` (`:68`), `AnthropicMessagesAdapter` (`:275`), `OpenAIResponsesAdapter` (`:400`), `OpenAICompatibleChatAdapter` (`:503`). `ChatAdapterRequest{system_prompt, message, history, temperature=0.1, max_output_tokens}`; `ChatAdapterResponse{reply, provider, transport, model, tokens_in, tokens_out, cost_usd, latency_ms, request_hash, raw}`; `ChatStreamEvent{kind ("token"|"complete"|"error"|"metadata_error"), token, payload}`.

**Ollama specifics** (`OllamaChatAdapter`): POSTs `{base_url}/api/chat` with `{"model", "messages", "stream": False|True, "think": False, "keep_alive", "options":{"temperature","num_predict"}}` (`chat_adapters.py:108`, `:204`). History trimmed to last 10. First attempt 180s timeout (cold model load), retries 60s, `max_retries=2`. Streaming reads NDJSON `aiter_lines()`, `done` terminates. `app/routers/abby.py` wraps it: `call_ollama()` (`:1210`) and `_stream_ollama()` (`:2671`) build the adapter from the local `ProviderProfile` via `build_default_provider_profiles(config)[local_profile_id]`. Reasoning-token stripping (`_strip_thinking_tokens`, `abby.py:2074`) handles `<think>`/`<unused94>` from MedGemma/Qwen; truncation-retry logic (`_retry_local_visible_reply`).

**Model names:** default `puyangwang/medgemma-27b-it:q4_0` (27B, `ollama_model`/`abby_ollama_model`); low-resource fallback `MedAIBase/MedGemma1.5:4b` (`abby_local_chat_4b_model`); aliases via `abby_model_aliases` (`medgemma:27b=...`, parsed by `parse_model_aliases`, `provider_profiles.py:183`). Agent-local (tool-calling) model is **NOT MedGemma** — `agent_local_model: "qwen2.5-coder:32b"` (`config.py:174`). The agent loop never uses `OllamaChatAdapter`; it reaches Ollama only through the Anthropic-compatible proxy.

---

## 4. Cost / usage tracking

`CostTracker` (`cost_tracker.py:19`), writes to table **`app.abby_cloud_usage`** (`record_usage`, `:60`). Columns inserted (`cost_tracker.py:93`): `user_id, department, tokens_in, tokens_out, cost_usd, model, request_hash, sanitizer_redaction_count, route_reason, provider, transport, provider_profile_id, entitlement_type, request_surface, status, error_class, fallback_reason, response_latency_ms, usage_metadata (jsonb)`. `record_route_decision()` (`:143`) logs zero-cost local/fallback routing decisions (status `routed_local`/`fallback_local`). Reads: `get_monthly_spend()` (SUM(cost_usd) for current month, optional provider/profile/surface/entitlement/department filters), `is_budget_exhausted()` (spend ≥ budget × `cutoff_threshold`), `get_triggered_alerts()`, `get_budget_status()`. Budget config: `cloud_monthly_budget_usd=500.0`, `cloud_budget_alert_thresholds=[0.50,0.80,0.95]`, `cloud_budget_cutoff_threshold=0.95`, plus per-provider `anthropic_monthly_budget_usd`/`openai_*`. Calibration: `get_routing_labels()`/`get_routing_label_count()` join `app.abby_feedback` ⨝ `app.abby_messages` (≥500 to train a classifier).

**Cost numbers:** Anthropic cost from `ClaudeClient.estimate_cost()` (`claude_client.py:149`) via `PRICING` table (`claude-sonnet-4-20250514` 3.0/15.0, `claude-opus-4-6` 15.0/75.0, default Sonnet). OpenAI/compatible adapters return `cost_usd=0.0` (operator must set per-mtok pricing in profile `limits`). Agent-loop cost comes straight from `ResultMessage.total_cost_usd` (SDK-computed), persisted to Laravel `agent_sessions`, **not** to `abby_cloud_usage`. `record_usage` called from `/abby/chat` (`abby.py:2519`) and `_stream_claude_response` (`abby.py:2883`).

---

## 5. FastAPI app shape

Routers mounted via `OPTIONAL_ROUTERS` import loop (`main.py:56-85`, ImportError-tolerant): `health` always; `/abby`, `/agent`, plus `/embeddings /concept-mapping /clinical-nlp /profiling /circe /ariadne /text-to-sql /wiki /chroma /gis /study-agent /orchestrate /genomics /patient-similarity` etc. CORS restricted to `cors_origins_list`. Lifespan warms embedders + Ollama (`_startup_warm_ollama`, `main.py:144`).

**`/abby` endpoints:** `GET /provider-health` (`abby.py:1250`), `GET /model-inventory` (`:1325`), `POST /parse-cohort` (`CohortParseRequest`→`CohortParseResponse`, `:1374`), `POST /chat` (`ChatRequest`→`ChatResponse`, `:2403`), `POST /chat/stream` (SSE, `:2940`), `POST /execute-plan`. **Chat models** (`abby.py:777-815`): `ChatRequest{message, page_context, page_data, history:[ChatMessage{role,content}], user_profile, user_id, conversation_id, provider_policy}`; `ChatResponse{reply, suggestions, routing:dict, confidence, sources}`. `routing` dict from `_routing_payload()` (`:443`) carries legacy `model/reason/stage` plus `provider/transport/model_name/profile_id/entitlement/routing_strategy/fallback_profile_ids/capabilities/cloud_safety_*`.

**SSE mechanics:** `StreamingResponse(media_type="text/event-stream", headers={Cache-Control:no-cache, Connection:keep-alive, X-Accel-Buffering:no})`. Frame format `data: {json}\n\n` with payloads `{"token":...}`, `{"suggestions":[...]}`, `{"sources":[...]}`, `{"error":...}`, terminated by `data: [DONE]\n\n` (`_stream_ollama` `:2671`, `_stream_claude_response` `:2805`, `_stream_chat_response` `:2928`).

**`/agent` endpoints** (`routers/agent.py`): `POST /sessions` (`CreateSessionRequest{profile, agent_session_id, subject_id, channel, ingest_path, scoped_token, context, provider}` → `{agent_session_id, channel, provider, actions_enabled}`); `POST /sessions/{id}/turn` (202, `TurnRequest{text, idempotency_key}`, runs in `BackgroundTasks`, 429 on busy); `POST /sessions/{id}/approve` (`ApproveRequest{tool_use_id, approved}`). In-memory session registry (`registry.py`, single-worker), `Semaphore(agent_max_concurrent_turns=4)`, per-session `asyncio.Lock`. Agent output is fanned out over **Reverb** (Pusher protocol) via `ReverbPublisher.publish(channel, event, data)` (`reverb_publisher.py:58`) — events `agent.turn.start/text.delta/tool.start/approval.request/approval.denied/turn.done/error`. `GET /health` (`routers/health.py`) reports Ollama status only.

---

## 6. Env / config keys (`app/config.py`)

- **Anthropic chat:** `claude_api_key` (`key_ref="CLAUDE_API_KEY"`), `claude_model="claude-sonnet-4-20250514"`, `claude_max_tokens=4096`, `claude_timeout=60`, `claude_input_price_per_mtok=3.0`, `claude_output_price_per_mtok=15.0`.
- **Agent SDK:** `agent_provider="anthropic"`, `agent_model="claude-opus-4-7"`, `agent_effort="xhigh"`, `agent_max_turns=24`, `agent_max_budget_usd=5.0`, `agent_max_concurrent_turns=4`, `agent_approval_timeout_seconds=600`; local: `agent_local_base_url="http://claude-router:8787"`, `agent_local_model="qwen2.5-coder:32b"`, `agent_local_auth_token="local"`, `agent_local_actions_enabled=False`, `agent_local_effort="medium"`. (Note: agent-loop CLI reads `ANTHROPIC_API_KEY` from its own process env, not `claude_api_key`.)
- **Ollama:** `ollama_base_url="http://host.docker.internal:11434"`, `ollama_model`, `ollama_timeout=120`, `ollama_num_predict=256`; Abby-specific `abby_ollama_base_url`, `abby_ollama_model`, `abby_ollama_keep_alive=3600`, `abby_local_chat_profile_id="local-medgemma"`, `abby_local_chat_4b_profile_id`/`_model`, `abby_model_aliases`, `abby_warmup_on_startup=False`.
- **Abby routing flags:** `abby_cloud_routing_enabled=False`, `abby_chat_provider_mode=""`, `abby_chat_default_profile_id`, `abby_chat_fallback_profile_ids`, `abby_cloud_chat_profile_id="anthropic-claude"`, `abby_cloud_entitlement="org_api_key"`.
- **OpenAI / compatible:** `openai_api_key`, `openai_model="gpt-5.5"`, `openai_base_url`, `openai_*_price_per_mtok=0.0`; `openai_compatible_api_key/_model/_base_url`.
- **Governance / budget:** `phi_detection_enabled=True`, `phi_block_on_detection=True`, `cloud_monthly_budget_usd=500.0`, `cloud_budget_alert_thresholds=[0.50,0.80,0.95]`, `cloud_budget_cutoff_threshold=0.95`, `anthropic/openai/openai_compatible_monthly_budget_usd`.
- **Infra:** `database_url` (required), `redis_url`, `reverb_app_id/key/secret/host/port/scheme`, `agency_api_base_url="http://nginx:80"`, `chroma_host/port`.

---

## 7. Parthenon-specific vs generalizable seam (for a hospital-ops port)

**Will NOT carry over (OHDSI/OMOP/study-gating domain):**
- Agent **profiles** (`agents/profiles.py`): `STUDY_DESIGN`, `PUBLISH`, `ABBY` system prompts are entirely OMOP CDM / observational-study / 7-gate / STROBE-RECORD / PICO. The `abby` agent's write tools (`evaluate_gates, reproject_results, build_study_package, open_in_publisher`) are study-publication actions.
- All **page system prompts** and `CONTEXT_HELP_KEYS` (`abby.py:897-1152`): ~40 OMOP surfaces (cohort_builder, estimation, incidence_rates, phenotype_library, …). 
- `/abby/parse-cohort` and `SYSTEM_PROMPT_COHORT_PARSER` (`abby.py:819`) — NL→OMOP cohort spec.
- `CAPABILITY_PREAMBLE` (`abby.py:864`): hardcoded OMOP schemas (`vocab`, `omop`, `pancreas`, `mimiciv`, `temp_abby`), `abby_analyst` role, `knowledge_cdm_schema="omop"`. RuleRouter Stage-2 indicators (`propensity`, `hazard_ratio`, `kaplan-meier`) are research-specific. MCP tools call OMOP-specific Laravel routes (`studies/{slug}/...`).
- `patient_level_local_only` capability + cloud-safety/PHI block are tuned to "no patient-level data to cloud" research governance.

**Generalizable seam (port these intact):**
- `app/routing/provider_profiles.py` — `ProviderProfile`, `AbbyChatPolicy`, `decide_abby_chat_route`, capability/entitlement/transport/strategy enums. Provider-neutral by design.
- `app/routing/chat_adapters.py` — the 4 adapter classes + `ChatAdapterRequest/Response/StreamEvent` + error classification. Zero domain knowledge.
- `app/routing/cost_tracker.py` + `app.abby_cloud_usage` schema — generic per-provider budget/circuit-breaker (rename table; drop `department`-as-research-chargeback if unused).
- `app/routing/rule_router.py` — keep the two-stage shell; **replace** `_COMPLEXITY_INDICATORS` with ops vocabulary.
- `app/agents/service.py` + `tool_base.py` + `tool_packs.py` + `registry.py` + `reverb_publisher.py` — the **entire Claude Agent SDK harness** (options build, `can_use_tool` approval gating, EE/CE provider switch via `resolve_agent_provider`, Reverb fan-out, Laravel persistence) is domain-agnostic. A hospital-ops port supplies new `AgentProfile` system prompts + new `@tool` packs over its own Laravel routes; the SDK plumbing, `permission_mode` logic, and local-proxy redirect are reusable verbatim.
- `config.py:resolve_agent_provider` + `_effective_chat_config` — the EE/CE + per-request-admin-override seams.

---


<br>

# A2 — Abby agentic copilots, tool packs, registry & Reverb streaming

I now have complete end-to-end evidence. Producing the deliverable.

---

# Abby Agentic Copilots — Architecture Map (Parthenon `ai/app/agents/` + `backend/`)

A three-tier system: **Laravel** (session ownership, auth, telemetry) → **python-ai FastAPI harness** (model-agnostic agent loop) → **Claude Agent SDK CLI subprocess** (model + tool execution), with **Reverb (Pusher protocol)** streaming events back to the React frontend. The harness is fully domain-agnostic; all OHDSI/OMOP specifics live in the per-profile tool packs and Laravel routes.

## 1. Agent/copilot definition & registration

Two distinct registries.

**Profile registry** (`profiles.py`): an `AgentProfile` frozen dataclass (`profiles.py:58-68`) = `name`, `system_prompt`, `model`, `effort`, optional `provider`. Three profiles instantiated at import (`profiles.py:71-90`): `STUDY_DESIGN`, `PUBLISH`, `ABBY` — all default `model=settings.agent_model` (`claude-opus-4-7`, `config.py:157`) / `effort="xhigh"`. Looked up by `get_profile(name)` from `_PROFILES` dict (`profiles.py:92-96`). Each profile's `system_prompt` is a long Parthenon-specific clinical brief (Abby's is the 7-gate orchestrator persona, `profiles.py:38-55`).

**Tool-pack builder registry** (`tool_packs.py`): `_BUILDERS: dict[str, Callable[[AgentToolContext], list]]` (`tool_packs.py:21-25`) maps profile → `<feature>_tools.build_tool_pack`. `build_tool_pack(profile, ctx)` (`tool_packs.py:46-48`) dispatches; `register()` allows runtime overwrite (`tool_packs.py:41-43`). A parallel `_WRITE_TOOLS: dict[str, set[str]]` (`tool_packs.py:29-33`) declares per-profile gated writes — `study_design: set()` (read-only), `publish: {update_draft, create_snapshot}`, `abby: {evaluate_gates, reproject_results, build_study_package, open_in_publisher}`; `write_tools(profile)` returns it (`tool_packs.py:36-38`).

**Per-surface = per-profile.** No class hierarchy of agents; one generic `ParthenonAgentService` (`service.py:93`) drives every profile. The router (`agent.py`) and service are domain-blind — Laravel supplies `profile`, `channel`, `ingest_path`, `context`.

## 2. Tools & tool packs

**Tool base** (`tool_base.py`): `AgentToolContext` frozen dataclass = `auth_token: str` + `context: dict` id-bag (`tool_base.py:26-36`). Helpers: `api_url(path)` → `{base}/api/v1/{path}` (`tool_base.py:39-41`); `text_result(payload)` wraps as MCP `{"content":[{"type":"text","text": json(...)[:20000]}]}` (`tool_base.py:44-45`); `error_result(msg)` adds `is_error: True` (`tool_base.py:48-49`); `request(ctx, method, path, *, params, json_body)` — the single seam: authenticated httpx call (`Bearer {ctx.auth_token}`) to a Laravel route, unwraps `body["data"]`, returns 400+ as `error_result` (`tool_base.py:52-80`).

**Tool definition shape:** each tool is a closure inside `build_tool_pack(ctx)`, decorated with the SDK's `@tool(name, description, input_schema)` where the schema is a dict of `{arg: python_type}` (e.g. `{"study_id": int}`, `{"publishable_only": bool}`, `{"drafts": list}`). Tools close over `ctx` to read ids from `ctx.context[...]`. **A tool pack = the list returned by `build_tool_pack`.** Concrete examples:

- `get_gate_status` (`abby_tools.py:62-73`) — `{}` schema, `GET studies/{slug}/gates`; reads the 7-stage gate ledger. Read (auto-approved).
- `get_study_results` (`abby_tools.py:75-90`) — `{"publishable_only": bool}`, `GET studies/{slug}/results`. Read.
- `evaluate_gates` (`abby_tools.py:106-118`) — `{}`, `POST studies/{slug}/gates/evaluate`. **Write (gated).**
- `update_draft` (`publish_tools.py:99-118`) — `{"document_json": dict, "title": str}`, `PATCH publish/drafts/{id}`. **Write (gated).**
- `search_concepts` (`study_design_tools.py:48-59`) — `{"query","domain","vocabulary","limit"}`, `GET vocabulary/search`. Read.

Guards: `_require_study` (`abby_tools.py:24-30`), `_require_version` (`study_design_tools.py:31-42`) return `error_result` when the context id is absent.

## 3. Agent run loop & approval gating

`ParthenonAgentService.run_turn(state, text)` (`service.py:273-336`):

1. `emit("agent.turn.start", {agent_session_id})`.
2. Opens `ClaudeSDKClient(options=self._options(state))` (`service.py:280`), `client.query(text)`, iterates `client.receive_response()` (`service.py:282`).
3. For each `AssistantMessage`: `TextBlock` → `emit("agent.text.delta", {text})` (`service.py:287`); `ToolUseBlock` → `emit("agent.tool.start", {name, input})` (`service.py:290`).
4. On `ResultMessage`: captures `session_id` → `state.anthropic_session_id` (`service.py:292`), `total_cost_usd`, `usage.input_tokens/output_tokens`. If `is_error` **or** (no output and zero cost): `emit("agent.error", …)` + persist `status="error"` (`service.py:304-312`); else `emit("agent.turn.done", {cost_usd, tokens_in, tokens_out, anthropic_session_id})` + persist `status="active"` (`service.py:317-329`). Per-turn deltas only (Laravel accumulates).
5. Any exception → `emit("agent.error", {message[:500]})` + best-effort persist (`service.py:330-336`).

**Options assembly** `_options()` (`service.py:186-251`): builds tools via `build_tool_pack`, registers an MCP server `create_sdk_mcp_server(name="parthenon", version="1.0.0", tools=tools)` (`service.py:205`). Read tools → `allowed_tools=["mcp__parthenon__{name}", …]` (auto-approved); write tools deliberately **excluded** from `allowed_tools` so the CLI routes them through `can_use_tool`. `tools=[]` strips all built-in Claude Code tools (Bash/Read/Edit) for HIGHSEC; `setting_sources=[]`, `strict_mcp_config=True`. If writes exist → `permission_mode="default"` + `can_use_tool` callback; else `permission_mode="dontAsk"` (`service.py:245-249`). `max_turns=24`, `max_budget_usd=5.0` (`config.py:159-160`), `resume=state.anthropic_session_id`.

**Approval gating** `_make_can_use_tool` (`service.py:116-184`): precomputes `read_names`/`write_names`. The async `_can_use(tool_name, input, ctx)`:
- strips `mcp__parthenon__` prefix;
- read → `PermissionResultAllow()` (fail-open, `service.py:142`);
- unknown → `PermissionResultDeny` (**fail-closed**, `service.py:146`);
- write → derives `tool_use_id` (from `ctx.tool_use_id`), creates an `asyncio.Future` in `state._pending[tool_use_id]`, **publishes `agent.approval.request` {tool_use_id, tool, input}** (`service.py:158-162`), then `await asyncio.wait_for(asyncio.shield(fut), timeout=settings.agent_approval_timeout_seconds)` (600s, `config.py:162`). Approve → `Allow`; deny/timeout → publish `agent.approval.denied` + `PermissionResultDeny`.

The future is resolved out-of-band by `resolve_approval(tool_use_id, approved)` (`service.py:98-114`), which scans `registry._sessions` for the matching pending future and `fut.set_result(approved)`. Invoked by `POST /agent/sessions/{id}/approve` (`agent.py:108-121`).

**Event types:** `agent.turn.start`, `agent.text.delta`, `agent.tool.start`, `agent.turn.done`, `agent.error`, `agent.approval.request`, `agent.approval.denied`.

## 4. Streaming via Reverb

`reverb_publisher.py`: `ReverbPublisher` wraps the official `pusher` client (Reverb is Pusher-compatible). Lazy client build `_build_default_client()` (`reverb_publisher.py:24-32`) using `reverb_app_id/app_key/app_secret/host (default "reverb")/port (8080)/scheme` (`config.py:185-190`); `ssl = scheme=="https"`. `publish(*, channel, event, data)` → `client.trigger(channel, event, data)`, **fail-open** (every error swallowed/logged, `reverb_publisher.py:58-66`) so a transport error never breaks a turn. The publisher has **no domain knowledge** — the caller passes the full channel name.

**Channel naming** is owned by Laravel: `"private-abby.study.{study->id}"` (`AbbyAgentController.php:84`); peers use `private-publish.draft.{id}` / `private-study-design.session.{id}` (`reverb_publisher.py:8-9`). The state carries the full string (`AgentSessionState.channel`, `service.py:56`). **Auth:** the channel is `private-*` — the React client strips the `private-` prefix and subscribes via Laravel Echo `echo.private(name)` (`useAbbyAgent.ts:44-49`), which authorizes against Laravel's broadcasting auth. python-ai publishes server-side with the app secret (no per-event auth). Event names are dot-prefixed on the listener side (`.agent.text.delta`, etc., `useAbbyAgent.ts:51-72`), each parsed through a Zod schema then dispatched into `abbyAgentStore.applyEvent` (text/tool/done/error/approval-request/approval-denied). `agent.turn.done` also triggers `qc.invalidateQueries(["study-gates", slug])`.

## 5. Session lifecycle & DB binding

**DB row:** `agent_sessions` (`2026_05_25_000000_create_agent_sessions_table.php:12-29`) — `id`, `profile`, `subject_type`, `subject_id`, `user_id` (FK users, cascade), `anthropic_session_id` (resume key), `status` (`active|closed|error`), cumulative `cost_usd/tokens_in/tokens_out`, `token_id` (`personal_access_tokens.id` of the scoped Sanctum token), `context_json` (jsonb id-bag), `last_active_at`. Model `App\Models\App\AgentSession` with `scopeForSubject(profile, subjectType, subjectId)` (`AgentSession.php:56-62`). The migration additively back-copied legacy `study_design_agent_sessions`.

**Create** `POST /api/v1/studies/{study}/agent/sessions` → `AbbyAgentController::start` (`AbbyAgentController.php:61-121`): authorizes (collaborator/admin), inserts the row with `profile=abby, subject_type=study, context_json={study_slug, study_id}`, mints a **scoped Sanctum token** `createToken('abby-agent', ['studies.view','studies.execute','studies.create'])` (`AbbyAgentController.php:81`, abilities `:26`), stores `token_id`, then POSTs to python-ai `/agent/sessions` with `profile, agent_session_id, subject_id, channel, ingest_path, scoped_token, provider (AgentProviderResolver), context`. On failure it deletes the token and marks the row `error` (`AbbyAgentController.php:103-108`). Returns `{agent_session_id, channel_name, provider, actions_enabled}`. python-ai's `create_session` (`agent.py:51-74`) builds `AgentToolContext` + `AgentSessionState` and `registry.put(state)` into an **in-memory dict `_sessions`** (single-worker uvicorn, `registry.py:11`).

**Turn** `POST .../messages` → forwards to `/agent/sessions/{id}/turn` (`AbbyAgentController.php:126-148`); router `_run` (`agent.py:77-88`) takes a per-session `asyncio.Lock` (serialize double-submits), dedups identical `idempotency_key`, acquires a global `Semaphore(agent_max_concurrent_turns=4)` (`registry.py:12`), runs the turn as a `BackgroundTask`. `turn_slot().locked()` → HTTP 429 backpressure (`agent.py:97-98`).

**Resume:** there is no persistent in-memory client. Continuity is `resume=state.anthropic_session_id` (`service.py:232`); after each turn `ResultMessage.session_id` is captured (`service.py:292`) and persisted to Laravel via `LaravelPersister.persist` POSTing to the `ingest_path` (`service.py:69-90`), which `AbbyAgentController::ingest` (`AbbyAgentController.php:198-221`) **increments** the running totals. `snapshot` (`AbbyAgentController.php:178-191`) is the authoritative state read for UI hydration. After a server restart, `_sessions` is empty — a new session row/binding must be created.

## Parthenon-specific vs. generalizable seam

**Generalizable (carries over verbatim):** the entire harness — `ParthenonAgentService` run loop, `can_use_tool` approval-future pattern, `tool_base.request` Laravel-proxy seam, `tool_packs` builder+`_WRITE_TOOLS` registry, `ReverbPublisher`, the `agent_sessions` table, scoped-token minting, the 7 Reverb event types, the `provider` (anthropic/local) switch (`config.py:227-260`), and the `AgentSessionState`/`AgentToolContext` shapes. None reference OMOP.

**Parthenon-specific (would be replaced for a hospital-ops product):** every tool pack body and its Laravel routes — `studies/{slug}/gates`, `/results`, `/manuscript`, `/package`, `vocabulary/search`, `publish/drafts/*`; the 7-gate scientific-validity persona and STROBE/RECORD/PICO/empirical-calibration domain language in all three system prompts; the `_WRITE_TOOLS` membership; `subject_type` values (`study`, `study_design_session`, `publication_draft`) and `context_json` keys (`study_slug`, `version_id`, `draft_id`); channel domains (`abby`/`publish`/`study-design`); and the abilities `studies.view/execute/create`.

**The seam for a new surface:** add an `AgentProfile` (prompt+model), a `<feature>_tools.build_tool_pack` returning `@tool`-decorated httpx-over-Laravel closures, register it in `_BUILDERS` + `_WRITE_TOOLS`, add a Laravel controller mirroring `AbbyAgentController` (mint scoped token, pick `subject_type`/`channel`/`ingest_path`/`context`). Zero harness changes.

---


<br>

# A3 — Abby agency / action-taking engine + orchestrator guards

I have all the evidence needed. Here is the deliverable.

---

# Abby Agency / Action-Taking Engine — Architecture Map (Parthenon `ai/`)

Module root: `/home/smudoshi/Github/Parthenon/ai/app/agency/` + `/home/smudoshi/Github/Parthenon/ai/app/orchestrator/`. Two distinct subsystems coexist: (A) the **generic Plan→DAG→DryRun→Execute→Audit agency engine**, and (B) a **Parthenon-specific 7-stage study FSM** (`orchestrator/`). The agency engine is the generalizable seam; the orchestrator is OHDSI-locked.

---

## 1) PLAN → DAG → execute

**Two parallel plan representations exist** (this is the single biggest porting gotcha):

- **Sequential plan** — `plan_engine.py:83 ActionPlan` / `plan_engine.py:47 PlanStep`. Fields: `plan_id`(uuid str), `user_id`(int), `description`(str), `steps:list[PlanStep]`, `status:PlanStatus`, `created_at`/`expires_at`(UTC datetime), `auth_token`(str, **excluded from `to_dict()`** — `plan_engine.py:130`). `PlanStep` = `tool_name`, `parameters:dict`, `status`(`pending|success|failed|skipped`), `result`, `error`. Lifecycle enum `PlanStatus` (`plan_engine.py:31`): `PENDING→APPROVED→EXECUTING→COMPLETED|FAILED|CANCELLED`.
- **DAG plan** — `dag_executor.py:28 DAGStep` / `dag_executor.py:68 DAGPlan`. `DAGStep` adds `id` and `depends_on:list[str]` (the sequential `PlanStep` has **no** dependency field). Topological scheduling is `DAGPlan.get_execution_waves()` (`dag_executor.py:85`) — **Kahn's algorithm** building waves of zero-in-degree nodes; raises `ValueError` on unknown dependency (`:111`) or cycle (`:141`).

**Plan creation:** `PlanEngine.create_plan(user_id, description, steps:list[dict], auth_token, ttl_minutes=30)` (`plan_engine.py:188`). Validates every `tool_name` against `ToolRegistry` *before* building anything (`:222-228`), mints a uuid, sets `expires_at = now + 30min` (`_PLAN_TTL_MINUTES`, `plan_engine.py:23`). Plans live in an **in-memory dict `self._plans`** (`plan_engine.py:182`) — no persistence; a process restart loses pending plans.

**Execution (sequential path):** `execute_plan()` is `async` (`plan_engine.py:270`). Checks `plan.is_expired` (`:286`), sets `EXECUTING`, iterates steps in order; **on any failure, all remaining steps → `"skipped"`** and plan → `FAILED` (`:294-316`). Each step routed via `_execute_step()` (`plan_engine.py:319`) through a **hardcoded `tool_map` dict** (`:343-357`) keyed by tool_name → executor coroutine, called as `await executor(api_client=..., params=..., auth_token=plan.auth_token)` (`:363`). Note: the `tool_map` lists 11 tools but **omits `schedule_recurring_analysis`** which is registered (`tool_registry.py:344`) — that tool has no executor and would fail at `:361`.

**DAG path:** `DAGExecutor.execute(plan, step_executor)` (`dag_executor.py:170`) runs each wave via `asyncio.gather(..., return_exceptions=True)` (`:232`); steps whose `depends_on` intersects `failed_ids` are marked `"skipped"` and never run (`:218`, `:224`). Failure is per-branch (transitive skip), unlike the sequential path's hard stop. **The two executors are not wired together** — `execute_plan` does not use `DAGExecutor`; the DAG layer is reachable only by a caller that constructs `DAGPlan` directly (e.g. from `workflow_templates` step dicts, which carry `depends_on`).

---

## 2) DRY-RUN (previewable, non-mutating simulation)

`dry_run.py`. `DryRunSimulator.simulate(step:DAGStep)` (`dry_run.py:86`) looks up a pure lambda in `TOOL_DESCRIPTIONS` (`dry_run.py:29`) and returns `{"simulated": True, **extra}`. No `api_client`, no network, no `auth_token` — structurally incapable of mutation. `simulate_plan(steps)` (`:126`) maps over a list.

What the user sees per tool (the approval surface): `create_concept_set`→`{would_create:"concept_set", name}`; `generate_cohort`→`{would_execute:"cohort_generation", cohort_id}`; `execute_sql`→`{read_only:True, query_preview: query[:200]}`; `clone_cohort`/`create_cohort_definition`/`compare_cohorts`/`export_results` similar (`dry_run.py:30-58`). Unknown tool → generic `{simulated:True, note:"unknown tool ... no simulation available"}` (`:110`); handler exceptions are swallowed to `extra={}` (`:122`). **The user approves a list of `would_*` intent descriptors, not concrete diffs** — dry-run does not call the backend to compute real before/after state. Coverage gap: dry-run descriptors exist for only 7 tools; the high-risk `modify_concept_set`, `modify_cohort_criteria`, `run_characterization`, `run_incidence_analysis` have **no** simulation lambda and fall through to the generic note.

---

## 3) action_logger — what is audited per action

`action_logger.py` → table **`app.abby_action_log`** (migration `backend/database/migrations/2026_03_17_000004_create_abby_action_log_table.php`). Columns (`migration:11-23`): `id BIGSERIAL PK`, `user_id BIGINT → app.users(id)`, `action_type VARCHAR(50)`, `tool_name VARCHAR(100)`, `risk_level VARCHAR(10)`, `plan JSONB`, `parameters JSONB`, `result JSONB`, `checkpoint_data JSONB`, `rolled_back BOOLEAN DEFAULT FALSE`, `created_at TIMESTAMP DEFAULT NOW()`. Indexes on `user_id`, `created_at`, `tool_name` (`:25-27`).

`ActionLogger.log_action(*, user_id, action_type, tool_name, risk_level, parameters, result, plan, checkpoint_data)` (`action_logger.py:36`) — `INSERT ... RETURNING id` (`:92-105`), each JSON field `json.dumps()`-ed then cast `::jsonb`. Called from `PlanEngine._log_action` (`plan_engine.py:373`) which derives `action_type="execute"` (hardcoded) and pulls `risk_level` from the tool's `ToolDefinition.risk_level` (`:385`). **Captured: who (`user_id`), tool, args (`parameters`), API result, the whole plan snapshot, risk tier, timestamp.** Reversibility support: `checkpoint_data` column + `mark_rolled_back(action_id)` (`action_logger.py:107`, sets `rolled_back=TRUE`). **But `_log_action` never passes `checkpoint_data`** (`plan_engine.py:386-393` omits it) — so the rollback column exists and `rollback_capable` flags are set per tool, yet **no checkpoint is ever captured and no automated undo path is implemented**. `get_recent_actions(user_id, limit=20)` (`:132`) returns rows as dicts, swallowing errors → `[]` (`:169`).

---

## 4) api_client — the seam for taking REAL actions

`api_client.py:21 AgencyApiClient`. This is the **only** point where Python mutates Laravel domain state. Constructor base_url defaults to `settings.agency_api_base_url` = **`"http://nginx:80"`** (`ai/app/config.py:204`). `_build_url(path)` (`api_client.py:34`) roots everything under **`/api/v1/`**. `call(method, path, auth_token, data=None, timeout=30.0)` (`:39`) builds headers `Authorization: Bearer <auth_token>`, `Accept/Content-Type: application/json` (`:73`), fires via `httpx.AsyncClient`. **Auth is a Laravel Sanctum Bearer token** carried on `ActionPlan.auth_token` (`plan_engine.py:104`) and threaded through every executor.

**Envelope (canonical):** success → `{"success":True,"status":<int>,"data":<payload>}`; failure → `{"success":False,"status":<int>,"error":<msg>}` (`api_client.py:94`,`:110`); timeout/network → `{"success":False,"status":0,"error":...}` (`:118`,`:122`). Executors branch on `result.get("success")` / read `result["data"]`.

**Concrete endpoint map (the real mutation surface):**
| Tool executor | HTTP | `/api/v1/` path |
|---|---|---|
| `create_concept_set` (`concept_set_tools.py:52`,`:69`) | POST | `/concept-sets`, then `/concept-sets/{id}/items` |
| `modify_concept_set` (`modify_tools.py:59`,`:75`) | POST/DELETE | `/concept-sets/{id}/items`, `/concept-sets/{id}/items/{item_id}` |
| `modify_cohort_criteria` (`modify_tools.py:136`) | PUT | `/cohort-definitions/{id}` |
| `compare_cohorts` (`query_tools.py:49`,`:60`) | GET | `/cohort-definitions/{id}` ×2 |
| `export_results` (`query_tools.py:121`) | GET | `/{entity_type}/{entity_id}?format=` |
| `run_characterization`/`run_incidence_analysis` (`analysis_tools.py:58`,`:121`) | POST | `/analyses` (body `type:"characterization"|"incidence_rate"`) |
| `execute_sql` (`sql_tools.py:145`) | POST | `/sql/execute` |
| orchestrator levers (`orchestrator/tools.py`) | GET/POST | `studies/{ref}/gates`, `/gates/evaluate`, `/execute`, `/progress`, `/results`, `cohort-definitions/{id}/diagnostics`, `sources/{id}/dqd/run`, `estimations/{id}/execute`, `studies/{ref}/manuscript/draft|export` |

---

## 5) Guards / state-machine — safety guardrails

**Tier 1 — risk metadata (agency, generic):** `tool_registry.py:13 RiskLevel` (`LOW|MEDIUM|HIGH`) + `ToolDefinition` flags `requires_confirmation:bool=True`, `rollback_capable:bool=True` (`:55-56`). The `ToolRegistry.default()` factory (`tool_registry.py:116`) defines the canonical 12-tool catalogue: reads/copies (`compare_cohorts`, `export_results`, `clone_cohort`) = LOW; creates = MEDIUM; **HIGH** = `modify_concept_set`, `modify_cohort_criteria`, `execute_sql`, `schedule_recurring_analysis`. Note `compare_cohorts`/`export_results` set `requires_confirmation=False` (`:206`,`:224`) — read-only auto-execute. **Caveat:** `create_plan`/`execute_plan` *read* `risk_level` only for logging (`plan_engine.py:384`); the engine **does not itself gate on `requires_confirmation`** — confirmation enforcement lives in the (out-of-scope) API/UI caller via the explicit `PENDING→approve_plan()→APPROVED` transition (`plan_engine.py:252`). The only hard runtime guards in the engine are TTL expiry (`:286`) and tool-name validation (`:224`).

**Tier 2 — SQL allowlist (write-tool hardening):** `sql_tools.py:45 validate_sql_safety` — strips `--` and `/* */` comments to prevent injection bypass (`:69`), **rejects multi-statement queries** (semicolon mid-query, `:77`), requires the statement to start with `SELECT|WITH` (`:82`), and rejects 13 `DANGEROUS_PATTERNS` (`sql_tools.py:28`): INSERT/UPDATE/DELETE/DROP/ALTER/CREATE/TRUNCATE/GRANT/REVOKE/COPY/DO/`pg_`/`information_schema`. Blocked → `{"success":False,"error":"SQL blocked: ...","blocked":True}` *before any API call* (`:135`).

**Tier 3 — gate FSM (orchestrator, Parthenon-specific):** `orchestrator/guards.py`. `GateGuard.assert_may_run(action, gates)` (`:68`) raises `GateBlocked` (`:26`) if a prerequisite gate hasn't reached a `CLEARING_STATUSES = {passed, overridden, approved}` (`guards.py:12`). `PREREQUISITES` (`:20`): `run_estimation` needs `cohort_diagnostics`+`data_quality`; `export_publication` needs `study_diagnostics`+`estimation_calibration`. Feature-flagged by `gating_enabled` (mirrors `STUDIES_GATING_ENABLED`); when off, `gate_clears`/`assert_may_run` no-op (`:55`,`:71`). A **missing** gate counts as NOT cleared when gating is on (`:58`). This is **defense-in-depth** — Laravel also enforces `assertMayRun` + estimate blinding server-side (`guards.py:4-6`).

**State machine:** `orchestrator/state_machine.py StudyOrchestrator.run(source_id, execute=False)` walks 7 `STAGES` (`:25`: design→phenotype→cohort_diagnostics→data_quality→study_diagnostics→estimation_calibration→publication) in order, halts at first non-clearing gate, emits deterministic `_remediation()` text (`:81`). **Effect-estimate blinding** until `UNBLIND_AFTER="study_diagnostics"` (S5) clears (`:36`,`:184`). With `execute=True` it runs diagnostics/DQ then `guard.assert_may_run("run_estimation", gates)` *before* estimation (`:153`). Emits lifecycle events via injected `emit` callback (`:116`) for streaming.

---

## 6) workflow_templates — reusable recipes

`workflow_templates.py WorkflowTemplates` — static methods returning `list[dict]` step lists (each dict: `step_id`, `tool_name`, `parameters`, `depends_on`) consumable by `create_plan` / as `DAGStep`s. **Two templates:**

- `incident_cohort(condition_name, condition_concepts:list[int], drug_name, drug_concepts:list[int], washout_days=365, source_id=None)` (`:29`) → 5 steps: condition concept-set → drug concept-set (both `depends_on:[]`, parallel) → `create_cohort_definition` (depends on both) → `generate_cohort` → `run_incidence_analysis` (`:67-141`).
- `characterization_study(cohort_name, condition_concepts, source_id=None)` (`:143`) → 4 steps: concept-set → cohort-def → generate → `run_characterization`.

Parameterization is **shallow string/ID templating** — concept IDs expand to `{concept_id, include_descendants:True}` items (`:76`). **Critical limitation:** downstream IDs are hardcoded `None` "resolved at runtime" (`:115`,`:127-129`,`:200`,`:212`) — e.g. `generate_cohort.cohort_definition_id=None`. **No runtime resolution logic exists** in these files to backfill the created cohort's ID from an upstream step's `result` into a downstream step's params; the DAG executor passes `result` forward but nothing injects it into dependents. Discovery helpers: `list_templates()` (`:231`), `format_for_prompt()` (`:257`); registry equivalent `ToolRegistry.format_for_prompt()` (`tool_registry.py:99`) for LLM tool advertisement.

---

## Porting notes — Parthenon-specific vs. generalizable seam

**Generalizable (carries to hospital-ops 1:1):** the entire `agency/` skeleton — `RiskLevel`/`ToolDefinition`/`ToolRegistry`, `ActionPlan`/`PlanStep`/`PlanStatus` lifecycle, `DAGPlan`/`DAGStep` Kahn-wave executor, `DryRunSimulator` pattern, `AgencyApiClient` (Sanctum-Bearer → `/api/v1/` envelope `{success,status,data|error}`), and `app.abby_action_log` audit schema. Only `RiskLevel`, the action-log table, executor signature `async (api_client, params, auth_token)`, and the envelope are load-bearing contracts.

**Parthenon-locked (must be rewritten for hospital-ops):** every concrete tool name and endpoint — `create_concept_set`, `create_cohort_definition`, `generate_cohort`, `modify_cohort_criteria`, `run_characterization`, `run_incidence_analysis`, `execute_sql`, and paths `/concept-sets`, `/cohort-definitions`, `/analyses`, `/sql/execute`. The **entire `orchestrator/` package** (7-stage study FSM, `PREREQUISITES`, gate ledger, `study_diagnostics` estimate-blinding, `estimation_calibration`, negative-controls remediation, `studies/{ref}/manuscript/export`) is OHDSI/OMOP outcomes-research domain logic with no hospital-ops analogue. Both `WorkflowTemplates` recipes are OMOP cohort/concept-set studies. The generalizable seam for a hospital-ops product: keep the engine + registry + DAG + dry-run + audit-log + api_client verbatim; replace the `ToolRegistry.default()` catalogue, the `_execute_step` `tool_map`, the `tools/*` executors (→ new Laravel ops endpoints like room-assignment, transport-dispatch, bed-status), the `dry_run.TOOL_DESCRIPTIONS` lambdas, and substitute the OHDSI gate-FSM with a hospital-ops approval/confirmation FSM. **Two pre-existing defects to fix on port:** (1) `checkpoint_data` is never populated so rollback is non-functional; (2) `schedule_recurring_analysis` is registered with no executor; (3) cross-step ID resolution in templates/DAG is unimplemented (downstream `None`s never backfilled).

---


<br>

# A4 — Abby memory, RAG, live-context, knowledge capture/surfacing

I now have complete, precise evidence for the full pipeline. Here is the deliverable.

---

# Abby Memory / RAG / Live-Context / Knowledge Architecture Map (Parthenon `ai/` service)

Orchestration seam: **`app/routers/abby.py`** — `_build_chat_system_prompt()` (def ~`abby.py:1782`) assembles `list[ContextPiece]`, then `_build_context_block()` (`abby.py:1733`) ranks+formats them into the system prompt. Request shape `ChatRequest` (`abby.py:777`); transport is the FastAPI Abby chat endpoint, called by the Laravel backend.

## 1) context_assembler — per-request awareness pipeline

**File:** `app/memory/context_assembler.py`. Pure ranking/budgeting library; holds no I/O.

- **Six tiers** (`ContextTier`, `context_assembler.py:9-15`): `WORKING, PAGE, LIVE, EPISODIC, SEMANTIC, INSTITUTIONAL`. Display names `TIER_DISPLAY_NAMES:18`; render order `TIER_ORDER:27`.
- **`ContextPiece`** dataclass (`:49`): `tier, content:str, relevance:float, tokens:int, source:str, is_safety_critical:bool`.
- **Budgets are model-specific.** `MEDGEMMA_TIER_BUDGETS:32` (total 4000: WORKING 1500/PAGE 500/LIVE 800/EPISODIC 400/SEMANTIC 600/INSTITUTIONAL 200). `CLAUDE_TIER_BUDGETS:37` (total 28000). Factory `ContextAssembler.for_model(name)` (`:71`) maps `"medgemma"`/`"claude"`.
- **`assemble()`** (`:79`): safety-critical pieces (`is_safety_critical`) are reserved off-budget first (`:85`); remaining sorted by `relevance` desc; greedy fill respecting per-tier cap **and** `remaining_budget` (`:90-99`); final output re-sorted by `(TIER_ORDER, -relevance)` so the prompt reads WORKING→INSTITUTIONAL.
- **`format_prompt()`** (`:105`): groups pieces by tier, emits `## {display_name}` markdown headers per tier.

**What actually populates the pieces** (in `_build_chat_system_prompt`, `abby.py:1809-2025`), in order:
1. Conversation **history** → `ContextTier.WORKING`, relevance ~0.9 (`abby.py:1816`); plus a recent-message block (`:1828`).
2. Episodic recall (cross-conversation) → `EPISODIC` (`:1840`).
3. **Help text** per page `_get_help_context()` → `PAGE` (`:1851`, `CONTEXT_HELP_KEYS`).
4. **RAG** `build_rag_context()` → `SEMANTIC` relevance 0.85 (`:1865-1879`).
5. **Live DB** `query_live_context()` → `LIVE` relevance 0.95, gated by `_should_skip_live_context` (`:1889-1903`).
6. User name/roles → `WORKING` 0.45 (`:1911`); learned research profile (`MemoryUserProfile.from_dict(...).get_context_string()`) → `WORKING` 0.5 (`:1928`).
7. **page_data** entity block (`CURRENT PAGE CONTEXT:`) → `PAGE` 0.8 (`:1944`).
8. **Data-quality warnings** → `LIVE`, relevance 1.0, **`is_safety_critical=True`** (`:1987`).
9. **Institutional knowledge** → `INSTITUTIONAL` 0.6 (`:2011`).

Then `_apply_cloud_safety_filter` (`:2025`) strips pieces before cloud (Claude) calls, `_build_context_block` ranks, and **GROUNDING RULES** are appended depending on `bool(rag_context or live_context)` (`abby.py:2032-2049`).

## 2) live_context — current screen/data-state injection

**File:** `app/chroma/live_context.py`. Entry: `query_live_context(message, page_context) -> str` (`:138`).

- **Intent gating** `_TOOL_INTENTS` (`:45`): per-tool regex sets; `_detect_intents()` (`:101`) returns a set; `broad_search` alone expands to `{concept_sets, cohort_definitions, analyses}` (`:108`). Only matching tools fire — keeps the window clean.
- **8+ tools run in parallel** via `_live_context_pool` (`ThreadPoolExecutor max_workers=4`, `:32`), each timed, `future.result(timeout=12)` (`:224`): `_tool_search_concept_sets` (`:267`), `_tool_list_cohort_definitions` (`:311`), `_tool_query_vocabulary` (`:363`), `_tool_get_achilles_stats` (`:390`), `_tool_get_dqd_summary` (`:461`), `_tool_get_cdm_summary` (`:497`), `_tool_get_analyses` (`:559`), `_tool_graph_query` (`:588`), `_tool_data_profile` (`:649`).
- **Payload shape:** plain markdown, not JSON. Prefixed `"\n\nLIVE PLATFORM DATA (queried just now from the Parthenon database):\n\n"` (`:248`); empty-match returns a "No matching data found" sentinel (`:236`). DB via `create_engine(settings.database_url, pool_size=3, pool_pre_ping=True)` (`:38`).
- **NOTE — this is the user's *platform* state, not literal screen DOM.** The literal screen state is `request.page_data` (a `dict[str,Any]`, `ChatRequest.page_data`, `abby.py:783`) rendered as the `CURRENT PAGE CONTEXT:` block (`abby.py:1944-1961`) and `request.page_context` (a string slug e.g. `cohort_builder`). `query_live_context` re-queries the DB by intent rather than trusting the frontend payload.

## 3) Conversation memory + summarization

**Two parallel stores** (dual-write):

- **PostgreSQL + pgvector** — `app/memory/conversation_store.py`, class `ConversationStore(engine, embedder, embedding_dim=384)`. Table `app.conversation_messages(conversation_id, role, content, embedding vector, user_id)` joined to `app.conversations(user_id)`. `store_message()` (`:59`) embeds via `embedder.encode([content])[0]`, casts `:embedding::vector`. `search_similar()` (`:104`) uses cosine `<=>`, `ORDER BY distance ASC`, `distance_threshold=1.0`. `get_recent()` (`:160`) newest-first. **All read paths swallow exceptions → return `[]`** (graceful degrade).
- **ChromaDB** — `app/chroma/memory.py`. `store_conversation_turn(user_id, question, answer, page_context)` (`:37`) writes `"Q: {q}\nA: {a}"` into the shared `conversation_memory` collection (`get_conversation_memory_collection`, `collections.py:56`) with id `conv_{user_id}_{uuid12}`, metadata `{user_id, page_context, category, timestamp, question_preview[:100], source:"abby_chat", source_type:"chat", type:"conversation_turn", workspace:"abby"}` (`:48`). Dedup by exact document match (`:61`). Public Commons messages go to a separate unified `conversations` collection via `store_commons_message()` (`:79`). `prune_old_conversations(user_id, ttl_days=90)` (`:135`). `aggregate_conversations()` (`:160`) backfills legacy `conversations_user_*` per-user collections into the shared one.
- **Embeddings** (`app/chroma/embeddings.py`): `get_general_embedder()` = `all-MiniLM-L6-v2` (384-dim) for docs/conv/FAQ; `get_clinical_embedder()` = SapBERT (768-dim) for clinical/wiki/papers/textbooks. PID-aware caching to survive uvicorn `--workers` forks.

**Summarization** — `app/memory/summarizer.py`, `ConversationSummarizer(threshold_ratio=0.75, context_window=8192)`.
- `estimate_tokens()` (`:68`) uses **`_CHARS_PER_TOKEN = 1`** (`:23` — deliberately 1:1, treats SQL/identifiers as worst-case dense).
- Trigger: `should_summarize()` (`:80`) True when `used >= context_window*threshold_ratio`.
- `split_for_summarization(messages, keep_recent=4)` (`:90`): keeps last `keep_recent*2` messages verbatim, returns `(old, recent)`.
- `format_summary_prompt()` (`:119`) builds the LLM prompt; **no LLM call inside this module** — caller sends it and injects `create_summary_message()` (`:142`) as a `{"role":"system","content":"[Prior context summary]\n..."}` message.

## 4) profile_learner / intent_stack / scratch_pad

- **`profile_learner.py`** — `ProfileLearner.learn_from_conversation(profile, messages) -> UserProfile` (`:182`); **regex/keyword only, no LLM**, fully immutable (returns new `UserProfile`). `UserProfile` (`:119`): `research_interests:list, expertise_domains:dict[str,float], interaction_preferences:dict, frequently_used:dict[str,list], interaction_count:int`; `to_dict/from_dict/get_context_string`. Learns: domain interests via `DOMAIN_KEYWORDS` (`:15`, 10 disease areas), verbosity via `TERSE/VERBOSE_INDICATORS` (`:62/70`), corrections via `CORRECTION_PATTERNS` (`:79`), entities via `CONCEPT_SET_PATTERN`/`DATASET_PATTERN` (`:106/110`), expertise via EMA `0.7*current+0.3*score` over `EXPERT_KEYWORDS`/`BEGINNER_KEYWORDS` after `min_interactions_for_calibration=3` (`:308`). The learned profile is **passed in by Laravel** as `ChatRequest.user_profile.research_profile` (`ResearchProfile`, `abby.py:739`) and rendered via `MemoryUserProfile.get_context_string()` (`abby.py:1928`).
- **`intent_stack.py`** — `IntentStack(max_depth=3, expiry_turns=10)`; bounded stack of `IntentEntry(topic, first_turn, last_active_turn)`. `push/refresh/clear_and_set/prune(current_turn)/current_topic/get_context_string` ("Active conversation topics: …"). `to_dict/from_dict` for session serialization.
- **`scratch_pad.py`** — `ScratchPad`, in-memory `dict[str,Artifact(key,value,version)]`; `store()` auto-bumps version; `get_context_string()` emits `Working scratch pad:` block; `estimated_tokens()` = chars//4; `to_dict/from_dict`. Session-scoped intermediate artifacts (SQL drafts, cohort specs).

**Note:** intent_stack and scratch_pad are self-contained primitives; in the read seam (`abby.py`) the WORKING tier is populated from history + the passed-in profile, so these two are persisted/replayed by the caller, not invoked inside `_build_chat_system_prompt`.

## 5) Institutional knowledge capture + surfacing + FAQ promotion

- **Capture** — `app/institutional/knowledge_capture.py`, `KnowledgeCapture(engine, embedder=None)`. Methods: `capture_cohort_creation()` (`:70`, type `cohort_pattern`), `capture_analysis_completion()` (`:132`, type `analysis_config`), `capture_correction()` (`:183` → `app.abby_corrections`), `capture_data_finding()` (`:236` → `app.abby_data_findings`). `_store()` (`:386`) inserts into **`app.abby_knowledge_artifacts`** `(type, title, summary, tags, disease_area, study_design, created_by, source_conversation_id, artifact_data::jsonb, embedding::vector)`; embedding = `embedder.encode(title+" "+summary)`. `search_similar(query, limit=5, artifact_type=None)` (`:298`): pgvector `ORDER BY embedding <=> :embedding::vector`, `WHERE status='active'`. `increment_usage(artifact_id)` (`:361`).
- **Surfacing** — `app/institutional/knowledge_surfacing.py`, `KnowledgeSurfacer(knowledge_capture)`. `suggest(query, max_results=5, max_distance=0.5)` (`:27`) delegates to `search_similar` and filters `distance <= max_distance`. `format_for_prompt()` (`:61`) → `INSTITUTIONAL KNOWLEDGE (from other researchers):` block, one line per `[type] title — summary (used Nx)`. Wired at `abby.py:2006-2019`.
- **FAQ promotion — two independent paths:**
  - **Postgres path** `app/institutional/faq_promoter.py`, `FAQPromoter(engine, embedder, threshold=settings.institutional_faq_threshold)`. `check_and_promote(question, answer)` (`:76`) counts `DISTINCT c.user_id` in `app.abby_messages`⋈`app.abby_conversations` via `content ILIKE %question[:100]%`; if `>= threshold` inserts `type='faq'` into `app.abby_knowledge_artifacts ON CONFLICT DO NOTHING`. `get_faqs(limit=20)` ordered by `usage_count DESC`. Availability-gated via `to_regclass` (`:50`).
  - **Chroma path** `app/chroma/faq.py`, `promote_frequent_questions(days=7)` (`:49`): nightly scan of `conversation_memory`, naive clustering against `faq_shared` (`SIMILARITY_THRESHOLD=0.85`), promote when `freq>=5 (MIN_FREQUENCY)` and `users>=3 (MIN_USERS)`; upsert `Q:/A:` doc into `faq_shared` with `source:"auto_promoted"`. `seed_demo_faqs()` (`:115`) preloads 8 OHDSI Q&A. The `faq_shared` collection is read back at query time by `query_faq()` (`retrieval.py:247`).

## 6) RAG retrieval (cross-cutting) + OMOP/OHDSI-specific vs generalizable

**RAG** — `app/chroma/retrieval.py`, entry `build_rag_context(query, page_context, user_id)` (`:334`) → `get_ranked_rag_results()` (`:380`). Fans out via `_query_pool` (`max_workers=5`, `:25`) to per-collection queriers: `query_docs`, `query_faq`, `query_user_conversations` (where `user_id`), conditionally `query_wiki` (`_should_query_wiki`: `commons_ask_abby/studies/analyses`, `:329`), and for `CLINICAL_PAGES` (`:27`) `query_clinical` + `query_ohdsi_papers`, plus `query_medical_textbooks` (`:294`) for genomics/biology topics. Ranking is hybrid: cosine `(1.0 - dist)` + lexical bonus (`title_overlap*0.25 + body_overlap*0.15 + exact_phrase 0.2`, `:161`); `DEFAULT_DISTANCE_THRESHOLD=0.5`; dedup by `text[:100]` prefix, top-8 (`:418-428`). Output block header: `KNOWLEDGE BASE (retrieved documents ranked by relevance):` (`:375`), chunks truncated to 600 chars. Ingestion is `app/chroma/ingestion.py` (content-hash dedup, markdown header chunking 512/64, OHDSI PDF via pymupdf, `audit_document` gating, `_purge_missing_source_files`).

### OMOP/OHDSI-specific (would NOT carry to hospital-ops)
- **`live_context.py` tools 1–9** query OMOP/OHDSI tables: `app.concept_sets`, `app.cohort_definitions`/`app.cohort_generations`, `vocab.concept` (`standard_concept='S'`), `results.achilles_results` (analysis IDs 1/400/700/…), `app.dqd_results`, `app.sources`, `app.analysis_executions`. Intent regexes name SNOMED/ICD/RxNorm/LOINC/Achilles/DQD/CDM.
- **`knowledge/graph_service.py`** (`KnowledgeGraphService`) is pure OMOP vocabulary graph: `vocab.concept_ancestor`, `vocab.concept_relationship`, `min_levels_of_separation`. Redis cache `abby:kg:` TTL 3600.
- **`knowledge/data_profile.py`** (`DataProfileService`) profiles CDM domain tables (`condition_occurrence`, `drug_exposure`, …, `observation_period`), `ALLOWED_SCHEMAS` whitelist, sparse/temporal gap heuristics (`_SPARSE_RECORDS_PER_PATIENT=1.0`, `_MIN_YEARS_COVERAGE=3`).
- **`profile_learner.DOMAIN_KEYWORDS`/`EXPERT_KEYWORDS`** are clinical-research-coded (cohort/OMOP/HR). **Clinical collections** (`clinical_reference`, `ohdsi_papers`, `wiki_pages`, `medical_textbooks`) use SapBERT for biomedical term matching.

### Generalizable seam (the porting boundary)
- **`context_assembler.py`** (tiers, budgets, greedy assembly, safety reservation) is 100% domain-agnostic — keep as-is; only re-tune `*_TIER_BUDGETS`.
- **`conversation_store.py`, `memory.py`, `summarizer.py`, `intent_stack.py`, `scratch_pad.py`** are domain-free.
- **`retrieval.py`** ranking core is generic; the porting work is swapping `CLINICAL_PAGES`, the `_should_query_*` predicates, the SapBERT collections, and `SOURCE_LABELS` for ops-domain ones.
- **`knowledge_capture.py`/`knowledge_surfacing.py`/`faq_promoter.py`** generalize cleanly: the artifact table `app.abby_knowledge_artifacts(type, title, summary, tags, disease_area, study_design, embedding, …)` is reusable; `disease_area`/`study_design` are the only domain-named columns (rename to e.g. `service_line`/`workflow_type`). The capture *methods* (`capture_cohort_creation`/`capture_analysis_completion`) are the OMOP-named seams; a hospital-ops product would add `capture_<ops-entity>` methods writing the same generic table.
- **Generic seam for "live context":** replace the 9 OMOP SQL tools and `_TOOL_INTENTS` regexes with operational-domain tools (census, bed status, transport queue) emitting the same markdown-prefix contract (`LIVE PLATFORM DATA …`) and the same `ContextTier.LIVE` `ContextPiece`. The `_detect_intents`/`ThreadPoolExecutor`/timeout scaffolding is reusable verbatim.

**Key env/config** (`app/config.py`): `database_url`, `redis_url` (default `redis://redis:6379/2`), `chroma_host`/`chroma_port` (`chromadb:8000`), `ollama_embedding_model` (`nomic-embed-text:latest`), `institutional_faq_threshold` (default 3, `faq_promoter.py:45`), `model_cache_dir`. Model profiles: `"medgemma"` (local Ollama, 4k budget) vs `"claude"` (cloud, 28k budget, cloud-safety filter strips pieces pre-send at `abby.py:2025`).

---


<br>

# A5 — Abby Laravel backend — proxy, scoped tokens, provider/surface policy, models, migrations

I have complete evidence. Producing the deliverable.

---

# Abby Laravel Backend Integration — Architecture Map (Parthenon)

Evidence cited as `path:line`. All paths relative to `/home/smudoshi/Github/Parthenon/backend`.

## 0. Two distinct Abby paths (do not conflate)

There are **two** Abby request models with different controllers, auth, and proxy mechanics:

- **(A) Synchronous chat / cohort path** — `AbbyAiController` → `AbbyAiService` → `AiService.abbyChat()` → HTTP POST to python-ai `/abby/chat`. Request/response, no callback into Laravel.
- **(B) Agentic orchestrator path** — `AbbyAgentController` (mirrors `PublishAgentController`) → registers a long-lived `AgentSession` with python-ai `/agent/sessions`, hands python-ai a **scoped Sanctum token** so the agent can call *back* into Laravel as the user, with async telemetry `/ingest` callbacks.

---

## 1) Proxy: Laravel → Python AI service

**HTTP client:** Laravel `Illuminate\Support\Facades\Http` (Guzzle wrapper) throughout — no SDK.

**Base URL (config key):** `config('services.ai.url')`, default `http://python-ai:8000`, from env `AI_SERVICE_URL` (`config/services.php:63-67`). `services.ai.base_url` is a duplicate alias. `AiService` caches it as `$this->baseUrl` (`AiService.php:26`); the two agent controllers re-read it via `config('services.ai.url', 'http://python-ai:8000')` and `rtrim('/')` (`AbbyAgentController.php:28-31`, `PublishAgentController.php:26-29`).

**(A) Chat proxy — `AiService::abbyChat()` (`AiService.php:178-215`):**
- `POST {baseUrl}/abby/chat`, `Http::timeout(300)`.
- Payload keys: `message`, `page_context`, `page_data` (`(object)[]` when empty — note the `[]`→`{}` guard), `history` (`[{role,content}]`), optional `user_profile`, `user_id`, `conversation_id`, and **`provider_policy`** (`AiService.php:187-209`).
- `provider_policy` is built by `abbyProviderPolicyPayload()` (`AiService.php:222+`): first tries `AbbyProviderPolicyService::payloadForSurface('chat')`; on null/throw falls back to the active `AiProviderSetting` (legacy `local-medgemma` / `local_only`).
- Response defaulted to `['reply' => 'Abby is unavailable.', 'suggestions' => []]` on null body.
- `parseCohortPrompt()` → `POST {baseUrl}/abby/parse-cohort` `{prompt, page_context}`, `timeout(120)` (`AiService.php:160-169`).
- `AbbyAiService::chat()` is a thin delegate to `AiService::abbyChat()` (`AbbyAiService.php:220-238`); `AbbyAiService` itself contains all the OMOP cohort-building logic (regex/LLM merge, vocabulary search, Circe expression JSON).

**(B) Agent proxy — `AbbyAgentController::start()` (`AbbyAgentController.php:86-101`):**
- `POST {aiBaseUrl}/agent/sessions`, `Http::acceptJson()` (no timeout override).
- Body: `profile='abby'`, `agent_session_id`, `subject_id`, `channel` (`private-abby.study.{id}`), `ingest_path` (Laravel callback URL), **`scoped_token`** (plaintext), `provider` (resolver output), `context` (`{study_slug, study_id}`).
- Turn relay: `POST /agent/sessions/{id}/turn` `{text, idempotency_key}` (`:138`). Approval relay: `POST /agent/sessions/{id}/approve` `{tool_use_id, approved}` (`:163`).
- Failure handling: on `$resp->failed()`, deletes the just-minted token and sets session `status='error'`, returns 503 (`:103-108`).

---

## 2) Scoped tokens (Sanctum abilities)

Minting (`AbbyAgentController::start`, `:81-82`):
```php
private const AGENT_ABILITIES = ['studies.view', 'studies.execute', 'studies.create'];
$newToken = $user->createToken('abby-agent', self::AGENT_ABILITIES);
$agentSession->update(['token_id' => $newToken->accessToken->id]);
```
- `createToken(name, abilities)` mints a Laravel Sanctum `personal_access_token` whose `abilities` JSON is the limited list — **not** `['*']`.
- The **plaintext** token (`$newToken->plainTextToken`) ships to python-ai in the request body (`scoped_token`, `:99`). python-ai stores it and uses it as `Authorization: Bearer` to call back into Laravel's agent-reachable WRITE routes.
- `personal_access_tokens.id` is persisted to `agent_sessions.token_id` (`:82`) for revocation; on proxy failure the token is `delete()`d (`:104-105`).
- `PublishAgentController` is the template: abilities `['publications.view','publications.update']` (`:24`); its comment confirms agent-reachable writes carry `abilities:publications.update` middleware so the scoped token is enforced, while normal SPA login tokens hold `['*']` and satisfy any `abilities:` gate (`:17-23`).
- **Provider override** is independent of token scope: `(new AgentProviderResolver)->resolveProvider()` returns `'anthropic'` (cloud) or `'local'` from `SystemSetting::getValue('agents.provider_mode')` ∈ {cloud|local|auto}; `auto` returns local only if an active+enabled `ollama` `AiProviderSetting` exists (`AgentProviderResolver.php:51-85`). Default is `cloud` deliberately.

---

## 3) Provider policy: `AbbyProviderProfile` × `AbbySurfacePolicy`

Service: `AbbyProviderPolicyService.php`. Two-table model — **profiles** (what a provider can do) and **surface policies** (what a surface is allowed to use).

**`abby_provider_profiles` columns** (`...010000_create...:11-32`): `id`, `profile_id` (unique string PK-by-convention), `display_name`, `provider_type` (50), `transport` (80), `entitlement_type` (default `local`), `model` (200, default `''`), `base_url` (500, nullable), `provider_setting_type` (50, nullable — which `AiProviderSetting` row holds the secret), `is_enabled` (bool), `capabilities` jsonb, `safety` jsonb, `limits` jsonb, `fallback_profile_ids` jsonb, `notes` jsonb, `updated_by` FK→users.

**`abby_surface_policies` columns** (`...010000...:34-48`): `id`, `surface` (80, unique), `provider_mode` (40, default `local_only`), `default_profile_id` (100, nullable), `fallback_profile_ids` jsonb, `never_send_phi_to_cloud` (bool, default true), `allow_cloud` (bool, default false), `required_capabilities` jsonb, `settings` jsonb, `updated_by` FK→users.

**Controlled vocabularies** (`AbbyProviderPolicyService.php:14-86`):
- `CAPABILITIES`: chat, streaming, structured_output, json_mode, embeddings, tool_calling, agent_loop, long_context, vision, clinical_rag, **patient_level_local_only**.
- `ENTITLEMENTS`: local, org_api_key, user_api_key, acumenus_managed_api, external_subscription_app.
- `MODES`: local_only, cloud_only, local_first, cloud_first, auto_by_complexity, auto_by_budget, disabled.
- `SURFACES`: chat, parse_cohort, **study_agent**, **protocol_evaluator**, **gis**, **phenotype_interpreter**, embeddings.
- `TRANSPORTS`: ollama_chat, anthropic_messages, openai_responses, openai_compatible_chat, anthropic_compatible_proxy, external_subscription_app.
- `surfaceRequirements()` (`:75-86`) maps each surface→required capabilities, e.g. `study_agent => ['agent_loop','tool_calling']`, `chat => ['chat','streaming']`.

**Decision logic — `payloadForSurface($surface)` (`:289-328`):**
1. `tablesExist()` guard (`Schema::hasTable`) → null if unmigrated.
2. Load policy by `surface`. If `provider_mode==='disabled'` → returns a stub `mode='disabled'` payload (`profile_id` default `local-medgemma`).
3. Build candidate list = `[default_profile_id, ...fallback_profile_ids]`; return first whose `validateProfileForSurface()` passes via `payloadForProfile()`.

**`validateProfileForSurface()` (`:251-284`)** emits machine-readable error codes:
- `profile_disabled` (not `is_enabled`); `external_subscription_app_not_backend_routable`; `missing_capabilities:<list>` (required minus profile capabilities); `cloud_not_allowed` (cloud profile but `!allow_cloud`); `patient_level_context_not_cloud_safe` (cloud + `never_send_phi_to_cloud` + `safety.patient_level_context_allowed` false).
- `isCloudProfile()` = `entitlement_type !== 'local' && transport !== 'ollama_chat'` (`:494-497`).

**`payloadForProfile()` (`:392-415`)** emits to python-ai: `provider_type` (lowercased), `profile_id`, `mode`, `model`, `entitlement`, and `settings` (api_key from `AiProviderSetting`, base_url resolved via profile→provider-settings→`OPENAI_COMPATIBLE_BASE_URLS` map {deepseek/mistral/moonshot/qwen}, timeout, max_output_tokens, monthly_budget_usd) — empties filtered by `onlyNonEmpty()`. **Secrets (api_key) are read from `AiProviderSetting.settings` via `providerSettingsForProfile()` (`:546-554`), never stored on the profile.**

`simulateRoute()` (`:421-492`) is a dry-run mirror returning `will_call_paid_provider`, `blocked_reasons`, `selected_profile`, `fallback_used`, `estimated_budget_impact` — used by the super-admin route simulator. `presets()` (`:337-387`) gives 7 named starting templates (e.g. `clinical_local_only`, `byo_api_key`, `agents_read_only_local`).

---

## 4) DB schema — full column lists

- **`app.abby_conversations`** (`...400001...:11-19`): `id` bigserial, `user_id` FK→users cascade, `title` (500, null), `page_context` (64, default `general`), `created_at`, `updated_at`. Model: `App\Models\App\AbbyConversation`, `$table='app.abby_conversations'`, scope `forUser`, relations user/messages/userProfile.
- **`app.abby_messages`** (`...400001...:21-30` + embedding migration): `id`, `conversation_id` FK cascade, `role` (16), `content` text, `metadata` json (null), `created_at` (no `updated_at` — model `UPDATED_AT=null`), **`embedding` vector(384)**, **`embedding_model` varchar(100) default `all-MiniLM-L6-v2`**, HNSW index `vector_cosine_ops` (`...000002...:36-44`). Vector type/schema resolved dynamically from `pg_extension`.
- **`app.abby_user_profiles`** (raw SQL, `...000001...:11-23`): `id` bigserial, `user_id` bigint UNIQUE FK cascade, `research_interests` **TEXT[]** default `'{}'`, `expertise_domains` **JSONB** `'{}'`, `interaction_preferences` JSONB, `frequently_used` JSONB, `learned_at` timestamp, `created_at`, `updated_at`.
- **`app.abby_cloud_usage`** (base `...000003...:11-23` + extension `...230000...`): `id`, `user_id` FK, `department` (100), `tokens_in` int NOT NULL, `tokens_out` int NOT NULL, `cost_usd` DECIMAL(10,6) NOT NULL, `model` VARCHAR(200, widened), `request_hash` (64), `sanitizer_redaction_count` int default 0, `route_reason` VARCHAR(100, widened), `created_at`; **added provider-neutral:** `provider` (50, default `anthropic`), `transport` (80), `provider_profile_id` (100), `entitlement_type` (80, default `org_api_key`), `request_surface` (80, default `abby_chat`), `status` (40, default `success`), `error_class` (80), `fallback_reason` (100), `response_latency_ms` double, `usage_metadata` JSONB default `'{}'`. Indexes on (provider,created_at), (status,created_at), (provider_profile_id,created_at).
- **`app.abby_action_log`** (`...000004...:11-23`): `id`, `user_id` FK, `action_type` (50) NOT NULL, `tool_name` (100) NOT NULL, `risk_level` (10) NOT NULL, `plan` JSONB, `parameters` JSONB, `result` JSONB, `checkpoint_data` JSONB, `rolled_back` bool default false, `created_at`. Indexes: user_id, created_at, tool_name. *(No Eloquent model in the read set — write path is python-ai or a service not in scope.)*
- **`agent_sessions`** (`...000000_create_agent_sessions...:12-29`): `id`, `profile` (64), `subject_type` (64), `subject_id` unsignedBigInt, `user_id` FK cascade, `anthropic_session_id` (null), `status` (32, default `active` — active|closed|error), `cost_usd` DECIMAL(10,4) default 0, `tokens_in`/`tokens_out` unsignedBigInt default 0, `token_id` unsignedBigInt (null — `personal_access_tokens.id`), `context_json` jsonb, `last_active_at`, timestamps. Index `(profile,subject_type,subject_id)`. Migration additively copies legacy `study_design_agent_sessions` rows. Model scope `forSubject(profile,subjectType,subjectId)`.
- **`abby_provider_profiles`** / **`abby_surface_policies`**: see §3.

---

## 5) Cost / usage accounting

- **`agent_sessions`** is the **running-total ledger** for the agentic path. The python-ai agent POSTs telemetry to `…/ingest` (`AbbyAgentController::ingest`, `:198-221`): validates `{anthropic_session_id?, cost_usd≥0, tokens_in≥0, tokens_out≥0, status∈[active,closed,error]}`, then **increments** `cost_usd += `, `tokens_in += `, `tokens_out += ` and bumps `last_active_at`. `snapshot` (`:178-191`) returns the cumulative totals + channel name. Note: `agent_sessions.cost_usd` is DECIMAL(10,4) but model casts to `float`.
- **`abby_cloud_usage`** is the **per-call cloud-cost audit table** (chat path). The provider-neutral migration (`...230000...`) generalized it off Anthropic-only: added `provider`, `transport`, `provider_profile_id`, `entitlement_type`, `request_surface`, `status`, `error_class`, `fallback_reason`, `response_latency_ms`, `usage_metadata`, and widened `model`/`route_reason`. `request_hash` + `sanitizer_redaction_count` support dedup and PHI-redaction accounting. *(The writer is not in the read set — likely python-ai or a usage-recording service; the table is the schema seam.)*

---

## 6) AuthZ — Spatie roles / Sanctum abilities

- **Outer group** wrapping all chat/conversation/profile routes: `Route::middleware(['auth:sanctum','locale.resolve','source.resolve'])` (`routes/api.php:229`). So **all** of `/api/v1/abby/*` (build-cohort, create-cohort, chat, chat/stream, suggest-criteria, explain, refine, suggest-protocol — `:1158-1167`), `abby/conversations` apiResource (`:1174`), and `abby/profile` (`:1178-1182`) require an authenticated Sanctum user but carry **no role/permission gate** — any logged-in user. Conversation isolation is enforced in-controller via the `forUser($request->user()->id)` scope (`AbbyConversationController.php:19,33,68`), not middleware.
- **`data-interrogation/ask`** adds `permission:analyses.view` (`:1170-1171`).
- **Agent (Abby orchestrator) routes** `studies/{study}/agent/*` (`:910-918`): no `permission:` middleware — gated by **throttle** (20/30/60/120 per min) + **in-controller** `authorizeAccess()`: collaborator via `Study::accessibleBy($user->id)` scope **OR** `$user->hasRole(['admin','super-admin'])` (`AbbyAgentController.php:42-48`), plus `assertSessionBelongs` (subject_id + `profile==='abby'`). Publish-agent routes use `PublicationDraftPolicy::view` instead.
- **Provider-policy admin** `prefix('abby-ai')` and `ai-agents`: `Route::middleware('role:super-admin')` (`:1568,1574`) — `AbbyProviderPolicyController` and `AgentSettingsController` are **super-admin only**.
- **Scoped-token enforcement on callbacks:** the agent's WRITE routes require `abilities:<scope>` middleware (Sanctum ability gate); SPA `['*']` tokens pass, agent tokens pass only for their granted abilities.

---

## 7) Parthenon-specific vs. generalizable seam (for hospital-ops porting)

**Will NOT carry over (OHDSI/OMOP-bound):**
- `AbbyAiService` is ~90% OMOP cohort logic: `PATTERNS`/`DOMAIN_HINTS` (`AbbyAiService.php:18-39`), `searchConcepts()` against `vocab.concept` standard concepts (`:333-370`), Circe `CohortExpression` JSON emission (`buildExpression`/`buildDomainCriterion`, ConditionOccurrence/DrugExposure/DemographicCriteria, gender concept IDs 8532/8507) (`:375-516`), `buildCohortAndSave` → `CohortDefinition` (`:179-211`), `suggestStudyProtocol` (OHDSI study methodology) (`:772+`).
- Agent surfaces `study_agent`/`protocol_evaluator`/`phenotype_interpreter`, `AGENT_ABILITIES` (`studies.*`/`publications.*`), `Study`/`PublicationDraft` subjects, and the `private-abby.study.{id}` Reverb channels are all research-domain.
- `embedding` vector(384)/`all-MiniLM-L6-v2`, `clinical_rag`/`patient_level_local_only` capabilities, `never_send_phi_to_cloud` PHI gating.

**Generalizable seam (port these 1:1):**
- **Proxy contract:** `config.services.ai.url` + `Http::post('/abby/chat')` payload envelope (`message/page_context/page_data/history/user_profile/provider_policy`) and `/agent/sessions` + `/turn` + `/approve` + `/ingest` lifecycle.
- **Scoped-token pattern:** `$user->createToken(name, abilities)` → ship `plainTextToken` to the AI service → persist `token_id` → revoke on failure → enforce via `abilities:` middleware on callback routes. Swap the ability strings (`studies.*`) for hospital-ops scopes.
- **Provider-policy engine:** `AbbyProviderProfile` + `AbbySurfacePolicy` + `AbbyProviderPolicyService` is fully domain-agnostic (capabilities/entitlements/modes/transports, `payloadForSurface`, fallback chain, `simulateRoute`, presets). Only the `SURFACES` list and `surfaceRequirements()` map are domain-named.
- **`AgentProviderResolver`** (`SystemSetting agents.provider_mode` cloud/local/auto over an `ollama` `AiProviderSetting`) — pure infra.
- **Ledgers:** `agent_sessions` (running totals via `/ingest`) and `abby_cloud_usage` (provider-neutral per-call audit) are generic accounting tables; `request_surface`/`provider_profile_id`/`entitlement_type`/`usage_metadata` already decouple from any clinical content.
- **Persistence trio** `abby_conversations`/`abby_messages`/`abby_user_profiles` is generic chat memory; only `expertise_domains`/`research_interests` profile fields are research-flavored (free-form JSON, trivially repurposed).

---


<br>

# A6 — Abby frontend UX/UI inventory

I have complete evidence. Here is the deliverable.

---

# Abby Frontend UX/UI — Architecture Map

Parthenon ships **two distinct Abby systems** that share branding but not code:
- **System A — "Abby chat / RAG companion"** (`src/features/commons/components/abby/*`, `src/components/layout/AbbyPanel.tsx`, `src/stores/abbyStore.ts`): a knowledge-base Q&A assistant. SSE token streaming over a plain `fetch`, no tool calls, no approvals.
- **System B — "Abby Orchestrator / agent copilot"** (`src/features/studies/*`, `src/features/publish/components/agent/*`, `src/components/agent/AgentCopilotShell.tsx`): an action-taking agent with **tool calls + per-tool approval gating**, streaming over **Reverb/Echo private channels**.

The port target (hospital-ops copilot with page-awareness + approvals) is a **fusion**: System A's page-context capture + global dock + source/feedback cards, with System B's Reverb streaming + approval cards.

---

## 1) User-facing surfaces & mount points

| Surface | Component | Mounts | System |
|---|---|---|---|
| **Global slide-over drawer** | `AbbyPanel` (`components/layout/AbbyPanel.tsx:273`) | Once, app-wide in `MainLayout.tsx:103`. `createPortal` to `document.body` (`:580`). Toggled by Header button (`Header.tsx:289`), CommandPalette `Ctrl+Shift+A` (`CommandPalette.tsx:119`), Escape closes (`:303`). | A |
| **Ask-Abby full channel** | `AskAbbyChannel` (default export, `commons/.../AskAbbyChannel.tsx:228`) | Commons feature page (in-page, not global). History sidebar + profile toggle + composer. | A |
| **About/profile panel** | `AbbyProfilePanel` (imported `AskAbbyChannel.tsx:10`, rendered `:638` behind `showProfile`); `AbbyAvatar` "online" status dot (`AbbyAvatar.tsx:24`). | Inside AskAbbyChannel header. | A |
| **Inline @mention** | `AbbyMentionHandler` (`AbbyMentionHandler.tsx:12`) + `dispatchAbbyMentionEvent(text,userName)` (`:145`). Listens to `window` `CustomEvent("commons:message-sent")` (`:82`); `MessageComposer.tsx:144` dispatches. Regex `/@abby\s+(.+)/i` (`useAbby.ts:120`). | Commons chat (`CommonsLayout.tsx:284`). | A |
| **Omnipresent agent dock** | `AbbyCopilotPanel` (`features/studies/components/AbbyCopilotPanel.tsx:23`) — floating launcher when closed (`:62`), docked chat when open. | Once on study page: `StudyDetailPage.tsx:517` `<AbbyCopilotPanel slug={study.slug}/>`. | B |
| **"Ask Abby about this" chip** | `AskAbbyButton` (`AskAbbyButton.tsx:19`), variant `chip|ghost`. `onClick → openWith(prompt)`. | Gate cards / result rows / manuscript (`StudyGatesTab`, `StudyResultsTab`, `StudyManuscriptTab`). | B |
| **Publish copilot side-panel** | `AgentCopilotPanel` (`features/publish/components/agent/AgentCopilotPanel.tsx:12`) wraps shared `AgentCopilotShell`. | `PublishPage.tsx:913`, gated by `publishAgentEnabled`. | B |
| **Shared agent chrome** | `AgentCopilotShell` (`components/agent/AgentCopilotShell.tsx:35`) — presentational `<aside>` w/ approvals + transcript slot + composer. `sendVariant: "publish"|"primary"`. | Used by publish panel; studies v2 has parallel `v2/agent/AgentCopilotPanel.tsx`. | B |
| **Legacy cohort-builder modal** | `AbbyAiPanel` (`features/abby-ai/components/AbbyAiPanel.tsx:17`) — right slide-over, build/refine cohort, `onApply(expression)`. | `CohortDefinitionDetailPage`. Parthenon-specific. | A-legacy |

---

## 2) Conversation rendering

**System A bubbles** (`AskAbbyChannel.tsx`): `UserBubble` (`:106`) / `AbbyBubble` (`:133`). Markdown via `ReactMarkdown` + `remarkGfm` (`:167`). `AbbyResponseCard` (`AbbyResponseCard.tsx:65`) is the canonical card with: name + AI badge + **route badge** (`abby-route-badge`, kinds `local/cloud/fallback/cloud_blocked`, `:103`), `body_html` sanitized via `DOMPurify.sanitize` (`:125`), object-reference chips (`:140`), `AbbySourceAttribution`, `AbbyFeedback`.

- **Source attribution** (`AbbySourceAttribution.tsx:244`): collapsible "N sources", numbered `SourceCard` (`:168`) with relevance bar `SourceScore` (`:150`, `clampScore` 8–100%), click → `resolveAbbySourceNavigation` (`:103`) routes to internal `/cohort-definitions/:id` etc.
- **Feedback thumbs** (`AbbyFeedback.tsx:18`): ▲ helpful / ▼ not-helpful; negative opens category multi-select (`inaccurate_recall|wrong_source|missing_context|too_verbose|hallucination|other`, `:9`) + comment; `onSubmit(AbbyFeedbackRequest)`.
- **Typing indicator** (`AbbyTypingIndicator.tsx:11`): driven by `RagPipelineState.stage` (`analyzing|retrieving|reading|composing|complete|error`) → "thinking"/"replying".

**System B transcript** (`AbbyCopilotPanel.tsx:174`): plain turns (`You`/`Abby`), `whitespace-pre-wrap`, **tool footer** `↳ tool1, tool2` (`:188`). **No markdown, no source cards, no feedback.**
- **Approval cards** (`AbbyCopilotPanel.tsx:141` / `AgentCopilotShell.tsx:61`, `data-testid="abby-approval-card"`/`approval-card`): renders `tool` name + `JSON.stringify(input).slice(0,160)` (the **dry-run preview**), Approve/Reject buttons → `approve(toolUseId, true|false)`. Pending count badged on collapsed launcher (`:72`).
- **Cost**: `lastCostUsd.toFixed(3)` in header (`:93`).

---

## 3) Streaming

**Two transports — this is the key divergence.**

**System A — SSE over `fetch`** (`abbyService.ts:222 queryAbbyStream`, also inlined in `AbbyPanel.tsx:413`):
- `POST /api/v1/abby/chat/stream`, `Accept: text/event-stream`, `Authorization: Bearer <token>` + `credentials:"include"`.
- Manual reader loop: split on `\n`, `line.startsWith("data: ")`, `[DONE]` sentinel; JSON per line carries `{token, suggestions, sources, conversation_id, routing, error}` (`:282`).
- On `!response.ok` → falls back to non-stream `POST /abby/chat` (`:256`, `queryAbby`). `AbortController` cancels in-flight (`useAbby.ts:39`).

**System B — Reverb/Echo private channels** (`useAbbyAgent.ts:39`):
- `getEcho()` (`@/lib/echo`), `echo.private(channelName.replace(/^private-/,""))` (`:44`), idempotent via `subscribedRef`.
- Six events (Zod-parsed via `abbyAgentApi.ts:51`):
  `.agent.text.delta` `{text}` → append; `.agent.tool.start` `{name,input}`; `.agent.turn.done` `{cost_usd,tokens_in,tokens_out,anthropic_session_id}` (→ `invalidateQueries(["study-gates",slug])`, `:60`); `.agent.error` `{message}`; `.agent.approval.request` `{tool_use_id,tool,input}`; `.agent.approval.denied` `{tool_use_id,tool}`.
- Events normalized to `AgentEvent` union and folded by `applyEvent` reducer (`abbyAgentStore.ts:70`); `ensureAssistantTurn` (`:41`) coalesces streamed text into the trailing assistant turn.

---

## 4) Context capture (page-awareness)

`useAbbyContext()` (`hooks/useAbbyContext.ts:51`):
- `ROUTE_CONTEXT_MAP: [RegExp, contextKey, label][]` (`:5`) — ~40 routes → context strings (`cohort_builder`, `data_explorer`, …). `useLocation()` + `useEffect` writes `setPageContext` into `abbyStore` (`:56`).
- `AbbyPanel` reads `pageContext` and ships it as `page_context` in the request body (`AbbyPanel.tsx:424`). Drives **suggested-prompt sets** `CONTEXT_SUGGESTIONS[pageContext]` (`:16`) and context label chip.
- Request also carries `history` (last 10, `:396`) and `user_profile {name, roles}` (`:425`). **No selected-entity/page-data DOM capture** in System A — context is route-derived only.
- System B has **richer context server-side**: the dock passes only `slug`; the python-ai harness reads the study's design/gates/results/manuscript. The page-awareness seam there is the **`slug` + server-side context loader**, not a client payload. `AbbyMentionHandler` / `AskAbbyButton` are the client "selected-entity" seam (they hand a context-specific prompt string).

---

## 5) State stores (Zustand)

**`abbyStore.ts` (System A, persisted)** — `name:"parthenon-abby"`, `partialize → {conversationId}` only (`:87`):
`panelOpen` / `togglePanel` / `setPanelOpen`; `messages: Message[]` (seeded `WELCOME_MESSAGE`) / `addMessage` / `clearMessages`; `conversationId: number|null`; `conversationList: ConversationSummary[]`; `pageContext: string` (default `"general"`); `isStreaming`; `streamingContent` / `appendStreamingContent`.

**`abbyDockStore.ts` (System B dock UI)** — `isOpen`; `queuedPrompt: string|null`; `openWith(prompt?)`; `toggle`; `close`; `consumeQueued()` (`:38`, pop-once). Deliberately separate from agent transcript so any tab can queue without owning the session.

**`abbyAgentStore.ts` (System B agent)** — `agentSessionId:number|null`; `channelName:string|null`; `transcript: TranscriptTurn[]` (`{role, text, tools?:{name,input}[]}`); `isStreaming`; `lastCostUsd:number|null`; `errorMessage`; `pendingApprovals: PendingApproval[]` (`{toolUseId,tool,input}`); `provider:string` (def `"anthropic"`); `actionsEnabled:boolean` (def `true` — CE reads-only hides write affordances, `:30`). Actions: `setSession`, `pushUserMessage`, `applyEvent` (reducer), `reset`. (`publishAgentStore.ts` is a near-identical sibling minus `provider`/`actionsEnabled`, plus `setStreaming`.)

---

## 6) Component/prop contracts to re-implement (Laravel + Inertia/React)

**Endpoints to provide** (System A baseURL `/api/v1`):
- `POST /abby/chat/stream` — SSE; body `{message, page_context, page_data, user_profile{name,roles}, history[], conversation_id?, title?}`; emits `data: {token|suggestions|sources|conversation_id|routing|error}` + `[DONE]`.
- `POST /abby/chat` — non-stream fallback `{reply|message, suggestions, sources, conversation_id, routing}`.
- `GET /abby/conversations?per_page=20`, `GET/DELETE /abby/conversations/:id`.
- `POST /commons/abby/feedback` (`abbyService.ts:333`) body = `AbbyFeedbackRequest`.
- Profile: `GET/PUT /abby/profile`, `POST /abby/profile/reset`.

**Agent endpoints** (System B, slug-scoped `/studies/:slug/agent/sessions`):
- `POST …/sessions` → `{agent_session_id, channel_name, provider, actions_enabled}` (`abbyAgentApi.ts:10`).
- `POST …/:id/messages` `{text, idempotency_key:uuid}`.
- `POST …/:id/approve` `{tool_use_id, approved}`.
- Reverb private channel `private-<channel_name>` broadcasting the six `.agent.*` events.

**Reusable components (carry over near-verbatim):** `AgentCopilotShell` (props: `title, errorMessage, children, onSend, disabled, streaming, inputLabel, sendLabel, sendVariant, approvals[], approveLabel, rejectLabel, onApprove`), `AbbyAvatar`, `AbbyTypingIndicator(pipelineState)`, `AbbyFeedback(messageId, existingFeedback?, onSubmit)`, `AbbySourceAttribution(sources, defaultExpanded?, onSourceClick?)`, `AbbyResponseCard`, the dock launcher pattern, `abbyDockStore`/`abbyAgentStore` reducers, `useAbbyContext` ROUTE map.

**Inertia adaptation notes:** replace TanStack `useQuery`/`useMutation` + `react-router` `useLocation`/`useNavigate` with Inertia equivalents (`usePage().url` for route context; `router.visit`/`<Link>` for source navigation in `resolveAbbySourceNavigation`). Echo/Reverb is already Laravel-native — wire `getEcho()` to Laravel Echo + Reverb. Keep `crypto.randomUUID()` idempotency keys.

---

## Parthenon-specific vs generalizable seam

**Will NOT carry over (rip/replace):**
- `CONTEXT_SUGGESTIONS` & `CONTEXT_LABELS` (`AbbyPanel.tsx:16/211`) and `ROUTE_CONTEXT_MAP` (`useAbbyContext.ts:5`) — all OMOP/OHDSI (cohort, concept-set, achilles, estimation, SCCS, vocabulary). Replace route map + suggestion copy with hospital-ops contexts (ED census, RTDC, OR board, transport).
- `ObjectReferenceType` (`cohort_definition|concept_set|study|analysis_result|data_source|dq_report`) and `resolveAbbySourceNavigation` internal routes (`/cohort-definitions/…`, `/concept-sets/…`) — `abby.ts:27`, `AbbySourceAttribution.tsx:118`.
- Source `collection` labels (`ohdsi, cohort_definitions, concept_sets, study_designs`, `AbbySourceAttribution.tsx:6`).
- `AbbyAiPanel` build/refine **cohort `expression`** + `concept_sets` (`AbbyAiPanel.tsx:240`) — entirely OMOP cohort logic.
- System B tool semantics ("re-evaluate gates", "build study package", Gates timeline, `study-gates` invalidation) — study-publication domain.
- Hardcoded model strings: `"MedGemma1.5:4b"` (`AbbyMentionHandler.tsx:104`), welcome "MedGemma … 39,000+ documentation chunks" (`abbyStore.ts:50`), `abby@parthenon.local`, `/Abby-AI.png` avatar.

**Generalizable seam (the real reusable architecture):**
1. **Transport contracts** — the SSE `{token,suggestions,sources,routing}` shape and the six Reverb `.agent.*` events are domain-agnostic.
2. **`AgentEvent` reducer + `ensureAssistantTurn`** — pure streaming-fold logic.
3. **Approval-gating UX** — `pendingApprovals` + approval cards + `approve(toolUseId, approved)` is fully generic; the only domain coupling is the tool's `input` JSON shown as dry-run.
4. **Dock store split** (`abbyDockStore` vs `abbyAgentStore`) — "any component queues a prompt; one dock owns the session" pattern.
5. **`actionsEnabled`/`provider` gating** — generic read-only vs action deployment toggle.
6. **Route→context→suggestions pipeline** — keep the mechanism, swap the data tables.
7. Presentational primitives (avatar, typing, feedback, source attribution, route badge) — pure UI, only i18n copy/collection labels change.

**Note for synthesis:** Parthenon has unfinished duplication — `features/studies/components/AbbyCopilotPanel.tsx` (Reverb dock) vs `features/studies/components/v2/agent/AgentCopilotPanel.tsx` + shared `AgentCopilotShell` (publish path). A clean port should consolidate on **one** `AgentCopilotShell` driven by one `agentStore` reducer, with the dock-store overlay for omnipresence.

---


<br>

# Z1 — Zephyrus backend domain services & process surfaces

I now have a complete, evidence-based picture. Here is the deliverable.

---

# Zephyrus Backend Domain Services & Process Surfaces — Architecture Map (Eddy Substrate)

## 0. Substrate shape & conventions (applies to every domain)

- **Pattern**: thin Inertia/JSON controllers delegate to a `*Service` that returns a fixed array shape matching a legacy React mock. Services query the **`prod.*` PostgreSQL schema** via `DB::table()`/raw SQL or Eloquent. All read services are documented as *"deterministic and safe on empty tables (returns zeros/empty arrays, never throws)."*
- **Two service families**: (a) **read/projection services** that recompute a mock-identical payload from `prod.*` (EdDashboardService, ServiceHuddleService, CaseManagementService, RoomStatusService, DashboardService); (b) **lifecycle/write services** with audited state machines (TransportOperationsService, EvsOperationsService, BedPlacementService, BarrierService, HuddleService, RtdcService, OperationalActionLifecycleService).
- **Determinism hack**: where `prod.*` lacks a field (patient name, room, chief complaint, care team), services synthesize stable values via `crc32(salt.':'.id) % N` keyed on the row PK. Names/teams come from `App\Support\Hospital\HospitalManifest` (`providerNames($specialty)`, `nurseNames()`, `careTeamNames()`, `transport()`).
- **Routes**: `routes/api.php:64-200` mounts all domain JSON endpoints under `middleware(['web','auth','throttle:60,1'])` with prefixes `rtdc`, `transport`, `evs`, `staffing`, `ops`, `patient-flow`, `command-center`, `facility`. `routes/web.php` mounts Inertia page routes. **This `web,auth` session-guard wrapping on `api.php` is the natural seam for Eddy tool auth** — it already provides `$request->user()`.
- **Existing agent/tool substrate (most important for Eddy)**: `app/Services/Ops/Agents/AgentToolRegistry.php` is a literal **tool registry** with `tools(): array<key,{label,description,read_only,minimum_role}>` and `call(string $toolKey, array $payload, ?User $actor): array`. It enforces `authorizeTool()` (role rank user<admin<superuser) and `redact()` of PHI keys (`patient`,`mrn`,`ssn`,`dob`,`encounter_ref`). **Today it ships 3 READ-ONLY tools only**: `capacity.snapshot`, `data_quality.summary`, `executive_brief.compose` (`AgentToolRegistry.php:24-43`). It explicitly throws if a tool is not `read_only` (`:56-58`). Eddy's write tools = the missing half of this registry, backed by the lifecycle services below.

---

## 1. Domains, services, methods & return shapes

### ED (Emergency Department)
- **`App\Services\Dashboard\EdDashboardService::build()`** (`EdDashboardService.php:41`) → `{edMetrics, performanceMetrics, patientStatusBoard, alertsData:{alerts}}`. Computes from `prod.ed_visits`, `prod.census_snapshots` (`unit_id=1`), `prod.beds`.
  - `edCensus()` → `{staffed_beds,occupied,available,blocked}` (latest `census_snapshots`).
  - `activeVisits($now)` → `{count,waiting,treating,boarding,critical}` (arrived, `departed_at IS NULL`, `esi_level<=2`=critical) `:115`.
  - `performance($now)` → 24h medians via `percentile_cont(0.5)`: `{door_to_provider,door_to_triage,door_to_disposition,door_to_departure,los_admitted,los_discharged,lwbs_pct}` `:198`.
  - `throughput()` → `{lastHour,today}` each `{arrivals,discharges,admissions,leftWithoutBeingSeen}`.
  - `predictions()` → `{arrivals[4×{hour,predicted}], admissions{probability,predictedCount,byService}, bottlenecks[{resource,probability,timeframe,impact}]}` (14-day arrival profile + 7-day admit rate) `:548`.
  - `patientStatusBoard()` → 12 rows `{id,location,chiefComplaint,triageLevel,waitTime,status,nextAction,provider}` `:657`.
  - `alerts()` → threshold-derived `{id,type,title,message,timestamp}` (High Volume ≥90% occ, Boarding ≥3, Wait>30m, LWBS>2%).
- **`App\Services\Ed\TreatmentService::build()`** (`TreatmentService.php:99`) → `{kpis,board,acuityMix,meta}`. Cohort = `provider_seen_at NOT NULL AND departed_at NULL`. `statusFor()` maps disposition→`{Boarding|Admitted|Transfer Pending|Discharge Ready|Admit Decision|In Treatment}` + tone `:217`. KPIs: `inTreatment,awaitingDisposition,boarding,medianTreatmentTime`.
- Sibling ED read services (`app/Services/Ed/`): `TriageService`, `WaitTimeService`, `ArrivalPredictionService`, `AcuityPredictionService`, `ResourceManagementService/ResourceAnalyticsService/ResourceOptimizationService`. Controller: `EDDashboardController` (Inertia, `web.php:136-157`).

### RTDC — IHI Real-Time Demand/Capacity engine (the crown jewel)
- **`App\Services\RtdcService`** (`RtdcService.php`) — the **4-step engine**, all WRITE/upsert to `prod.rtdc_predictions`:
  - `upsertCapacity($unitId,$serviceDate,$horizon,$definite,$probable,$possible)` → weighted discharges (1.0/0.6/0.3) `:30`.
  - `upsertDemand(...,$ed,$or,$transfer,$direct)` → `demand_expected` `:45`.
  - `developPlan($unitId,$serviceDate,$horizon)` → computes signed `bed_need = demand_expected − (available beds + floor(discharges_weighted))` `:57`. Returns `RtdcPrediction`.
  - Backed by route `POST rtdc/units/{unitId}/{capacity|demand|plan}` (`PredictionController`, broadcasts `HuddleUpdated`).
- **`App\Services\HuddleService`** — `openUnitHuddle`/`openHospitalHuddle`/`close($huddleId)` on `prod.huddles`; `hospitalRollup($serviceDate,$horizon)` → `{net_bed_need,total_positive_bed_need,units[{unit_id,unit_name,bed_need,capacity_now,demand_expected}]}` `:40`. Routes `POST rtdc/huddles`, `POST rtdc/huddles/{id}/close`, `GET rtdc/bed-meeting` (`HuddleController`, broadcasts `BedMeetingUpdated`).
- **`App\Services\BarrierService`** — `open(array)` (validates `Barrier::CATEGORIES`) / `resolve($barrierId)` on `prod.barriers`; status `open→resolved`. Routes `GET/POST rtdc/barriers`, `POST rtdc/barriers/{id}/resolve`.
- **`App\Services\BedPlacementService`** — `recommend(BedRequest): RankedRecommendations`; `decide(BedRequest,$action,$chosenBedId,$reason,$decidedBy): BedPlacementDecision` (`BedPlacementService.php:35`). On `accepted|edited` it **re-validates server-side** (`lockForUpdate` on available bed, `BedFeasibility::violation`, throws `BedUnavailableException`/`UnsafePlacementException`), dispatches `CanonicalEvent::encounterStarted` (live census update), sets `bed_request.status='placed'`. Routes `GET rtdc/bed-requests`, `POST rtdc/bed-requests`, `GET .../recommendations`, `POST .../decision`.
- **`App\Services\Rtdc\ServiceHuddleService::build()`** (`ServiceHuddleService.php:100`) → `{patients[], metrics{unitMetrics,careRequirements,acuityStatus}}`. Cohort = active non-ED encounters joined `prod.units`+`prod.beds`; `acuity_tier`→`Critical|Guarded|Stable` `:280`. `unitMetrics`→`{occupancy,availableBeds,pendingAdmissions,expectedDischarges}` (`pendingAdmissions`=`prod.bed_requests status=pending`).
- Other RTDC read services (`app/Services/Rtdc/`): `BedTrackingService`, `DemandForecastService`, `DischargePrioritiesService`, `RiskAssessmentService`, `AncillaryServicesService`, plus analytics (`Performance/Resource/ResourcePlanning/Trends/Utilization`). `ReconciliationService` = step 4 (evaluate, `prod.rtdc_reconciliations`).
- Census read: `CensusController::units` (`GET rtdc/units`). Models: `Unit`, `Bed`, `Encounter`, `CensusSnapshot`, `RtdcPrediction`, `RtdcPlan`, `RtdcReconciliation`, `RtdcRedStretchPlan`, `Huddle`, `Barrier`, `BedRequest`, `BedPlacementDecision`, `GmlosReference`.

### Perioperative / Operations (OR)
- **`App\Services\Operations\CaseManagementService::getData()`** (`CaseManagementService.php:70`) → `{mockProcedures[],specialties{},locations{},stats{totalPatients,inProgress,delayed,completed,preOp}}`. Joins `prod.or_cases`+`services`+`providers`+`rooms`+`locations`+`case_types`+`or_logs`. Anchors on `MAX(surgery_date)`; synthesizes board `phase` (Recovery/Procedure/Pre-Op by index), `journey` 0-100, delay flag (`case_id%7`). Per-case `{id,patient,type,specialty,status,phase,location,startTime,expectedDuration,provider,resourceStatus,journey,staff[],resources[]}`.
- **`App\Services\Operations\RoomStatusService::build()`** (`RoomStatusService.php:53`) → `{rooms[{number,status,currentCase,nextCase,timeRemaining,turnoverTime}],generatedAt}`. `status ∈ {available,in_progress,delayed,turnover}` from a **simulated operative clock** projected onto anchor day (clamps to 10:30 off-hours). `currentCase`→`{patient,procedure,provider,startTime,expectedEndTime,expectedDuration,elapsed,staff,resources,notes,alerts}`.
- `BlockScheduleService` (`prod.block_templates`,`block_utilization`). Perioperative dashboard read: `DashboardController` (`web.php:51`), `Dashboard\PerioperativeMetricsService`. Analytics suite (`app/Services/Analytics/`): `OrUtilizationService`, `BlockUtilizationService`, `PrimetimeUtilizationService`, `RoomRunningService`, `TurnoverTimesService`. OR API: `ORCaseController` (`api.php` `cases/*`), `BlockScheduleController` (`blocks/*`). Models: `ORCase`, `ORLog`, `Room`, `Location`, `Provider`, `Service`, `CaseTiming/Metrics/Resource/Transport/SafetyNote/Measurement`, reference `Reference\*`.

### Operations — EVS, Staffing, Transport (the action-rich domains)
- **Transport** — `App\Services\Transport\TransportOperationsService` (`prod.transport_requests`,`transport_events`):
  - READ: `list($filters):LengthAwarePaginator`, `overview():{metrics,by_type,by_status,queue[12],vendor_options,resource_options,measures}` `:47`, `measures()` (lifecycle-pivoted SLA metrics) `:96`, `serializeRequest()`.
  - WRITE state machine (each in `DB::transaction` + `recordEvent` to `transport_events`): `create($data,$actor)` (status `requested`), `assign($req,{assigned_team,assigned_vendor},$actor)`→`assigned`, `transition($req,$status,$payload,$actor)` (sets `dispatched_at`/`completed_at`), `completeHandoff($req,$data,$actor)`→`handoff_complete`. `ACTIVE_STATUSES` is a 14-state lifecycle (`:17`). Routes: `transport/requests[/{id}/{assign|status|cancel|handoff}]`, `transport/overview` (`api.php:114-129`). Vendor catalog: Ride Health, Uber/Lyft Health, Pulsara, CarePort/WellSky, Aidin (`:352`).
  - `Transport\RegionalTransferService` (`regional.*` tables) — inter-facility transfer + route simulation + agent draft.
- **EVS** — `App\Services\Evs\EvsOperationsService` (`prod.evs_requests`,`evs_events`), mirror of Transport: READ `list/overview` (`{metrics{dirty_bed_turnovers,isolation_cleans},by_type,by_status,queue,resource_options}`); WRITE `create`/`assign`/`transition` (status `requested→queued→assigned→in_progress→completed`; `in_progress` sets `started_at`). `request_type ∈ {bed_clean,discharge_turnover,terminal_clean,...}`, `isolation_required`. Routes `evs/requests[/{id}/{assign|status|cancel}]`.
- **Staffing** — `App\Services\Staffing\StaffingOperationsService` (`prod.staffing_requests`,`staffing_plans`,`staffing_events`). `StaffingController`: `overview/plans/index/store/show/assign/status/cancel/resources`. `staffing_plans` carries `{required_count,scheduled_count,actual_count,status='critical_gap',shift_date}` (consumed by `AgentToolRegistry::staffingGap()`).

### Process Improvement (PDSA / SPC / bottlenecks)
- **`App\Services\DashboardService`** (`DashboardService.php`):
  - `getImprovementStats()` → `{total,activePDSA,opportunities,libraryItems}`.
  - `getBottleneckStats()` → `{bottleneckData[],resourceData[],stats{active,avgResolutionTime,patientImpact}}` `:62`. Five **live** bottleneck detectors: `bottleneckLongStay` (LOS>GMLOS on `prod.encounters`), `bottleneckOrTurnover` (window-fn gap>30m on `or_cases`), `bottleneckBlockedBeds` (`census_snapshots.blocked`), `bottleneckAtRiskTransports`, `bottleneckEdBoarding`. `resourceData` is a **curated static set** (`:374`).
  - `getActiveCycles()`/`getPdsaCycles()`/`getPdsaCycle($id)` → PDSA cards from `prod.pdsa_cycles` (`PdsaCycle` model + `unit`). Phase/progress/metrics deterministically derived; `deriveCycleMetrics()` parses "from X to Y" objective strings `:684`.
  - `getRootCauses()` (`:463`) is **static curated data**; `getOpportunities()`/`getLibraryResources()` read `prod.improvement_opportunities`/`improvement_resources`.
  - `updateWorkflowPreference(User,$workflow)` (the only user-state write).
- WRITE: `DashboardController::pdsaStore` (`POST improvement/pdsa`, `web.php:73`). `ProcessAnalysisController` saves OCEL process-map layouts (`prod.process_layouts`, `ProcessLayout` model). Models: `PdsaCycle`, `OperationalEvent`, `CareJourneyMilestone`, `DiversionEvent`.

### Ops governance / agents (the orchestration layer Eddy plugs into)
- `OperationalActionLifecycleService` — full **action state machine** on `ops.actions`/`ops.approvals`/`ops.recommendations`: `inbox()`, `decideApproval($approval,'approved'|'rejected',...)`, `assign`, `start`, `complete`, `override`, `expire`; `syncRecommendationStatus()` rolls child action states up to the parent recommendation. Routes `ops/approvals/{approval}/decision`, `ops/actions/{action}/{assign|start|complete|override|expire}`.
- `AgentControlPlaneService` — `runCapacityCommander/runDataQualityAgent/runExecutiveBriefingAgent($actor,$objective):AgentRun`. `RulesOnlyAgentRunner` implements `AgentRunner::run(...,callable $planner)`. `OperationsGraphController`: `snapshot/node/recommendations/agentInbox` (`ops/graph/*`). `OperationsSimulationService` + `SimulationController::promote`. `InterventionAttributionService::dashboard()` (before/after outcome attribution).

---

## 2. Candidate Eddy TOOLS → backing method/endpoint (1:1 map)

**READ tools** (extend `AgentToolRegistry::tools()` or wrap services directly):
| Tool | Backing |
|---|---|
| `ed.dashboard` / `ed.treatment_board` | `EdDashboardService::build()` / `Ed\TreatmentService::build()` |
| `rtdc.unit_prediction` | `RtdcPrediction` query (`GET rtdc/units/{id}/prediction`) |
| `rtdc.bed_meeting_rollup` | `HuddleService::hospitalRollup()` (`GET rtdc/bed-meeting`) |
| `rtdc.service_huddle` | `Rtdc\ServiceHuddleService::build()` |
| `rtdc.bed_recommendations` | `BedPlacementService::recommend()` (`GET rtdc/bed-requests/{id}/recommendations`) |
| `ops.or_board` / `ops.room_status` | `Operations\CaseManagementService::getData()` / `Operations\RoomStatusService::build()` |
| `transport.overview` / `evs.overview` / `staffing.overview` | respective `OperationsService::overview()` |
| `capacity.snapshot` / `executive_brief` / `data_quality.summary` | **already exist** in `AgentToolRegistry` |
| `improvement.bottlenecks` / `improvement.pdsa` | `DashboardService::getBottleneckStats()` / `getPdsaCycles()` |
| `ops.agent_inbox` | `OperationalActionLifecycleService::inbox()` |

**ACTION/WRITE tools** (all already have audited, transactional service methods — Eddy is a new caller, not new logic):
| Tool | Backing service method → endpoint |
|---|---|
| `bed.assign` / `bed.decide` | `BedPlacementService::decide()` → `POST rtdc/bed-requests/{id}/decision` (server re-validates, dispatches canonical event) |
| `bed.create_request` | `BedRequest::create` → `POST rtdc/bed-requests` |
| `huddle.open` / `huddle.close` | `HuddleService::openUnitHuddle/openHospitalHuddle/close` → `POST rtdc/huddles[/{id}/close]` |
| `barrier.open` / `barrier.resolve` | `BarrierService::open/resolve` → `POST rtdc/barriers[/{id}/resolve]` |
| `rtdc.upsert_capacity/demand` / `rtdc.develop_plan` | `RtdcService::upsertCapacity/upsertDemand/developPlan` → `POST rtdc/units/{id}/{capacity|demand|plan}` |
| `transport.dispatch` / `transport.assign` / `transport.handoff` / `transport.cancel` | `TransportOperationsService::transition/assign/completeHandoff` → `POST transport/requests/{id}/{status|assign|handoff|cancel}` |
| `room.mark_clean` / `evs.assign` / `evs.dispatch` | `EvsOperationsService::transition/assign` (`status=completed`) → `POST evs/requests/{id}/{status|assign}` |
| `staffing.assign` / `staffing.fill_gap` | `StaffingController::assign/status` → `POST staffing/requests/{id}/{assign|status}` |
| `action.approve` / `action.assign` / `action.complete` / `action.override` | `OperationalActionLifecycleService::decideApproval/assign/complete/override` → `POST ops/{approvals\|actions}/...` |
| `improvement.create_pdsa` | `DashboardController::pdsaStore` → `POST improvement/pdsa` |
| `agent.run_capacity_commander` | `AgentControlPlaneService::runCapacityCommander` → `POST ops/agents/capacity-commander/run` |

The **gating model already exists**: every write endpoint goes through `ops.approvals` for human-in-the-loop (`OperationalActionLifecycleService`), and `AgentToolRegistry` enforces `minimum_role`/PHI redaction. Eddy write tools should emit `ops.actions` rows (status `draft`) rather than mutate domain tables directly, reusing `decideApproval`→`assign`→`complete`.

---

## 3. Live-seeded vs mock

**Fully live from `prod.*`** (computed at request time): ED census/treatment/performance/throughput (`prod.ed_visits`,`census_snapshots`,`beds`); RTDC engine (`rtdc_predictions`,`huddles`,`barriers`,`bed_requests`,`encounters`,`gmlos_references`); Service Huddle roster (`encounters`+`units`+`beds`); OR board & room status (`or_cases`,`or_logs`,`rooms`,`locations`,`services`,`providers`,`case_types`); Transport/EVS/Staffing (full event-sourced lifecycles `*_requests`+`*_events`); Improvement bottleneck **detectors** + PDSA cards + opportunities/library; Ops governance (`ops.*`), agent control plane, intervention attribution.

**Mock / synthesized / static** (load-bearing for a port to replace): (a) **deterministic enrichment** of fields with no source column — patient names, MRNs, chief complaints, treatment rooms, care teams, vitals, pending orders (all `crc32`-seeded; no PII in `ed_visits`/`encounters`/`or_cases` which store only `patient_ref`/`patient_id` tokens). (b) **ED staffing model** (`EdDashboardService::staffing()` — pure ratios 1:9/1:3/1:6, no source table). (c) `DashboardService::getRootCauses()` and `bottleneckResourceData()` — **static curated arrays**. (d) OR board live-state (phase/journey/delay) is **synthesized** because the seed is historical (all cases `COMP`, `journey_progress=0`); `RoomStatusService` uses a **simulated clock**. (e) Improvement OCEL process maps load from JSON files in `sample-pages/OCEL/` (`api.php`/`ProcessAnalysisController`). (f) Transport `vendor_options`/`resource_options`, EVS `resource_options` are **static catalogs**.

## 4. Domain tables Eddy reads/writes (prod & ops schemas)

`prod.` (read+write): `ed_visits, encounters, census_snapshots, units, beds, rooms, locations, providers, services, or_cases, or_logs, case_types, bed_requests, bed_placement_decisions, rtdc_predictions, rtdc_plans, rtdc_reconciliations, rtdc_red_stretch_plans, huddles, barriers, gmlos_references, transport_requests, transport_events, evs_requests, evs_events, staffing_requests, staffing_plans, staffing_events, pdsa_cycles, improvement_opportunities, improvement_resources, process_layouts, operational_events, diversion_events, care_journey_milestones`.
`ops.` (governance/agent write surface): `actions, approvals, recommendations, agent_definitions, agent_runs, agent_tool_calls, agent_approvals, agent_safety_events, agent_evaluations, nodes, edges, state_snapshots, source_freshness, metric_definitions/values/lineage, interventions, intervention_metrics, outcome_attribution, simulation_scenarios/runs/results, constraints, writeback_drafts, data_quality_findings`.
Also: `integration.*`, `raw.*`, `fhir.*`, `flow_realtime.ambient_signal_*`, `regional.*` (transfer network + route sim).

## 5. Process-awareness signals Eddy should watch
- **Capacity**: `census_snapshots.{available,blocked}`, `bed_requests status=pending`, ED boarders (`ed_visits disposition='admitted' AND bed_assigned_at IS NULL`), `rtdc_predictions.bed_need` (signed deficit). All pre-aggregated in `AgentToolRegistry::capacitySnapshot()` with a `riskScore` 0-100.
- **Huddles/barriers**: open `huddles`, open `barriers` (category-coded), `hospitalRollup.net_bed_need`.
- **Bottlenecks**: the 5 detectors in `DashboardService` (long-stay vs GMLOS, OR turnover>30m, blocked beds, at-risk transports, ED boarding) with `impactScore`+`stressScore`.
- **Task queues/SLA**: `transport`/`evs`/`staffing` `overview().queue` + `measures()`/SLA `at_risk` (`needed_at` past, `priority=stat`), `staffing_plans status='critical_gap'`.
- **Governance**: `ops.approvals status=pending`, `ops.actions` overdue (`due_at`<now), `ops.recommendations` open, `ops.source_freshness` (census lag>60m → trust warning).
- **Realtime broadcasts** (Eddy event triggers): `BedMeetingUpdated`, `HuddleUpdated` (Laravel Reverb/Echo), dispatched on every RTDC prediction/huddle write.

## 6. Parthenon-specific concepts that do NOT carry over (and the generalizable seam)
Zephyrus is **clean** of OHDSI/OMOP — there is **no** `omop` schema, cohort, concept, study, vocabulary, CDM, Atlas/WebAPI/Achilles, or population-research code anywhere in `app/Services` or `app/Models`. The closest analogs are **operations-native**: `gmlos_references` (CMS geometric-mean-LOS benchmark, not a CDM vocab), `improvement_opportunities`/`pdsa_cycles` (QI/SPC, not cohort studies), and `ops.interventions`/`outcome_attribution` (before/after operational attribution, explicitly *"not causal proof"* — not CohortMethod/PLP). **FHIR R4 is present but operational only** (`fhir.resource_*`, `PatientFlow\FhirBundleFactory`, `integration.smart_backend_credentials`) for ADT/HL7v2 ingest, not OMOP ETL. **The generalizable seam carried from Parthenon is the agent/tool-governance pattern**: `AgentToolRegistry` (tool catalog + role/PHI gating) + `OperationalActionLifecycleService` (draft→approve→assign→execute→complete with audit) + `AgentRunner`/`AgentControlPlaneService` (run-with-planner, `agent_runs`/`agent_tool_calls` trace tables). That is exactly Eddy's substrate — domain-agnostic, already wired for human-in-the-loop approval — and the *only* thing missing is the set of **write tools** enumerated in §2, each of which already has a transactional, event-recording service method behind it.

---


<br>

# Z2 — Zephyrus frontend layouts, navigation, design system

I have complete evidence. Producing the architecture map.

---

# Zephyrus Frontend Architecture Map — Eddy Dock Integration Reference

## 0. Stack ground truth
- **Inertia.js + React** SPA on Laravel 11. Single root view `app` (`HandleInertiaRequests.php:15`). Client entry `resources/js/app.tsx`.
- **Build:** Vite. Pages auto-resolved by glob `import.meta.glob('./Pages/**/*.{jsx,tsx}')`, **.tsx preferred over .jsx** (`app.tsx:17-20`).
- **Path alias:** `@/` → `resources/js/`.
- State/data: **TanStack Query** (`@tanstack/react-query`), **Zustand** (`@/stores/uiStore`), **Zod** schemas (`@/schemas/rtdc`). UI kit: **HeroUI** (`@heroui/react`), icons `lucide-react` + `@iconify/react`, animation `framer-motion`.

---

## 1. Layout structure — where a global Eddy dock mounts

There is **no persistent app shell** between Inertia navigations — Inertia re-renders the page tree on every visit, and each Page component imports its own layout. Two layout families exist:
- `Layouts/AuthenticatedLayout.tsx` (the canonical authenticated wrapper)
- `Components/Dashboard/DashboardLayout.jsx` (older, used by many `.jsx` pages e.g. `ED/Operations/Triage.jsx:4`)

Pages are inconsistent about which they use, so **neither layout is a reliable single mount point**. The reliable global mount is the **root `Providers` wrapper** in `resources/js/Providers/HeroUIProvider.tsx:16-34`, which wraps **every** Inertia page via `app.tsx:32-34`:

```
<Providers> → QueryClientProvider → HeroUIProvider → ModeProvider → DashboardProvider
  <div className="min-h-screen bg-healthcare-background dark:bg-healthcare-background-dark">{children}</div>
  <ToastProvider />   ← sibling-to-children overlay; THIS is the pattern to copy
```

**Recommendation:** mount `<EddyDock />` as a sibling of `{children}` inside `Providers` (next to the existing `<ToastProvider />`, `HeroUIProvider.tsx:27`). This guarantees presence on every authenticated *and* guest page; gate visibility on `usePage().props.auth.user` to suppress on `/login`. This is preferable to `AuthenticatedLayout`, which not all pages use.

**Precedent for a global blocking overlay:** `ChangePasswordModal` (`AuthenticatedLayout.tsx:78`, source `Components/ChangePasswordModal.jsx`) renders `fixed inset-0 z-[9999]` with `framer-motion`. Note z-index ladder: navbar `z-[65]` (`TopNavbar.tsx:31`), ChangePassword `z-[9999]`. **Eddy dock should sit at ~`z-[80]`** (above navbar, below the password gate).

---

## 2. Inertia page-context — what Eddy can capture about "what the user is viewing"

`usePage()` from `@inertiajs/react` exposes (typed `PageProps` in `types/index.ts:11-22`, re-declared in `types/index.d.ts` referenced as `@/types`):

- **`page.url`** — current path+query, e.g. `/rtdc/bed-tracking` (`TopNavbar.tsx:23`). Primary "where am I" signal.
- **`page.component`** — the Inertia page name string, e.g. `RTDC/BedTracking`, `ED/Operations/Triage` (the glob key, `app.tsx:18`). Best machine-readable view identifier.
- **`page.props.auth`** — `{ user: User|null, roles?: string[], is_admin?: boolean }` (`HandleInertiaRequests.php:34-41`). `User` = `{id, username, name, email, workflow_preference?, must_change_password?, roles?}` (`types/index.ts:1-9`).
- **`page.props.workflow`** — session workflow string (`HandleInertiaRequests.php:42`).
- **`page.props.flash`** — `{message?, error?}` (`:43-46`).
- **`page.props.app`** — `{name, env}` (`:47-50`).
- **`page.props.ziggy`** — `{url, port, defaults, routes}` (`:51-56`). **Ziggy v2** (`tightenco/ziggy ^2.0`, composer.json:19) gives the named-route table; `route()` helper is used in `Pages/Admin/Users/*` only. Eddy can map `page.component`→domain via the same `NAVIGATION` config.

**Domain inference seam:** `config/navigationConfig.ts` exports `isDomainActive(domain, url)` (`:327`) and `NAVIGATION[].matchPrefixes` (e.g. rtdc `['/rtdc','/dashboard/rtdc']`). Eddy can reuse these to label the active domain (RTDC / ED / Perioperative / Improvement / Transport / Staffing / Analytics) without new logic.

Per-page data props are **not standardized** — page components receive their own server props (e.g. BedTracking takes a `unitCensus` override prop, `BedTracking.tsx:53`). There is no global "current entity" prop; Eddy must read `page.component` + `page.url` and optionally page-specific props it knows about.

---

## 3. Design tokens Eddy MUST honor

**Two-System Rule (enforced):** operational UI = **`healthcare-*` blue/slate** tokens. Crimson `#9B1B30` + gold `#C9A227` are brand/focus only — **Eddy is operational chrome, so it must NOT use crimson/gold as primary.**

**Tailwind `healthcare-*` tokens** (`tailwind.config.js:28-132`), each with a `.dark` pair:
- Surfaces: `bg-healthcare-surface` (`#FFFFFF` / dark `#1E293B`), `bg-healthcare-background` (`#F8FAFC`/`#0F172A`), `bg-healthcare-hover` (`#F1F5F9`/`#334155`).
- Text: `text-healthcare-text-primary` (`#1E293B`/`#F8FAFC`), `text-healthcare-text-secondary` (`#475569`/`#CBD5E1`).
- Border: `border-healthcare-border` (`#E2E8F0`/`#334155`).
- Interactive blue: `healthcare-primary` (`#2563EB`/dark `#3B82F6`).
- **Status palette (teal/amber/coral/sky), always paired with icon/arrow/label, never color-alone:** `healthcare-success` (light `#059669` / dark teal `#2DD4BF`), `healthcare-warning` (`#D97706`/amber `#E5A84B`), `healthcare-critical` (`#DC2626`/coral `#E85A6B`), `healthcare-info` (`#0284C7`/sky `#60A5FA`). The CSS-var mirror is `var(--critical|--warning|--success|--info|--text-muted)` via `STATUS_VAR` (`Components/CommandCenter/status.ts`). Use STATUS_VAR for inline `style` color, healthcare-* classes for Tailwind.

**Surface primitive — reuse, do not re-implement** (`Components/ui/Surface.tsx:23`): `Surface` = `rounded-lg`, `bg-healthcare-surface dark:bg-healthcare-surface-dark`, `border-healthcare-border`, `shadow-sm hover:shadow-md` (Quiet-Lift), `transition-all duration-300`, plus a subtle top-down sheen gradient. **Floating elements (Eddy panel/modal) get `shadow-lg`.** Aliases that all delegate to Surface: `Components/CommandCenter/Panel.tsx` (`export { Surface as Panel }`), `Components/ui/Panel.jsx` (title wrapper), `Components/Dashboard/Card.jsx`, `Components/ui/MetricCard.jsx`.

**Typography:** Figtree via `font-sans` only; weights **400/500/600** (`font-normal`/`font-medium`/`font-semibold`) — **no `font-bold`**. Sizes are the remapped Tailwind scale (body `text-sm`=13px, `text-base`=14px; `tailwind.config.js:140-147`) — never `text-[Npx]`. Metrics/IDs `tabular-nums`. (Note: `AuthenticatedLayout.tsx:28-29` still injects a legacy Google-Fonts link for Crimson Pro/Source Serif/IBM Plex Mono — vestigial; the canon is Figtree. Don't propagate.)

**Dark-default:** dark mode resolved from `localStorage('darkMode')` toggling `<html class="dark">` (`AuthenticatedLayout.tsx:34-47`); read via `useDarkMode()` hook (`@/hooks/useDarkMode`, used in `Surface.tsx:16,25`). Eddy must support both themes with `dark:` pairs and gold `:focus-visible`.

**Components to reuse for Eddy's content:** `Section`, `MetricGrid`, `KpiTile`, `Panel`, `EmptyState`, `metric()`, `Band`, `StrainIndex`, `Gauge`, `UnitHeatStrip`, `STATUS_VAR` — all from the **`@/Components/system` barrel** (`Components/system/index.ts`), the "gold-standard kit." `KpiTile` (`Components/CommandCenter/KpiTile.tsx`) is the canonical metric tile (status dot + arrow + sparkline + target + drillHref). Buttons: HeroUI `Button` (`@heroui/react`) for actions; legacy `Components/ui/button.jsx` exists.

---

## 4. JSX vs TSX — migration state

**Mixed, mid-migration.** Pages: **70 `.jsx`, 67 `.tsx`** (`resources/js/Pages`). Shared infra (`app.tsx`, layouts `AuthenticatedLayout.tsx`/`AuthLayout.tsx`/`GuestLayout.tsx`, `Providers`, `Surface.tsx`, `navigationConfig.ts`, `types/`, `lib/echo.ts`, the entire `Components/system` + `Components/CommandCenter` kit) is **TypeScript**. Older domain pages and `Components/Dashboard/*`, `Components/Common/PageContentLayout.jsx`, `ChangePasswordModal.jsx`, ED pages are **JSX**. **Write Eddy in `.tsx`** to match the canonical infra layer.

**Component library / patterns present:**
- Surface primitive + `Card`/`Panel`/`MetricCard` aliases.
- Metric system: `KpiTile`, `MetricGrid`, `metric()` factory producing `KpiMetric` (`{key,label,value,display,status,trajectory:{points[],direction},target,targetDisplay,unit,definition,drillHref,detail,sourceTrust,caption}` — see `KpiTile.tsx:115-200`, types in `@/types/commandCenter`).
- Layout primitives: `Section` (heading), `PageContentLayout` (the one gutter owner, `p-4`, `PageContentLayout.jsx:4`), `EmptyState`.
- Nav: `TopNavbar` → `NavMegaMenu` (mega-dropdown per domain) + `UserMenu` + `CommandPalette` (Cmd+K, opened via `useUIStore.setCommandPaletteOpen`, `TopNavbar.tsx:26,73,83`). The command palette flattens `NAVIGATION` via `flattenNavigation(isAdmin)` (`navigationConfig.ts:346`) — a natural place to also register Eddy actions.

---

## 5. Existing real-time & notifications

**Real-time: YES, Laravel Echo + Reverb is wired** (`resources/js/lib/echo.ts`):
```ts
export const echo = new Echo({ broadcaster: 'reverb',
  key: VITE_REVERB_APP_KEY, wsHost: VITE_REVERB_HOST,
  wsPort: VITE_REVERB_PORT(8080), forceTLS: VITE_REVERB_SCHEME==='https',
  enabledTransports: ['ws','wss'] });
window.Echo = echo;  // pusher-js shim
```
Env vars: `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME`. **It is NOT imported in `bootstrap`/`app.tsx`** — `echo` is lazily imported only by `features/rtdc/hooks.ts`. So Echo is real but **scoped to RTDC**, not app-global. Only consumer: `useLiveCensus()` (`features/rtdc/hooks.ts:71-106`), which subscribes to channels **`unit.{id}`** (events `.census.updated`, `.huddle.updated`) and **`hospital.beds`** (event `.bedmeeting.updated`), validates payloads with `censusUpdatedEventSchema` (Zod), and uses snapshot-on-reconnect to invalidate TanStack queries. **Seam for Eddy push:** import `echo` and subscribe to a new channel (e.g. private `eddy.{userId}`); follow the same invalidate-on-reconnect pattern (Reverb does not replay).

**Notifications/Toast: YES** — `react-hot-toast`. `ToastProvider` (`components/ui/Toast.tsx`) renders `<Toaster position="top-right">` and is mounted globally in `Providers` (`HeroUIProvider.tsx:27`). Eddy can fire `toast(...)` directly. (Note its inline dark styling uses raw hex `#1C1C20`/`#F0EDE8` rather than healthcare tokens — a minor canon drift to avoid replicating.)

**HTTP:** axios pre-configured with CSRF (`X-XSRF-TOKEN`, `withCredentials`), `baseURL '/'`, and a **401→redirect-to-`/login` interceptor** (`bootstrap.ts:6-19`). Eddy's API calls inherit this.

---

## 6. Parthenon-specific / non-portable callouts

This is **Zephyrus** (hospital ops), already distinct from Parthenon — there is **no OMOP/OHDSI/cohort/study surface here**. Domain vocabulary is hospital-operations: RTDC (real-time demand/capacity), ED, Perioperative/OR, Transport, Staffing, Improvement/PDSA (`navigationConfig.ts`). The generalizable seams that DO carry to any product:
- **Mount seam:** `Providers` sibling-overlay pattern (`HeroUIProvider.tsx:27`).
- **Context seam:** `usePage().{component,url,props.auth}` + `isDomainActive`/`matchPrefixes`.
- **Design seam:** `@/Components/system` barrel + `Surface` + `healthcare-*`/`STATUS_VAR` tokens.
- **Real-time seam:** `lib/echo.ts` `echo` singleton + per-channel TanStack invalidation.
- **Toast seam:** global `react-hot-toast`.

**Do-not-touch:** the auth system (`AuthenticatedLayout.tsx:78` ChangePasswordModal, `routes/auth.php`, Resend temp-password flow) is locked by `.claude/rules/auth-system.md`. Eddy must not alter the auth overlay; mount alongside it. The `AuthenticatedLayout.tsx:28-29` legacy serif/mono Google Fonts link is vestigial and contradicts the Figtree canon — do not extend it.

**Key file index for the porting team:**
- Mount: `resources/js/Providers/HeroUIProvider.tsx:16`, `resources/js/app.tsx:32`
- Layouts: `resources/js/Layouts/AuthenticatedLayout.tsx`, `resources/js/Components/Dashboard/DashboardLayout.jsx`
- Nav config: `resources/js/config/navigationConfig.ts`
- Surface/tokens: `resources/js/Components/ui/Surface.tsx`, `tailwind.config.js:28-147`
- Metric kit: `resources/js/Components/system/index.ts`, `resources/js/Components/CommandCenter/KpiTile.tsx`, `.../status.ts`
- Real-time: `resources/js/lib/echo.ts`, `resources/js/features/rtdc/hooks.ts`
- Toast: `resources/js/components/ui/Toast.tsx`
- Shared props: `app/Http/Middleware/HandleInertiaRequests.php:30`
- Types: `resources/js/types/index.ts`, `resources/js/types/commandCenter.ts`

---


<br>

# Z3 — Zephyrus infra/config/realtime/auth/deploy

I now have complete evidence. Producing the deliverable.

---

# Zephyrus Integration Substrate — Architecture Map (for an Eddy FastAPI service)

## 0. Verdict at a glance

| Concern | State today | Evidence |
|---|---|---|
| Real-time (Reverb + broadcasting + Echo) | **Installed & wired end-to-end** (server, client, 3 events, dispatch sites). Default ships `null`; `.env.example` sets `reverb`. | `composer.json:14`, `config/reverb.php`, `config/broadcasting.php:18`, `resources/js/lib/echo.ts`, `app/Events/Rtdc/*` |
| Sanctum | **Package present, config present, but `HasApiTokens` NOT on User → `createToken()` unavailable today.** Guard = `web` (SPA cookie mode), not token mode. | `composer.json:15`, `config/sanctum.php:36`, `app/Models/User.php:13` |
| Queues / Redis | DB queue default; Redis container + config present; **no Horizon**. | `config/queue.php:16`, `docker-compose.yml:60`, no horizon in composer |
| DB | pgsql, connection `pgsql`, schema `prod` (`search_path=prod,public`), **Postgres 16**. | `config/database.php:101-114`, `docker-compose.yml:45` |
| Deploy | Apache + php-fpm at `/var/www/Zephyrus`; `deploy.sh` builds-in-dev + rsync, **skips migrations**. | `deploy.sh:43-75` |
| Python services | **None in the app runtime** (only offline `data-loaders/*.py` ETL + OCEL generators). Eddy is net-new. | `find *.py` |
| Agent runtime | **Synchronous, rules-only, no LLM, no Python** — `RulesOnlyAgentRunner implements AgentRunner`. This interface is the exact Eddy swap seam. | `app/Services/Ops/Agents/AgentRunner.php`, `RulesOnlyAgentRunner.php` |

---

## 1. Real-time / broadcasting

**Fully present, not net-new.**

- **Server:** `laravel/reverb ^1.10` (`composer.json:14`). `config/reverb.php` complete — server binds `0.0.0.0:8080` (`REVERB_SERVER_HOST`/`PORT`), app creds from `REVERB_APP_ID/KEY/SECRET`, scaling-via-Redis available but `REVERB_SCALING_ENABLED=false`.
- **Broadcaster:** `config/broadcasting.php:18` — `'default' => env('BROADCAST_CONNECTION', 'null')`. **Framework default is `null`** (broadcasts no-op), but `.env.example:50` sets `BROADCAST_CONNECTION=reverb`. So real prod must have it set or events silently drop.
- **Client:** `resources/js/lib/echo.ts` — `laravel-echo ^2.3.7` + `pusher-js ^8.5.0` (`package.json:69,72`), `new Echo({ broadcaster:'reverb', key: VITE_REVERB_APP_KEY, wsHost: VITE_REVERB_HOST, wsPort: VITE_REVERB_PORT, ... })`, exported as `echo` and attached to `window.Echo`. Vite env typed in `resources/js/vite-env.d.ts`.
- **Events (3, all `ShouldBroadcast`):** `app/Events/Rtdc/CensusUpdated.php`, `HuddleUpdated.php`, `BedMeetingUpdated.php`.
  - `CensusUpdated`: channel `new Channel('unit.'+unit_id)` (**public**), `broadcastAs()='census.updated'`, payload `{unit_id, captured_at(ISO), staffed_beds, occupied, available, blocked, acuity_adjusted_capacity}`.
  - Channels are **intentionally public**, PHI-free aggregates — `routes/channels.php` defines **zero** `Broadcast::channel()` callbacks by design (documented there). `bootstrap/app.php:18` registers `channels: routes/channels.php`.
- **Dispatch sites:** `broadcast(new BedMeetingUpdated(...))` in `Api/Rtdc/HuddleController.php:44`; `broadcast(new HuddleUpdated($unitId, $pred->toArray()))` ×3 in `Api/Rtdc/PredictionController.php:32,41,53`; `CensusUpdated` via `app/Rtdc/EventDispatcher.php`.

**Eddy seam:** Eddy can push live updates two ways — (a) **HTTP→Pusher protocol** to Reverb directly (Reverb speaks the Pusher app protocol; Eddy posts to Reverb's HTTP API using `REVERB_APP_ID/KEY/SECRET`), or (b) call a thin Laravel endpoint that `broadcast()`s. Since channels are public + PHI-free, no channel-auth handshake is needed. New events should mirror the `broadcastAs()` + flat-array `broadcastWith()` convention; the React client subscribes via the shared `echo` singleton.

---

## 2. Auth — and how Eddy acts as the user

**Session/web is the live auth.** `config/sanctum.php:36` sets `'guard' => ['web']`. Every protected API route group is `middleware(['web','auth','throttle:60,1'])` (`routes/api.php:57,84,109,...`) — i.e. **Inertia SPA cookie auth, not bearer tokens** (comment at `routes/api.php:80-83` documents the `web` group is needed so the session cookie authenticates; axios sends `X-XSRF-TOKEN`).

**Protected MediCosts-paradigm flow** (do-not-modify per `.claude/rules/auth-system.md`):
- `routes/auth.php` — temp-password registration (`RegisteredUserController`), forced `change-password` (`ChangePasswordController` GET `show`/POST `update`, names `password.change`/`password.change.update`), OIDC (`auth/oidc/redirect|callback` → `OidcController`).
- `AuthenticatedSessionController::store()` (`store()` body lines 35-58): authenticates, `session()->regenerate()`, stores `username`/`user_id` in session, defaults `workflow_preference='superuser'`, and **redirects to `password.change` when `must_change_password` is true**.
- `config/services.php:38-53` — OIDC (Authentik) config, default-off (`OIDC_ENABLED=false`), groups `Zephyrus Users`/`Zephyrus Admins`.

**User model gap (critical for Eddy):** `app/Models/User.php:11-13` — `use HasFactory, HasRoles, Notifiable;`. **`Laravel\Sanctum\HasApiTokens` is absent**, so `$user->createToken()` does not exist yet and `personal_access_tokens` is not in play. Table is `prod.users`; auth cols `must_change_password`, `role`, `is_active`, `phone`, `workflow_preference`, `username`.

**How Eddy mints a scoped token to act as the user — recommended path:**
1. Add `HasApiTokens` to `App\Models\User` (additive; the only model change required). Sanctum is already installed (`composer.json:15`) and configured.
2. When the SPA invokes an agent, Laravel issues a **short-TTL, ability-scoped** token: `$user->createToken('eddy-run', ['ops:read'], now()->addMinutes(10))`. Set `config/sanctum.php:49` `'expiration'` or pass per-token `expiresAt`. Abilities map to the existing **read-only tool allowlist** (`tool_allowlist` in `AgentControlPlaneService::definitionCatalog()`).
3. Eddy calls back into Laravel's existing read-only API (`/api/ops/graph/snapshot`, `/api/rtdc/units`, etc.) as `Authorization: Bearer <token>`, which Sanctum resolves to the acting user via the bearer fallback (works even with `guard=web`). Eddy never gets DB creds; it acts strictly through the governed Laravel surface.
4. Alternatively (no model change) issue a custom signed JWT — `firebase/php-jwt ^7.0` is already a dependency (`composer.json:11`, used by OIDC) — but Sanctum + `HasApiTokens` is the idiomatic, ability-scoped route and is the recommendation.

---

## 3. Queues / Redis / async infra for background agent runs

- **Default queue = `database`** (`config/queue.php:16`), `.env.example:23` even ships `QUEUE_CONNECTION=sync`. Failed jobs `database-uuids`. Redis queue connection defined (`config/queue.php:66-73`) but not default.
- **Redis:** container `redis:7-alpine` (`docker-compose.yml:60`), full `config/database.php:161-188` (default db 0, cache db 1, phpredis client). Cache default is `file` (`config/cache.php:18`), not Redis.
- **No Horizon** (absent from composer + config). Only one queued job exists: `app/Jobs/ReconcileRtdcPredictions` (`implements ShouldQueue`), scheduled daily 02:00 in `bootstrap/app.php:35`.
- **Agent runs are NOT queued today.** `RulesOnlyAgentRunner::run()` executes **synchronously** inside the request — creates `ops.agent_runs` row (`status='running'`), invokes the planner closure, saves `completed`/`blocked`/`failed`, returns. No job, no worker.

**Eddy seam:** long-running LLM agent calls should move off the request. Two options: (a) dispatch a Laravel queued job that calls Eddy and writes back to `ops.agent_runs` (reuse the existing `database` queue + the daily worker pattern), or (b) Laravel POSTs to Eddy, Eddy runs async and callbacks `/api/ops/agents/runs/{run}` writes + a Reverb broadcast on completion. Either way the `AgentRun` lifecycle (running→completed) is already modeled.

---

## 4. Database

- Connection name **`pgsql`** (`config/database.php:101`), `'search_path' => 'prod,public'`, `'schema' => 'prod'`, `sslmode=prefer`. App tables live in **`prod`** schema (e.g. `prod.users`, `app/Models/User.php:20`); ops/agent tables in **`ops`** schema (`ops.agent_runs`, `app/Models/Ops/AgentRun.php:11`).
- **Postgres 16** (container `postgres:16-alpine`, `docker-compose.yml:45`). `.env.example` prod target is `demo.zephyrus.care:5432` db `zephyrus`, user `postgres`, schema `prod`.
- Per project memory: prod runs against a **shared host PG**; deploy.sh excludes `.env`, so prod DSN is the deployed `.env`. (Globally: prefer `claude_dev` on host PG17 for DB ops.)

**Ops/agent schema for Eddy to read/write (`ops.*`):** `agent_runs` (PK `agent_run_id`, `agent_run_uuid`, `agent_definition_id`, `actor_user_id`, `status`, `mode`, `objective`, `input_payload`/`output_payload`/`summary_payload` JSON, `blocked_reason`, `started_at`/`completed_at`), plus `agent_definitions`, `agent_tool_calls`, `agent_evaluations`, `agent_safety_events`, `agent_approvals`, `operational_actions`, `recommendations`, `metric_*`, `simulation_*`, `operations_node/edge`. JSON columns are Laravel `array`-cast.

---

## 5. Deploy reality + adding the Eddy FastAPI service

**Confirmed from `deploy.sh`:**
- Must run from `/home/smudoshi/Github/Zephyrus`; refuses on dirty tree or behind-remote.
- `NODE_ENV=production npm run build` (`deploy.sh:45`) → **build-in-dev**, then `rsync -av` to `/var/www/Zephyrus/` excluding `node_modules/.git/.env/tests/deploy.sh/.github/storage logs` (`deploy.sh:49-57`).
- `chown -R www-data:www-data` the whole tree (`deploy.sh:63`) — Apache/php-fpm runs as www-data.
- Clears caches, `systemctl restart apache2`, verifies via `Host: zephyrus.acumenus.net`. **No `php artisan migrate`** — migrations are out-of-band (matches memory: full deploy skips migrations; use an explicit step).

**`docker-compose.yml` is the DEV stack only** (nginx:8084, php fpm w/ healthcheck, node dev:5176 [profile dev], postgres:16 :5484, redis:7 :6384, mailhog [profile dev]). Network `zephyrus` (bridge). `docker/php/Dockerfile` = `php:8.3-fpm-alpine` + `pdo_pgsql`. **There is no Reverb service and no worker service in compose** — Reverb/queue:work are not containerized; in prod they'd be systemd units alongside Apache (consistent with the Apache+php-fpm prod model).

**Adding Eddy (net-new):**
- **Dev:** add an `eddy` service to `docker-compose.yml` (network `zephyrus`): `build docker/eddy/Dockerfile` (python:3.12-slim + uvicorn/fastapi), `ports ["${EDDY_PORT:-8090}:8000"]`, `env_file [.env]`, `depends_on postgres/redis`. Laravel reaches it at `http://eddy:8000` over the shared bridge.
- **Prod:** mirror the existing pattern — a **systemd unit** (`eddy.service`) running `uvicorn` on a local port, fronted by an Apache `ProxyPass /eddy/ → 127.0.0.1:8000` in the `zephyrus.acumenus.net` vhost (same shape Apache already uses elsewhere). Because `deploy.sh` rsyncs the whole repo, an `eddy/` dir would ship to prod; a `--eddy` flag (build venv + `systemctl restart eddy`) is the clean extension, but `deploy.sh` would need a small additive change.
- **Add a Reverb + queue:work systemd unit** if not already present, since compose doesn't run them.
- **No existing Python service** to collide with; `data-loaders/*.py` are offline ETL, not a runtime.

**Parthenon-specific things that do NOT carry over:** none of OHDSI/OMOP CDM/cohort/study/Achilles exists in Zephyrus — this is a self-contained hospital-ops Laravel app. The generalizable seam Eddy plugs into is the **`App\Services\Ops\Agents\AgentRunner` interface** (swap `RulesOnlyAgentRunner` for an `EddyAgentRunner` that calls FastAPI), the **`AgentToolRegistry`** (read-only, allowlisted, PHI-redacting tools — `tools()`, `call($toolKey,$payload,$actor)`, `redact()`, `authorizeTool()` with `minimum_role` gating), the **`ops.agent_runs` lifecycle + golden evaluations** (`expected_tool_called`, `no_write_tools`, `phi_minimized`, `prompt_injection_guardrail`), and the **public Reverb channels** for live push. Safety policy (`approval_required_for_writes`, `phi_minimization`, `prompt_injection_blocking`, `stale_data_guardrails`) is already enforced in `AgentControlPlaneService` and must be preserved when LLM-backing the runner.

---

## 6. `.env.example` keys + what Eddy adds

**Present** (`.env.example`): `APP_*`; `DB_CONNECTION=pgsql / DB_HOST=demo.zephyrus.care / DB_PORT=5432 / DB_DATABASE=zephyrus / DB_USERNAME=postgres / DB_PASSWORD / DB_SCHEMA=prod`; `BROADCAST_DRIVER=log` (legacy) **and** `BROADCAST_CONNECTION=reverb`; `CACHE_DRIVER=file`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=file`; `REDIS_HOST/PASSWORD/PORT`; mail (`MAIL_*`, mailpit), `RESEND_API_KEY`; AWS; `REVERB_APP_ID=zephyrus / REVERB_APP_KEY=zephyrus-key / REVERB_APP_SECRET=zephyrus-secret / REVERB_HOST=localhost / REVERB_PORT=8080 / REVERB_SCHEME=http`; `VITE_REVERB_APP_KEY/HOST/PORT/SCHEME`; OIDC (`OIDC_ENABLED=false`, `OIDC_DISCOVERY_URL`, `OIDC_CLIENT_ID/SECRET`, `OIDC_REDIRECT_URI`, `OIDC_ALLOWED_GROUPS="Zephyrus Users"`, `OIDC_ADMIN_GROUPS="Zephyrus Admins"`).
**Absent but referenced in code:** `SANCTUM_STATEFUL_DOMAINS`, `SANCTUM_TOKEN_PREFIX` (`config/sanctum.php`), `REDIS_QUEUE_CONNECTION`, `DB_QUEUE_CONNECTION` (`config/queue.php`).

**New keys Eddy needs** (add to `.env.example` with safe defaults per global rules):
- `EDDY_BASE_URL=http://eddy:8000` (dev) / `http://127.0.0.1:8000` (prod)
- `EDDY_ENABLED=false` (ship disabled, mirror the OIDC pattern)
- `EDDY_SHARED_SECRET=` / `EDDY_CALLBACK_TOKEN=` — HMAC/bearer for Laravel↔Eddy mutual auth
- `EDDY_TIMEOUT_SECONDS=30`, `EDDY_PORT=8090` (compose)
- LLM provider creds consumed by Eddy (e.g. `ANTHROPIC_API_KEY` / `OLLAMA_BASE_URL` per the AI stack) — held in Eddy's env, **never** exposed to the Laravel/Vite client.
- If switching agent runs to bearer tokens: confirm `SANCTUM_STATEFUL_DOMAINS` includes `zephyrus.acumenus.net` and set token TTL.

**Key footguns for the porting team:** (1) `BROADCAST_CONNECTION` defaults to `null` in config — must be `reverb` in prod or all broadcasts silently no-op. (2) `User` lacks `HasApiTokens` — bearer-token agent auth is a one-line additive change, not a refactor. (3) compose has **no Reverb/worker service** — those are out-of-band (systemd) in prod. (4) `deploy.sh` does **not** migrate — Eddy's `ops.*` additions need a separate migrate step. (5) Agent runs are **synchronous + rules-only** today; LLM-backing them means moving to a queue/callback to avoid request timeouts.

---
