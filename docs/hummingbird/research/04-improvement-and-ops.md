# Hummingbird Research 04 — Process Improvement + Ops/AI-Agent + Command Center

> Inventory for the **Hummingbird** mobile companion (Kotlin/Android + Swift/iOS).
> Covers the Process Improvement subsystem, the Ops/AI-agent (human-in-the-loop)
> layer, and the Command Center. Field-level where the source allows.
> Source commit context: branch `main`, repo `Zephyrus` (Laravel 11 + React/Inertia,
> PostgreSQL). Researched 2026-06-26. **No source files were modified.**

---

## 0. Executive summary & headline counts

| Bucket | Count | Notes |
|---|---|---|
| **Ops domain models** (`app/Models/Ops/`) | **23** | Agent control plane (7), action/approval lifecycle (4), intervention/attribution (4), metric trust (3), simulation (3), graph + state (4 incl. constraints/snapshots). |
| **PI domain models** (`app/Models/`) | **4** | `PdsaCycle`, `ProcessLayout`, `Barrier`, `OperationalEvent`. |
| **Ops API endpoints** (`/api/ops/*`) | **16** | 6 reads, 10 mutations (run x3, approval decision, 5 action-lifecycle, simulation promote). |
| **Command Center endpoints** | **2** | `GET /dashboard` (Inertia page), `GET /api/command-center/drilldown` (JSON). |
| **PI web routes** | **8** | improvement dashboard, bottlenecks, process, root-cause, process layout/viewport (3), PDSA index/show. |
| **Ops console Inertia pages** | **2** | `Ops/AgentInbox`, `Ops/ExecutiveBrief`. |
| **PI Inertia pages/components** | **18** | `Pages/Improvement/*` incl. PDSA subtree. |
| **Mutating endpoints total (this scope)** | **10 Ops + ~6 PDSA (Inertia)** | See §2. |

**The single most important finding for mobile:** the **human-in-the-loop approval
workflow is real and fully wired**, but it lives in **one desktop page** —
`resources/js/Pages/Analytics.jsx` (the "Opportunity Portfolio" at
`/analytics/opportunities` + "Workbench" at `/analytics/workbench`), specifically the
`GraphRecommendationsPanel` and `SimulationWorkbenchPanel` components. The Ops
`AgentInbox` page only **displays** pending approvals read-only and explicitly tells
users to go to the Opportunity Portfolio to act. There is currently **no mobile-shaped
approval surface anywhere** — this is the #1 greenfield opportunity for Hummingbird.

**Second finding:** the **Ops layer is production-grade** (real DB tables, real
lifecycle service with row locks and state machine, real recommendation engine).
The **Process Improvement layer is largely placeholder** — `DashboardService`
PI methods return empty/zero stubs, most PI pages render imported mock data, and PDSA
editing UIs (`Show.jsx` "Edit Cycle", `PDSACycleManagementPage` "Save") are
non-functional. PDSA write paths that *do* work are Inertia `router.post` calls
(barriers, discharge failures, create cycle) whose server routes are **not present** in
`routes/web.php` as inspected (see §2.4 caveat).

**Third finding:** the AI agents are **rules-only, read-only by design** (`mode:
rules_only`, `read_only: true`, LLM disabled). They never write; they emit
recommendations/briefs. All actual state change requires an explicit human approval —
this is the safety spine and is highly relevant to how Hummingbird frames "AI
suggestions vs. human decisions."

---

## 1. Data elements (models + key fields + relationships)

### 1.1 Process Improvement models

#### `PdsaCycle` — `prod.pdsa_cycles` (PK `pdsa_cycle_id`)
Plan-Do-Study-Act improvement cycle (the Model Improvement primitive).
Migration `2026_06_22_000040`:
- `title` (string), `unit_id` (FK → `prod.units`), `status` (string, default `active`;
  **CHECK constraint**: `planned | active | completed | abandoned`), `owner` (string,
  nullable), `objective` (text, nullable), `started_at`, `completed_at` (timestamps),
  `is_deleted` (bool).
- **Note:** the DB schema is *flat* (single status + objective). The richer
  plan/do/study/act *stage* structure (per-stage actions, findings, metrics) that the
  React pages display is **front-end mock shape only** — not columns. A mobile PDSA
  model should treat `status` as the stage indicator and expect plan/do/study/act
  payloads to be synthesized client-side until backed.
- Relationships: `belongsTo Unit`; `hasMany Ops\Intervention` (via `pdsa_cycle_id`) —
  this is the bridge that ties PI cycles to measured Ops interventions.

#### `Barrier` — `prod.barriers` (PK `barrier_id`)
Patient-flow barrier (blocks discharge/placement). Migration `2026_06_20_000050`:
- `encounter_id` (FK → `prod.encounters`, nullable), `unit_id` (FK → `prod.units`),
  `category` (string; **`CATEGORIES = ['medical','logistical','placement','social']`**),
  `reason_code` (string, nullable), `description` (text), `owner` (string), `status`
  (string, default `open`; comment says `open | resolved`), `opened_at`, `resolved_at`,
  `is_deleted`.
- Scope: `scopeOpen()` → `status = open AND is_deleted = false`.
- Relationship: `belongsTo Unit`.
- **Note:** this `prod.barriers` model is the RTDC barrier (also surfaced in PI PDSA
  "Barriers" tab). The PDSA `BarriersTab.jsx` uses a *different* status vocabulary
  (`identified/in_progress/resolved/blocked`) against mock data — a vocabulary
  mismatch to reconcile.

#### `OperationalEvent` — `prod.operational_events` (PK `operational_event_id`)
Append-only event stream (`UPDATED_AT = null`). Migration `2026_06_20_000030`:
- `event_id` (uuid, unique — idempotency key), `type` (string; e.g.
  `EncounterStarted | EncounterTransferred | EncounterDischarged | BedStatusChanged |
  AcuityChanged`), `encounter_ref` (string, nullable, indexed), `payload` (jsonb),
  `occurred_at` (timestamp), `created_at` only.
- This is the substrate the Ops graph projector and recommendation engine read from.

#### `ProcessLayout` — `process_layouts` (default/public schema; PK `id`)
Persists a user's saved process-mining diagram layout (node positions/viewport).
Migrations `2025_02_17_180108` + `_195730`:
- `user_id` (FK → users, cascade), `process_type` (string 50), `layout_data` (jsonb),
  `hospital` (default `Virtua Marlton Hospital`), `workflow` (default `Admissions`),
  `time_range` (default `24 Hours`). Unique constraint over the filter tuple.
