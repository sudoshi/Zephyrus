# Zephyrus 90-Day Demo Walkthrough

Date: 2026-06-26
Status: Phase 0 deliverable for `docs/superpowers/plans/2026-06-26-competitor-leapfrog-execution-plan.md` (Section 9 demo target)
Audience: Product, engineering, and sales running the "evidence-grade operations execution" demo.

## Thesis the Demo Must Prove

1. Zephyrus is an **execution system**, not a passive dashboard.
2. AI is **governed, observable, and accountable**.
3. **Impact measurement is part of the workflow**, not a retrospective consulting exercise.

## Setup (deterministic)

```bash
# Migrate then seed the deterministic Command Center demo scenario.
php artisan migrate --force
php artisan db:seed --class=CommandCenterDemoSeeder
```

The seeder is idempotent and deterministic (`mt_srand(20260622)`). It produces the Section-9 scenario:

| Scenario element | Seeded signal | Where it shows |
|---|---|---|
| ED boarding | ~5 admitted ED patients without beds | `/dashboard`, `/dashboard/emergency`, `/analytics/opportunities` |
| PACU holds delaying OR | OR cases + PACU logs | `/operations/room-status`, `/analytics/opportunities` |
| EVS turnaround behind | 6 active EVS turns, 4 at-risk, 1 stat | `/analytics/opportunities` (blocked-bed rule), capacity simulation |
| Transport queue overloaded | stat/overdue transport requests | `/transport/dispatch`, `/analytics/opportunities` |
| Discharges likely but blocked | open barriers + expected discharges | `/rtdc/unit-huddle`, `/analytics/opportunities` |
| Staffing tight on two units | 6 East + ICU short RN (gap 4, critical) | `/staffing`, `/analytics/opportunities`, simulation |
| One source feed stale | `process_events` stale since 2026-06-22 (+3 warning feeds) | `/analytics/data-quality`, metric trust badges |

## The Ten Steps

Each step lists the **surface**, the **action**, and the **talking point**.

### 1. Show the live operational graph and 4D facility context
- Surface: `/dashboard` (Command Center) → `/rtdc/patient-flow-navigator` (Patient Flow 4D).
- Action: Open the Command Center; show the bento wall and Donabedian bands computed from the live seeded DB. Switch to the 4D navigator and overlay ops-graph nodes on facility spaces.
- Talking point: "This is one operational graph projected from EHR, ADT, bed, OR, transport, EVS, and facility signals — not a slide."

### 2. Flag source freshness and confidence
- Surface: `/dashboard` metric trust badges → `/analytics/data-quality`.
- Action: Point to a hero metric's trust badge; open Data Quality and show the stale `process_events` feed and warning feeds.
- Talking point: "Every metric answers 'where did this come from and can I trust it?' — source lineage and freshness are first-class, not footnotes."

### 3. Explain root causes across ED, inpatient, OR, EVS, transport, staffing, discharge
- Surface: `/analytics/opportunities` (Graph Recommendations).
- Action: Show graph-backed recommendations: ED boarding, bed pressure, blocked beds (EVS), OR/PACU pressure, transport SLA risk, open barriers, **staffing gap**, stale source feed — each with evidence facts, graph path, and source tables.
- Talking point: "Each root cause is backed by graph evidence and named source tables, not opinion."

### 4. Compare scenarios
- Surface: `/analytics/workbench` (Scenario Workbench).
- Action: Compare no-action, EVS acceleration, discharge pull-forward, transport reassignment, flex-bed, **staffing relief**, OR/PACU protection, and the combined plan; sort by projected risk and net beds.
- Talking point: "We don't just predict — we simulate safe action plans with explicit assumptions and projected effects per metric."

### 5. Recommend a combined plan with alternatives and constraints
- Surface: `/analytics/workbench` → promote the combined scenario.
- Action: Promote `combined_capacity_plan` into a draft action plan; show the constraints (e.g., "House supervisor confirms staffing and safety constraints").
- Talking point: "The recommended plan carries alternatives, constraints, and expected downstream effects."

### 6. Request human approval
- Surface: `/analytics/opportunities` or `/ops/agent-inbox`.
- Action: Show the pending approval created by promotion; approve it.
- Talking point: "Nothing executes without governed human approval — read-only, then draft-only, then approval-gated."

### 7. Create huddle items, tasks, and owner assignments
- Surface: `/analytics/opportunities` lifecycle controls (assign owner) and `/rtdc/unit-huddle`.
- Action: Assign an owner and due time to the approved action(s).
- Talking point: "Approved work routes to accountable owners with due times — execution, not just visualization."

### 8. Track completion
- Surface: `/analytics/opportunities` / `/ops/agent-inbox` active actions.
- Action: Start and complete an action; show status transitions and overdue flags.
- Talking point: "The action ledger tracks assignment, execution, completion, override, and expiration."

### 9. Measure outcome and balancing metrics
- Surface: `/analytics/workbench` Impact Attribution cards.
- Action: Show attributed interventions, estimated net-bed gain, primary outcomes improved, balancing-measure warnings, and confidence level.
- Talking point: "Impact is measured in the workflow with before/after windows, balancing measures, and confidence language — not a quarterly consulting deck."

### 10. Generate an executive brief with source lineage and confidence
- Surface: `/ops/executive-brief` (and `/ops/agent-inbox` for the agent roster + traces).
- Action: Run the Executive Briefing Agent. Show the headline, situation (root causes across domains), governed plan (pending approvals), measured impact, source lineage, and the explicit confidence statement. In Agent Inbox, show the run's tool calls, golden-case evaluations (`expected_tool_called`, `no_write_tools`, `phi_minimized` all pass), and that it is read-only.
- Talking point: "The brief is composed by a governed, read-only agent with a visible trace and passing safety evals — accountable AI, with source lineage and an honest confidence statement."

## Acceptance

- The demo proves Zephyrus is an execution system (steps 5–8 take action and track it).
- The demo proves AI is governed, observable, and accountable (steps 6, 10 — approvals, traces, evals, PHI minimization, prompt-injection blocking).
- The demo proves impact measurement is part of the workflow (step 9 — attribution with balancing measures and confidence).

## Notes for Presenters

- The scenario is deterministic; re-seed any time to reset.
- Do not overclaim ROI — the attribution cards use confidence language and balancing measures by design.
- The Executive Briefing Agent, Capacity Commander, and Data Quality Agent are all read/draft-only; show the evals to make the governance real.