- Relationship: `belongsTo User`. **Desktop-only artifact** (mobile won't drag layouts).

### 1.2 Ops — graph & state foundation

#### `OperationsNode` — `ops.nodes` (PK `graph_node_id`)
A canonical operational entity projected from prod tables.
- `node_uuid`, `node_type` (80), `canonical_key` (160, unique), `display_name`,
  `source_schema/source_table/source_pk` (provenance), `status`, `source_priority`
  (smallint, default 100), `current_state` (jsonb), `metadata` (jsonb),
  `last_observed_at`, `is_active`.
- Rel: `hasMany outgoingEdges/incomingEdges` (OperationsEdge).

#### `OperationsEdge` — `ops.edges` (PK `graph_edge_id`)
- `from_node_id`/`to_node_id` (FK nodes, cascade), `edge_uuid`, `edge_type` (100),
  `weight` (decimal 8,4), `metadata`, `valid_from`/`valid_to`, `is_active`.
- Rel: `belongsTo fromNode/toNode`.

#### `StateSnapshot` — `ops.state_snapshots` (PK `state_snapshot_id`)
Immutable snapshot of the whole graph at a moment (baseline for simulations).
- `snapshot_uuid`, `scope_type`/`scope_key`, `captured_at`, `node_count`/`edge_count`,
  `state_hash` (128), `state_payload` (jsonb), `metadata`.

#### `OperationalConstraint` — `ops.constraints` (PK `constraint_id`)
Hard/soft operating constraints made explicit to the recommendation engine.
- `constraint_uuid`, `constraint_type` (100), `scope_type`/`scope_key`, `hard_or_soft`
  (default `hard`), `severity` (default `warning`), `status` (default `active`),
  `expression` (jsonb), `metadata`.

### 1.3 Ops — recommendations, actions, approvals (THE HITL CORE)

#### `Recommendation` — `ops.recommendations` (PK `recommendation_id`)
An AI/rules-generated suggestion. Migration `2026_06_25_000020`:
- `recommendation_uuid`, `recommendation_type` (100; e.g. `ed_boarding`,
  `bed_pressure`, `blocked_beds`, `or_pacu_pressure`, `transport_sla_risk`,
  `flow_barrier`, `staffing_gap`, `stale_source_feed`, `simulation_action_plan`),
  `scope_type` (80) / `scope_key` (160), `title`, `rationale` (text),
  `confidence` (decimal 5,4 → 0.0000–1.0000), **`risk_level`** (default `low`;
  observed values `low | medium | high | critical`), **`status`** (default `draft`),
  `expected_impact` (jsonb — `{metric, direction, ...}`), `evidence` (jsonb — graph
  nodes, source tables, facts, generated_at), `created_by_source` (e.g.
  `rules`, `simulation:operations_simulation_service`).
- Rel: `hasMany actions` (OperationalAction), `hasMany interventions`.
- **Status lifecycle (derived from child actions** by
  `OperationalActionLifecycleService::syncRecommendationStatus`): `draft → approved →
  assigned → executing → completed` (or terminal `rejected | expired | overridden`).

#### `OperationalAction` — `ops.actions` (PK `action_id`)
The executable unit attached to a recommendation; the thing that gets approved &
worked. Migrations `2026_06_25_000020` + `2026_06_26_000020` (lifecycle extension):
- `action_uuid`, `recommendation_id` (FK), `action_type` (100; e.g.
  `create_capacity_huddle_item`, `review_bed_placement_gap`, `request_evs_bed_readiness`,
  `protect_or_pacu_flow`, `escalate_transport_dispatch`, `assign_barrier_owner`,
  `mitigate_staffing_gap`, `promote_simulation_action_plan`),
  `subject_node_id`/`target_node_id` (FK nodes), **`status`** (default `draft`),
  `payload` (jsonb — `{owner, route, instruction, ...}`), `completion_payload` (jsonb),
  `owner_name` (160), `assigned_to_user_id`, `approved_by_user_id`,
  `executed_by_user_id`, `override_reason` (text), and timestamps
  `approved_at / assigned_at / due_at / expires_at` (default now+8h) `/ executed_at /
  completed_at / expired_at / overridden_at`.
- Rel: `belongsTo recommendation, subjectNode, targetNode`; `hasMany approvals`;
  `hasMany interventions`.
- **Action status state machine** (`OperationalActionLifecycleService`):
  `draft → (approval decided)` → `approved → assigned → executing → completed`.
  Terminal: `rejected | expired | overridden`. Transitions guarded (e.g. only
  `approved/assigned` can `start`; terminal actions can't be overridden/expired).

#### `Approval` — `ops.approvals` (PK `approval_id`)
The human-in-the-loop gate on an action. Migration `2026_06_25_000020`:
- `approval_uuid`, `action_id` (FK, cascade), **`status`** (default `pending`; →
  `approved | rejected`), `requested_by_user_id`, `decided_by_user_id`, `reason`
  (text), `requested_at`, `decided_at`.
- Rel: `belongsTo action`. **This is the record Hummingbird approves on the go.**
- Created automatically by the recommendation engine for every materialized action
  (reason seeded: *"Human approval required before executing graph-backed operational
  action."*).

### 1.4 Ops — interventions & outcome attribution (did it work?)

#### `Intervention` — `ops.interventions` (PK `intervention_id`)
A tracked change whose impact is measured before/after. Migration `2026_06_26_000050`:
- `intervention_uuid`, **FKs**: `recommendation_id`, `action_id` (unique — 1 per
  action), `pdsa_cycle_id` (→ `prod.pdsa_cycles`), `simulation_scenario_id`.
- `intervention_type` (120), `scope_type/scope_key`, `title`, `status` (default
  `measuring`), `owner_name`, `hypothesis` (text), `attribution_method` (default
  `before_after`), `comparison_strategy` (default `before_after`), `confidence_level`
  (default `directional`), `confidence_language` (text), the baseline/followup window
  timestamps (started/ended x2), `evidence_payload`, `stratification_payload`.
- Rel: `belongsTo recommendation/action/pdsaCycle/simulationScenario`; `hasMany
  metrics`; `hasOne attribution`. **This is the link between PI (PDSA) and Ops impact.**

#### `InterventionMetric` — `ops.intervention_metrics` (PK `intervention_metric_id`)
- `intervention_id` (FK), `metric_key`, `label`, `measure_type` (default `outcome`),
  `unit` (default `count`), `direction` (default `down`), `baseline_value`,
  `followup_value`, `delta_value`, `delta_pct`, `status` (default `neutral`),
  `is_primary` (bool), window timestamps, `source_payload`.

#### `OutcomeAttribution` — `ops.outcome_attribution` (PK `outcome_attribution_id`)
- `intervention_id` (FK, unique), `attribution_method`, `comparison_strategy`,
  `confidence_level`, `confidence_score` (decimal 5,2), `confidence_language`,
  `sample_size`, `balancing_summary` (jsonb), `caveats` (jsonb), `comparison_options`
  (jsonb), `executive_summary` (text), `calculated_at`.
- Generated by `InterventionAttributionService` (36KB — the largest Ops service).

### 1.5 Ops — metric trust / lineage (data governance)

- **`MetricDefinition`** — `ops.metric_definitions`: `metric_definition_uuid`,
  `metric_key` (unique), `label`, `domain`, `definition` (text), `owner`, `unit`,
  `target_value`, `target_display`, `direction` (default `neutral`), `cadence`
  (default `live`), `status`, `metadata`. Rel: `hasMany lineage, values`.
- **`MetricValue`** — `ops.metric_values`: `metric_definition_id`, `metric_key`,
  `measured_at`, `period_start/end`, `grain` (default `snapshot`), `value`, `display`,
  `status`, `dimension_payload`, `source_hash`, `metadata`.
- **`MetricLineage`** — `ops.metric_lineage`: source provenance — `source_key`,
  `source_schema/table/column`, `freshness_column`, `transform_name`, `transform_step`,
  `confidence_weight`, `source_filter`, `metadata`. (Powers the
  `/api/analytics/metrics/{metricKey}/lineage` endpoint — "where did this number come
  from?")
- **`SourceFreshness`** — `ops.source_freshness`: `source_key` (unique),
  `source_label`, `source_schema/table`, `freshness_column`, `latest_observed_at`,
  `expected_lag_minutes` (1440), `warning_lag_minutes` (10080), `record_count`,
  `status` (default `warning`), `checked_at`, `metadata`. → "stale data" guardrails.
- **`DataQualityFinding`** — `ops.data_quality_findings`: `finding_uuid`, `check_key`,
  `check_label`, `status`, `severity`, `source_key`, `detail`, `measured_value`,
  `threshold_value`, `opened_at`, `resolved_at`, `metadata`.

### 1.6 Ops — simulation (what-if)

- **`SimulationRun`** — `ops.simulation_runs`: `simulation_run_uuid`,
  `baseline_snapshot_id` (FK state_snapshots), `scope_type/key`, `status` (default
  `completed`), `started_at/completed_at`, `baseline_payload`, `summary_payload`,
  `created_by_user_id`. Rel: `belongsTo baselineSnapshot`; `hasMany scenarios`.
- **`SimulationScenario`** — `ops.simulation_scenarios`: `simulation_scenario_uuid`,
  `simulation_run_id` (FK), `scenario_key` (e.g. `no_action`, intervention bundles),
  `title`, `assumption` (text), `status` (default `modeled`), `intervention_payload`
  (jsonb), `promoted_at`, `promoted_recommendation_id` (FK recommendations). Rel:
  `belongsTo run, promotedRecommendation`; `hasMany results`.
- **`SimulationResult`** — `ops.simulation_results`: `simulation_scenario_id` (FK),
  `metric_key`, `baseline_value`, `projected_value`, `delta_value`, `unit`, `status`,
  `result_payload`.

### 1.7 Ops — agent control plane (governed AI)

- **`AgentDefinition`** — `ops.agent_definitions`: `agent_definition_uuid`, `agent_key`
  (unique), `label`, `description`, `mode` (default `rules_only`), `status` (default
  `active`), `read_only` (default **true**), `minimum_role` (default `user`),
  `tool_allowlist` (jsonb), `safety_policy` (jsonb). Catalog (3 agents, see §4.5).
- **`AgentRun`** — `ops.agent_runs`: `agent_run_uuid`, `agent_definition_id`,
  `actor_user_id`, `status` (default `running`; → `completed | blocked`), `mode`,
  `objective` (text), `input_payload`, `output_payload`, `summary_payload`,
  `blocked_reason`, `started_at/completed_at`. Rel: `hasMany toolCalls, approvals
  (agent), evaluations, safetyEvents`.
- **`AgentToolCall`** — `ops.agent_tool_calls`: `agent_run_id`, `tool_key`, `status`
  (default `started`), `read_only`, `request_payload`/`response_payload` (redacted),
  `error_message`, `started_at/completed_at`.
- **`AgentApproval`** — `ops.agent_approvals`: `agent_run_id`, `approval_key`, `status`
  (default `pending`), `requested_by/decided_by_user_id`, `reason`, `requested_at/
  decided_at`. (Distinct from `ops.approvals`; gates agent-proposed writes — currently
  unused since agents are read-only.)
- **`AgentEvaluation`** — `ops.agent_evaluations`: `agent_run_id`, `evaluation_key`
  (e.g. `expected_tool_called`, `no_write_tools`, `phi_minimized`,
  `prompt_injection_guardrail`), `status` (pass/fail), `score` (decimal 5,2), `detail`,
  `payload`. ("Golden evals" run on every agent run.)
- **`AgentSafetyEvent`** — `ops.agent_safety_events`: `agent_run_id`, `event_type`
  (e.g. `prompt_injection`), `severity` (default `warning`), `status` (default `open`;
  → `blocked`), `detail`, `input_excerpt`, `payload`.

### 1.8 Relationship map (high-value chains for mobile)

```
Recommendation 1─* OperationalAction 1─* Approval        (HITL gate)
Recommendation *─1 ...generated by RulesEngine / SimulationService
OperationalAction 1─1 Intervention 1─* InterventionMetric
Intervention *─1 PdsaCycle            (PI ↔ Ops bridge)
Intervention 1─1 OutcomeAttribution   ("did it work" + confidence)
SimulationRun 1─* SimulationScenario 1─* SimulationResult
SimulationScenario ─promote→ Recommendation (+ Action + Approval)
AgentRun 1─* {ToolCall, Evaluation, SafetyEvent, AgentApproval}
MetricDefinition 1─* {MetricValue, MetricLineage}; SourceFreshness governs trust
OperationsNode *─* OperationsEdge ; StateSnapshot = frozen graph
```

---

## 2. API & web endpoints

### 2.1 Ops API — `routes/api.php`, prefix `/api/ops`, middleware `web,auth,throttle:60,1`

| Method | Path | Controller@method | Purpose | Mutation? |
|---|---|---|---|---|
| GET | `/api/ops/graph/snapshot` | `OperationsGraphController@snapshot` | Rebuild + serialize whole ops graph snapshot | read |
| GET | `/api/ops/graph/nodes/{node}` | `OperationsGraphController@node` | Node detail + timeline (by id or `canonical_key`) | read |
| GET | `/api/ops/recommendations` | `OperationsGraphController@recommendations` | Generate (rules) + return recommendations + summary | read* (regenerates/persists recs) |
| GET | `/api/ops/agent-inbox` | `OperationsGraphController@agentInbox` | Regenerate recs then return approval/action inbox | read* |
| GET | `/api/ops/agents/definitions` | `AgentController@definitions` | List agent catalog (key,label,mode,readOnly,toolAllowlist,safetyPolicy,minimumRole) | read |
| POST | `/api/ops/agents/capacity-commander/run` | `AgentController@runCapacityCommander` | Run Capacity Commander agent (body: `objective?`) | **mutation** (creates AgentRun) |
| POST | `/api/ops/agents/data-quality/run` | `AgentController@runDataQuality` | Run Data Quality agent (`objective?`) | **mutation** |
| POST | `/api/ops/agents/executive-briefing/run` | `AgentController@runExecutiveBriefing` | Compose executive brief (`objective?`) | **mutation** |
| GET | `/api/ops/agents/runs/{run}` | `AgentController@show` | Fetch a single agent run (status, output, toolCalls, evaluations, safetyEvents) | read |
| **POST** | `/api/ops/approvals/{approval}/decision` | `OperationalActionController@decideApproval` | **Approve/reject** an action (body: `decision: approved\|rejected`, `reason?`) | **mutation (HITL)** |
| POST | `/api/ops/actions/{action}/assign` | `OperationalActionController@assign` | Assign owner (`owner_name` req, `assigned_to_user_id?`, `due_at?`, `expires_at?`) | mutation |
| POST | `/api/ops/actions/{action}/start` | `OperationalActionController@start` | Mark action `executing` | mutation |
| POST | `/api/ops/actions/{action}/complete` | `OperationalActionController@complete` | Complete (`completion_payload?`, `note?`) | mutation |
| POST | `/api/ops/actions/{action}/override` | `OperationalActionController@override` | Override (`reason` req) → terminal | mutation |
| POST | `/api/ops/actions/{action}/expire` | `OperationalActionController@expire` | Expire (`reason?`) → terminal | mutation |
| POST | `/api/ops/simulation-scenarios/{scenario}/promote` | `SimulationController@promote` | Promote a what-if scenario → new Recommendation + Action + Approval | mutation |

> **Conflict semantics:** all lifecycle mutations return **HTTP 409** with `{error}` on
> illegal transition (RuntimeException). Success returns `{data: serializedAction}`.
> `decideApproval` body validated to `approved|rejected` only. These are the contracts
> a mobile client must implement (idempotency = enforced by status guards, not tokens).

**`inbox` response shape** (`OperationalActionLifecycleService::inbox`, used by
`/agent-inbox` and the in-scope `OperationalActionController@inbox` — note the latter has
no route registered, only the lifecycle service is invoked via `/agent-inbox`):
```json
{ "generatedAtIso": "...",
  "summary": { "pendingApprovals", "activeActions", "approvedActions",
               "assignedActions", "executingActions", "overdueActions" },
  "approvals": [ { approvalId, approvalUuid, actionId, status, reason,
                   requestedByUserId, decidedByUserId, requestedAtIso, decidedAtIso,
                   action: {serializedAction} } ],
  "actions":   [ { actionId, actionUuid, recommendationId, recommendation:{id,uuid,
                   type,title,riskLevel,status}, type, status, ownerName,
                   assignedToUserId, payload, completionPayload, overrideReason,
                   isOverdue, approvedAtIso, assignedAtIso, dueAtIso, expiresAtIso,
                   executedAtIso, completedAtIso, expiredAtIso, overriddenAtIso,
                   approvals:[...] } ] }
```

**`serializeRun` (agent) shape:** `{ agentRunId, agentRunUuid, agentKey, label, status,
mode, objective, blockedReason, output, summary, startedAtIso, completedAtIso,
toolCalls:[{toolKey,status,readOnly,errorMessage,...}],
evaluations:[{evaluationKey,status,score,detail}],
safetyEvents:[{eventType,severity,status,detail}] }`.

### 2.2 Command Center endpoints

| Method | Path | Controller@method | Purpose | Shape |
|---|---|---|---|---|
| GET | `/dashboard` (name `dashboard`) | `CommandCenterController@index` | Renders Inertia `Dashboard/CommandCenter` with `data` = `CommandCenterDataService::build()`. Sets session `workflow=superuser`. | Inertia page |
| GET | `/api/command-center/drilldown` | `CommandCenterController@drilldown` | Synthetic ≥90-day drill-down (`focus?` ≤120 chars, `days?` 1–180, default 90) | JSON (`commandCenterDrilldownSchema`) |

Payload contracts are fully Zod-defined in
`resources/js/types/commandCenter.ts` (page = `commandCenterDataSchema`; drilldown =
`commandCenterDrilldownSchema`). Key sub-schemas: `kpiMetricSchema`
(`key,label,value,unit,display,target,status,trajectory{points,direction,goodWhenDown},
drillHref,lineageHref,lineageSummary,sourceTrust{score,status,...}`), `strainStateSchema`
(surge level 0–4), `unitCensusSchema`, `forecastStateSchema` (occupancy curve + net beds
by unit), `objectiveSchema` (OKRs), `commandCenterOpportunitySchema`,
`commandCenterEventSchema` (with `recommendedAction`, `avoidableBedDays`,
`patientSafetyDomains`). `statusLevels = critical|warning|success|info|neutral`.

### 2.3 Process Improvement / process-mining endpoints

| Method | Path (route name) | Controller@method | Purpose |
|---|---|---|---|
| GET | `/dashboard/improvement` (`dashboard.improvement`) | `DashboardController@improvement` | Inertia `Dashboard/Improvement` + `stats` (currently all zeros) |
| GET | `/improvement/bottlenecks` (`improvement.bottlenecks`) | `DashboardController@bottlenecks` | Inertia `Improvement/Bottlenecks` + `bottlenecks` stats |
| GET | `/improvement/root-cause` (`improvement.root-cause`) | `DashboardController@rootCause` | Inertia `Improvement/RootCause` + `rootCauses` |
| GET | `/improvement/process` (`improvement.process`) | `ProcessAnalysisController@index` | Inertia `Improvement/Process` + `savedLayout` |
| GET | `/improvement/api/nursing-operations` | `ProcessAnalysisController@getNursingOperations` | Process-map JSON (nodes/edges/metrics) by hospital/workflow/timeRange |
| GET | `/api/improvement/api/nursing-operations` | *(closure in api.php)* | **Always** returns `bed_placement_process_map.json` regardless of `workflow` param; fallback minimal mock |
| POST | `/improvement/process/layout` (`...process.saveLayout`) | `ProcessAnalysisController@saveLayout` | Persist drag layout (204) — DESKTOP |
| GET | `/improvement/process/layout` (`...process.getLayout`) | `ProcessAnalysisController@getLayout` | Fetch saved layout |
| POST | `/improvement/process/viewport` (`...process.saveViewport`) | `ProcessAnalysisController@saveViewport` | Persist viewport state — DESKTOP |
| GET | `/improvement/pdsa` (`improvement.pdsa.index`) | `DashboardController@pdsaIndex` | Inertia `Improvement/PDSA/Index` (cycles=[]) |
| GET | `/improvement/pdsa/{id}` (`improvement.pdsa.show`) | `DashboardController@pdsaShow` | Inertia `Improvement/PDSA/Show` + `cycle` (empty stub) |

### 2.4 PDSA write paths (front-end Inertia `router`) — CAVEAT

The PDSA React components fire these Inertia mutations, but **matching server routes were
NOT found** in `routes/web.php` (only GET pdsa index/show exist). Treat as *intended/not
yet wired* — Hummingbird must not assume they work until backed:
- `POST /improvement/pdsa` (create cycle — `CreatePDSACycleModal`)
- `POST /improvement/pdsa/{cycleId}/barriers`, `POST .../barriers/{barrierId}/status`,
  `DELETE .../barriers/{barrierId}` (`BarriersTab`)
- `POST /improvement/pdsa/{cycleId}/discharge-failures`,
  `DELETE .../discharge-failures/{failureId}` (`DischargeFailuresTab`)

### 2.5 Adjacent Analytics endpoints that drive the HITL UI

The Opportunity Portfolio / Workbench (`Analytics.jsx`) loads ops data via the Analytics
section API and acts on it via the `/api/ops/*` mutations above:
- `GET /api/analytics/opportunities`, `GET /api/analytics/workbench`,
  `GET /api/analytics/data-quality`, `GET /api/analytics/metrics/{metricKey}/lineage`
  (these return the `recommendations`/`simulation`/`impact`/`agent` payloads consumed by
  the panels). All under `analytics` controller `renderSection`/API.

---

## 3. Pages / features

### 3.1 Ops console (in-scope, `resources/js/Pages/Ops/`)

#### `AgentInbox.tsx` — route `/ops/agent-inbox` (`OpsConsoleController@agentInbox`)
- **Purpose:** governed AI-agent console — agent roster + run + read-only inbox of
  pending approvals & active actions.
- **Data:** React Query hooks (`resources/js/features/ops/{api,hooks,types}.ts`):
  `useAgentInbox()` → `GET /api/ops/agent-inbox`; `useAgentDefinitions()` →
  `GET /api/ops/agents/definitions`; `useRunAgent()` → `POST .../run`.
- **Viz:** 4 `MetricTile`s (Pending approvals / Active / Assigned / Overdue), agent
  cards (label, read-only badge, description, tool-allowlist chips), pending-approvals
  list (title + risk-level `ToneBadge`), active-actions list (status badge + overdue
  flag + completed check).
- **Actions:** **only "Run"** (3 runnable agents: `capacity_commander`,
  `data_quality_agent`, `executive_briefing_agent`); after a run the card shows
  status/headline/eval badges/safety-event badges. **No approve/reject/assign here** —
  empty state links to `/analytics/opportunities` ("Review and approve in the
  Opportunity Portfolio").
- **Mobile:** GLANCEABLE (counts) + ACTIONABLE (run agent) but the *decisions* are not
  here. The 4 summary tiles are an ideal mobile glance.

#### `ExecutiveBrief.tsx` — route `/ops/executive-brief`
- **Purpose:** read-only executive synthesis composed by the Executive Briefing Agent.
- **Data:** `useRunAgent()`; **auto-fires `POST /api/ops/agents/executive-briefing/run`
  on mount** — the POST response *is* the page (no separate GET). Refresh re-runs it.
- **Viz:** headline banner (tone by status); panels — **Situation/root-causes**
  (per-domain), **Recommended plan (governed)** (3 tiles: pendingApprovals / draft
  actions / open recommendations + top recommendations w/ risk badges), **Measured
  impact** (interventions, net bed gain, primary improved, confidence + language),
  **Source lineage & trust**, **Governance trace** (tool calls + evaluations). Blocked
  path shows guardrail banner.
- **Actions:** "Refresh brief" only. Read-only by design.
- **Mobile:** GLANCEABLE / NOTIFY (the morning executive brief is a perfect push/widget;
  see §6). Composed server-side, mobile just renders it.

#### `components.tsx`
- Shared presentational primitives: `Tone` (`critical|warning|success|info|neutral`),
  `normalizeTone()` (maps API strings → tone; **`high`→warning, `medium`/`low`→info,
  `critical`→critical**), `ToneBadge`, `MetricTile`, `Panel`. The status→tone mapping
  is reusable verbatim in the mobile design system.

### 3.2 The actual HITL page (CRITICAL, out-of-named-scope but in functional scope)

#### `resources/js/Pages/Analytics.jsx` — `/analytics/opportunities` ("Opportunity Portfolio") + `/analytics/workbench` ("Workbench")
This 74KB page is where humans approve/act. Three key panels:

**`GraphRecommendationsPanel`** (rendered when section = `opportunities`) — the approval
console. For each recommendation (top 6): title, rationale, **risk-level pill**,
**status pill**, confidence %, graph-node count, owner, approval status. The action
button set is **status-driven** (this is the entire HITL UI in one place):
- pending approval → **Approve** (`POST /api/ops/approvals/{approvalId}/decision`
  `{decision:'approved'}`) and **Reject** (`{decision:'rejected', reason:'Rejected from
  Operations Intelligence review.'}`).
- `action.status === 'approved'` → **Assign** (`POST /api/ops/actions/{id}/assign`
  `{owner_name}`).
- `'assigned'` → **Start** (`POST .../start`).
- `'executing'` → **Complete** (`POST .../complete` `{note}`).
- On success calls `onLifecycleChange()` → bumps a refresh nonce to re-pull data.

**`SimulationWorkbenchPanel`** (section = `workbench`): baseline tiles (net beds, ED
boarders, dirty/blocked beds, PACU holds), scenario table (net beds, risk pill,
interventions), and per-scenario **Promote** button (`POST
/api/ops/simulation-scenarios/{scenarioId}/promote`) → mints a recommendation+action+
approval. `no_action` scenario can't be promoted.

**`ImpactAttributionPanel`** (section = `workbench`): renders measured intervention
impact cards (from `OutcomeAttribution`/`InterventionMetric`).

**`DataQualityAgentPanel`** (section = `data-quality`): findings + rules from the data
quality agent.

> **Implication:** Hummingbird's approval flow can call the *exact same* `/api/ops/*`
> endpoints this page uses. The reject reason is currently hardcoded on web — mobile
> should collect a real reason (the API already accepts `reason`).

### 3.3 Command Center pages

#### `Dashboard/CommandCenter.tsx` — `/dashboard`
- **Purpose:** house-wide operations command center shell (demand/capacity/flow/
  forecast). Validates Inertia `data` prop via `safeParseCommandCenterData`; **refreshes
  via `router.reload({only:['data']})` on a 45s interval** + manual; staleness model
  (`STALE_MS`, `AGING_MS`, 15s tick) drives aging/stale banners; `RoleSwitcher` in
  header. Delegates to `CommandCenterView`.
- **`CommandCenterView.tsx`:** role-aware (`command` vs `executive` from Zustand
  `commandCenterStore`). Band order differs by role; executive sees `OkrScoreboard` +
  detailed tiles, command sees `UnitHeatStrip` + heat strip. Refresh + stale "Retry now"
  banner; SR-only recovery announcement.
- **Components** (`resources/js/Components/CommandCenter/`): `Band`, `KpiTile` (gauge or
  big number + trajectory + source-trust badge + drill link), `Gauge`, `HeroWall`,
  `StrainIndex` (surge 0–4), `ForecastCurve` (Recharts 24h occupancy + confidence band),
  `UnitHeatStrip`, `OkrScoreboard`, `RoleSwitcher`, `Panel` (=Surface), `states`
  (error/empty/freshness), `status` (status→CSS-var map).
- **Mobile:** GLANCEABLE — the StrainIndex/house-status gauge, hero KPIs, and forecast
  are premier mobile glance content. Drill-downs (`/api/command-center/drilldown`) are
  ACTIONABLE/secondary. No mutations.

### 3.4 PI dashboards & pages (`resources/js/Pages/Improvement/` + `Dashboard/Improvement.jsx`)

(Full per-file detail captured during research; summarized by mobile weight here.)

**Simple list/status (mobile-friendly):** `Index.jsx`, `Active.jsx`, `Library.jsx`,
`Opportunities.jsx`, `Opportunities/Index.jsx`, `PDSA/Index.jsx`, `PDSADashboard.jsx`,
`PDSA/Show.jsx`. Most render *imported mock data*, not props (only `Show.jsx`,
`Library.jsx`, `Opportunities.jsx` are prop-driven; `PDSACycleManagementPage` does a
mock lookup by `id`).

**Heavy / desktop-oriented (defer or read-only on mobile):**
- `Process.jsx` (HEAVIEST): reactflow process-mining canvas, drag-layout + reset FAB,
  modals, 256px side nav, tabs (process-map/variants/statistics/optimization). Loads
  static `*_process_map.json` or `/improvement/api/nursing-operations`.
- `RootCause.jsx` (HEAVY): 3-pane "human-in-the-loop AI assistant" OCEL workbench with a
  faked chat (setTimeout keyword matching), tall fixed panels (`h-[950px]/500/350`),
  free-text analysis, Export/Publish stubs.
- `Bottlenecks.jsx`: wide 2-col tables (top bottlenecks + resource utilization),
  hardcoded data, read-only.

**Working write paths (best PI companion value, but server routes unconfirmed — §2.4):**
- `CreatePDSACycleModal` → `POST /improvement/pdsa`.
- `BarriersTab` → add / change-status / delete barrier.
- `DischargeFailuresTab` → add / delete failure event.

**Non-functional editors (gaps):** `Show.jsx` "Edit Cycle" (no handler),
`PDSACycleManagementPage` "Save Changes" (`console.log` TODO), `CareIssuesModal`
(returns `null`). `DashboardService` PI methods return empty stubs
(`getImprovementStats` → all zeros, `getPdsaCycle` → empty strings).

#### `Dashboard/Improvement.jsx`
- Nav cards (Overview/Opportunities/Library/Active) using `stats.*`; "Process
  Intelligence" tiles (6 domains, hardcoded health scores), `DischargeProcessFailures`,
  Resource Utilization, Care Transition, `ChronicCarePanel`. Clicking a tile opens
  `ProcessIntelligenceModal`. No mutations, no API beyond `stats` prop.

#### `Dashboard/Superuser.jsx`
- 21-line shell wrapping `DashboardOverview` (static module grid). No data/actions.

---

## 4. Approval / human-in-the-loop workflow (the mobile-critical spine)

### 4.1 Lifecycle (state machine)

```
[rules/sim engine generates Recommendation]
        │  materializeAction() → OperationalAction(status=draft, expires_at=+8h)
        │  + Approval(status=pending, reason="Human approval required…")
        ▼
   Approval.pending ──approve──▶ Action.approved ──assign──▶ Action.assigned
        │                                                        │
        └──reject──▶ Action.rejected (TERMINAL)            ──start──▶ Action.executing
                                                                     │
                                                              ──complete──▶ Action.completed (TERMINAL)

   Any non-terminal Action ──override(reason)──▶ overridden (TERMINAL)
                           ──expire(reason)────▶ expired (TERMINAL)
   (TERMINAL = completed | rejected | overridden | expired)
```

- **Recommendation.status is derived** from its actions by `syncRecommendationStatus`:
  any executing → `executing`; all completed → `completed`; all rejected → `rejected`;
  all expired → `expired`; all overridden → `overridden`; any assigned → `assigned`;
  any approved → `approved`; else `draft`.
- **Guards (return 409 on violation):** approve/reject only on `pending` approvals (row
  locked `lockForUpdate` inside a DB transaction); assign only from `approved|assigned`;
  start only from `approved|assigned`; complete from `approved|assigned|executing`;
  override/expire only on non-terminal.
- **Overdue:** computed (`due_at` past AND not terminal) — surfaced as
  `isOverdue` + `overdueActions` count. Actions default `expires_at = now()+8h`.

### 4.2 Statuses reference

| Entity | Field | Values |
|---|---|---|
| Recommendation | `status` | `draft, approved, assigned, executing, completed, rejected, expired, overridden` |
| Recommendation | `risk_level` | `low, medium, high, critical` |
| OperationalAction | `status` | `draft, approved, assigned, executing, completed, rejected, expired, overridden` |
| Approval | `status` | `pending, approved, rejected` |
| Intervention | `status` | `measuring, …` (default `measuring`) |
| AgentRun | `status` | `running, completed, blocked` |
| AgentApproval | `status` | `pending, approved, rejected` (currently unexercised) |

### 4.3 Who can act (authorization)

- All `/api/ops/*` routes require `web` + `auth` (session). No fine-grained policy gate
  on the action lifecycle endpoints themselves — any authenticated user can hit them;
  `decided_by_user_id`/`approved_by_user_id` are stamped from `request->user()->id`.
- Agents carry `minimum_role` (`user`) checked in `AgentToolRegistry::authorizeTool`
  via `roleAllows`. Agent objectives are screened for unsafe instructions
  (prompt-injection / PHI patterns) and **blocked** before any tool runs.

### 4.4 Where it happens today vs. where Hummingbird fits

| Surface | Today |
|---|---|
| **Decide approvals / drive action lifecycle** | `Analytics.jsx` `GraphRecommendationsPanel` (`/analytics/opportunities`) — desktop only |
| **Promote simulations** | `Analytics.jsx` `SimulationWorkbenchPanel` (`/analytics/workbench`) — desktop only |
| **View pending (read-only)** | `Ops/AgentInbox` + `Ops/ExecutiveBrief` (counts + lists) |
| **Run agents** | `Ops/AgentInbox` |
| **Mobile** | **Nothing.** Greenfield. |

### 4.5 Agent catalog (all read-only, rules-only, LLM disabled)

| key | label | tool | safety_policy |
|---|---|---|---|
| `capacity_commander` | Capacity Commander | `capacity.snapshot` | approval_required_for_writes, phi_minimization, prompt_injection_blocking, stale_data_guardrails |
| `data_quality_agent` | Data Quality Agent | `data_quality.summary` | (same) |
| `executive_briefing_agent` | Executive Briefing Agent | `executive_brief.compose` | (same) |

Every run writes **golden evaluations** (`expected_tool_called`, `no_write_tools`,
`phi_minimized`) and may write `AgentSafetyEvent` (e.g. `prompt_injection` → run
`blocked`). The `executive_brief.compose` tool counts `ops.approvals` where
`status=pending` to surface "pending approvals" in the brief.

---

## 5. Roles

- **Command Center roles** (`commandCenterStore.ts`): `command | executive |
  service-line` (`SELECTABLE_ROLES = command, executive`; service-line scoped but
  non-functional). Role is read from / written to the `?role=` URL param (so wall
  displays/deep-links hold a view). `executive` → OKR scoreboard + detailed tiles;
  `command` → unit heat strip + ops-first band order.
- **App auth roles** (`prod.users.role`, default `user`; superuser `admin@acumenus.net`;
  `AdminMiddleware` gates user management). Agent `minimum_role` currently `user`.
- **Persona mapping for Hummingbird:**
  - *Ops leaders / command-center staff (charge nurses, house supervisors, capacity
    command):* the primary approvers — Command Center glance + **approval inbox** +
    assign/start/complete + run Capacity Commander.
  - *Executives:* Executive Brief (read), Command Center executive role (OKRs/strain),
    high-level pending-approval awareness.
  - *PI analysts / improvement leads:* PDSA ownership/tasks, barriers, opportunities;
    process-mining/root-cause stay desktop.
- **Workflow preference** (`prod.users.workflow_preference`): `superuser | rtdc |
  perioperative | emergency | improvement | transport` (set via
  `/set-preference/{workflow}`); drives default dashboard.

---

## 6. Mobile relevance (per-feature flags)

Legend: **GLANCEABLE** (view at a glance / widget / lock screen) · **ACTIONABLE** (tap
to do work on the go) · **NOTIFY** (push-worthy) · **DESKTOP-ONLY** (keep on web).

### 6.1 Ops / AI-agent + approvals

| Feature | Flag(s) | Rationale |
|---|---|---|
| **Approval inbox — approve/reject a recommendation's action** | **ACTIONABLE + NOTIFY** (★ top value) | One-tap approve/reject with reason via `POST /api/ops/approvals/{id}/decision`. The defining "approvals on the go" use case. Push: *"Recommendation awaiting your approval: {title} ({riskLevel})."* |
| **Assign / Start / Complete an action** | **ACTIONABLE** | Lightweight status advance (`/actions/{id}/{assign,start,complete}`). Assign owner from contacts; complete with a note. |
| **Overdue actions** | **NOTIFY + GLANCEABLE** | `isOverdue`/`overdueActions` → escalation push: *"Action overdue: {title}."* Red badge on app icon. |
| **Action about to expire (default +8h)** | **NOTIFY** | `expires_at` window → *"Action expires in 1h — approve or it lapses."* |
| **Agent inbox summary tiles** (pending/active/assigned/overdue) | **GLANCEABLE** | 4 numbers = perfect home/widget glance (`/api/ops/agent-inbox`). |
| **Run Capacity Commander** ("what's my capacity risk + next actions?") | **ACTIONABLE** | `POST .../capacity-commander/run`, read-only, fast; returns huddle-ready next actions. Great pre-huddle mobile action. |
| **Executive Brief** (situation, governed plan, measured impact, confidence) | **GLANCEABLE + NOTIFY** | Server-composed; ideal scheduled morning push/widget: *"Your operations brief is ready."* Read-only render. |
| **Promote simulation scenario** | **ACTIONABLE (secondary)** | Possible on mobile but scenario tables are wide; better as "review + promote the recommended scenario" than full what-if. |
| **Data Quality agent / source freshness / lineage** | **DESKTOP-ONLY** (NOTIFY for critical) | Governance detail is desktop; but a *critical* `DataQualityFinding`/stale-source could push: *"Census feed stale — metrics qualified."* |
| **Agent safety events / evaluations / tool-call traces** | **DESKTOP-ONLY** | Audit/governance depth; show a trust badge on mobile, not the full trace. |
| **Operations graph snapshot / node timeline** | **DESKTOP-ONLY** | Graph exploration is large-canvas. |

### 6.2 Command Center

| Feature | Flag(s) | Rationale |
|---|---|---|
| **House strain / surge index (0–4) + drivers** | **GLANCEABLE + NOTIFY** | Single most glanceable signal; widget + push on level change: *"House status escalated to High."* |
| **Hero KPIs (net beds, ED boarders, etc.) with trajectory + source trust** | **GLANCEABLE** | `kpiMetricSchema` already carries display/target/status/trajectory — renders natively. |
| **24h forecast curve (occupancy + net beds)** | **GLANCEABLE** | Compact sparkline/area on mobile. |
| **Unit heat strip (occupancy %, open/blocked)** | **GLANCEABLE** | Scrollable per-unit row. |
| **Executive OKR scoreboard** | **GLANCEABLE** | Executive persona widget. |
| **Drill-down (`/drilldown` — panels, timeline, opportunities, events, playbooks)** | **ACTIONABLE (secondary)** | Tap a KPI → focused drill-down; opportunities/events carry `recommendedAction`. |
| **Role switch (command/executive)** | **ACTIONABLE** | Mobile setting/segmented control; mirror to `?role=`. |
| **Metric lineage ("where did this number come from")** | **DESKTOP-ONLY** (link out) | Trust detail; show trust badge + "view lineage on web". |

### 6.3 Process Improvement

| Feature | Flag(s) | Rationale |
|---|---|---|
| **PDSA cycle ownership / "my cycles" + status (plan/do/study/act)** | **ACTIONABLE + NOTIFY** | Task ownership on the go; push: *"PDSA '{title}' due this week"* / *"You were assigned a PDSA cycle."* Needs real backend (currently stubs). |
| **Create PDSA cycle (plan intake)** | **ACTIONABLE** | Simple form (`CreatePDSACycleModal` shape) — good mobile create *if route is wired* (§2.4). |
| **Barriers — add / resolve / change status** | **ACTIONABLE + NOTIFY** | Resolving a flow barrier from the floor is high-value; push when a barrier is assigned to you. Reconcile status vocab (`open/resolved` vs `identified/in_progress/resolved/blocked`). |
| **Discharge-failure logging** | **ACTIONABLE** | Quick capture form; mobile-friendly. |
| **Opportunities list / "create PDSA from opportunity"** | **GLANCEABLE + ACTIONABLE** | Browse + kick off; filters stack on mobile. |
| **Improvement stats / dashboard tiles** | **GLANCEABLE** | Counts (once `getImprovementStats` is real). |
| **Bottlenecks tables** | **GLANCEABLE (read-only)** | Reflow wide tables → cards; no actions. |
| **Process mining (`Process.jsx`) — reactflow canvas, drag layout** | **DESKTOP-ONLY** | Large-canvas, drag-and-drop, modals; at most a read-only simplified map. |
| **Root-cause / OCEL workbench (`RootCause.jsx`) — multi-pane + chat** | **DESKTOP-ONLY** | Analyst workbench; tall fixed panels; redesign cost too high for v1. |
| **Library / templates** | **GLANCEABLE** | Read-only resource list; downloads link out. |
| **Process layout / viewport persistence** | **DESKTOP-ONLY** | Drag-layout artifact. |

### 6.4 Notification opportunities (consolidated — wire these to push/widgets)

1. **"Recommendation awaiting your approval: {title} ({riskLevel})"** — new
   `Approval(status=pending)`. *Highest value.*
2. **"Action overdue: {title}"** — `isOverdue`/`overdueActions`.
3. **"Action expires in {N}: {title} — approve or it lapses"** — `expires_at` window.
4. **"Your morning operations brief is ready"** — scheduled `executive_briefing_agent`
   run.
5. **"House status escalated to {level}"** — `StrainState.level` change (Command Center).
6. **"Critical capacity risk: {N} ED boarders, net beds {X}"** — Capacity Commander
   `status=critical` finding.
7. **"Data feed stale: {source} — dependent metrics qualified"** — critical
   `DataQualityFinding`/`SourceFreshness`.
8. **"Barrier assigned to you: {description}"** / **"PDSA '{title}' due this week"** —
   PI ownership (pending real backend).
9. **"Simulation plan promoted — review the new recommendation"** — scenario promote
   created a new pending approval.
10. **"Action assigned to you: {title} (due {dueAt})"** — `assign` stamped your user id.

---

## 7. Implementation notes & caveats for Hummingbird

- **Reuse `/api/ops/*` verbatim** — the mobile approval flow is a thin client over the
  same endpoints the desktop Opportunity Portfolio uses. The lifecycle service is the
  source of truth; respect its 409 transition guards (don't show Approve on a
  non-pending approval, Start on a non-assigned action, etc.). Mirror the status→tone
  map from `Ops/components.tsx` and `STATUS_VAR` from `CommandCenter/status.ts`.
- **Collect a real reject reason** — the web hardcodes it; the API accepts `reason`.
- **Auth is session/cookie (`web` middleware) today.** Mobile (native) will need a
  token/session strategy (e.g. Sanctum) — none of the in-scope `/api/ops/*` routes are
  on a token guard. This is an infra prerequisite, not in current code.
- **Agents never write.** Frame AI output as suggestions; every state change is an
  explicit human tap. Surface the read-only/governance badge to build trust.
- **Command Center delivers as an Inertia prop, not JSON** (`router.reload({only})`).
  For native, build/consume a JSON endpoint mirroring `commandCenterDataSchema`
  (`/api/command-center/drilldown` is the only existing JSON CC endpoint). The Zod
  schemas in `types/commandCenter.ts` are a ready contract.
- **PI backend is mostly placeholder** (`DashboardService` stubs; PDSA write routes
  absent in web.php; mock-data reads). Hummingbird PI features (PDSA tasks, barriers)
  require backend build-out first; sequence them after the Ops approval flow, which is
  real today.
- **Vocabulary reconciliation:** Barrier status differs between `prod.barriers`
  (`open/resolved`) and the PDSA `BarriersTab` mock (`identified/in_progress/resolved/
  blocked`). Pick one before mobile.

### Key source files (absolute paths)

- Models: `/Users/sudoshi/Github/Zephyrus/app/Models/Ops/*.php`,
  `/Users/sudoshi/Github/Zephyrus/app/Models/{PdsaCycle,Barrier,OperationalEvent,ProcessLayout}.php`
- HITL service: `/Users/sudoshi/Github/Zephyrus/app/Services/Ops/OperationalActionLifecycleService.php`
- Recommendation engine: `/Users/sudoshi/Github/Zephyrus/app/Services/Ops/OperationsRecommendationService.php`
- Simulation: `/Users/sudoshi/Github/Zephyrus/app/Services/Ops/OperationsSimulationService.php`
- Attribution: `/Users/sudoshi/Github/Zephyrus/app/Services/Ops/InterventionAttributionService.php`
- Agents: `/Users/sudoshi/Github/Zephyrus/app/Services/Ops/Agents/{AgentControlPlaneService,AgentToolRegistry,RulesOnlyAgentRunner,AgentRunner}.php`
- Ops API controllers: `/Users/sudoshi/Github/Zephyrus/app/Http/Controllers/Api/Ops/*.php`
- Ops console pages: `/Users/sudoshi/Github/Zephyrus/resources/js/Pages/Ops/{AgentInbox,ExecutiveBrief,components}.tsx`
- **HITL UI (desktop):** `/Users/sudoshi/Github/Zephyrus/resources/js/Pages/Analytics.jsx` (`GraphRecommendationsPanel`, `SimulationWorkbenchPanel`, `ImpactAttributionPanel`)
- Command Center: `/Users/sudoshi/Github/Zephyrus/app/Http/Controllers/CommandCenterController.php`, `/Users/sudoshi/Github/Zephyrus/resources/js/Pages/Dashboard/CommandCenter.tsx`, `/Users/sudoshi/Github/Zephyrus/resources/js/Components/CommandCenter/`, `/Users/sudoshi/Github/Zephyrus/resources/js/types/commandCenter.ts`
- Ops API client: `/Users/sudoshi/Github/Zephyrus/resources/js/features/ops/{api,hooks,types}.ts`
- PI pages: `/Users/sudoshi/Github/Zephyrus/resources/js/Pages/Improvement/**`
- PI controllers: `/Users/sudoshi/Github/Zephyrus/app/Http/Controllers/{DashboardController,ProcessAnalysisController}.php`
- Migrations: `/Users/sudoshi/Github/Zephyrus/database/migrations/2026_06_2*_create_ops_*`, `2026_06_22_000040_create_pdsa_cycles_table.php`, `2026_06_20_000050_create_rtdc_huddles_barriers_tables.php`, `2026_06_20_000030_create_rtdc_operational_events_table.php`
- Routes: `/Users/sudoshi/Github/Zephyrus/routes/{web,api}.php`
