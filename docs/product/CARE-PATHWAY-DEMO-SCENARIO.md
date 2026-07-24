# Care Pathway Journey Demo

## Why the real catalog is inactive

The 250-pathway package is an evidence and provenance release, not an approved local clinical protocol. Automated verification answered whether the source claims and references could be reconciled; it did not authorize Zephyrus to assign care, tell a patient what their plan is, or let Eddy propose patient-specific treatment.

The production release therefore remains inactive because:

- all 250 pathways still require institutional clinical signoff;
- eligibility and exclusion logic has not completed local validation;
- patient-language content has not completed local health-literacy, accessibility, and patient-advisor review;
- role ownership, escalation, correction, and release policies are not approved for live encounters;
- Hummingbird and Eddy must consume governed audience projections rather than raw research prose; and
- the database activation gate correctly rejects a release without complete approvals and active versions.

This is a clinical-safety boundary, not a data-quality failure. The verified release has zero failed import controls and zero residual unclassified absence, but evidence completeness and local authorization are different decisions.

## What the demo proves

The demo presents a fictional adult inpatient, **Jordan Lee**, in a six-step heart-failure journey. Heart Failure is one of the evidence-verified pilot candidates, but the demo does not approve it. It applies a non-persistent presentation overlay to show the intended workflow:

1. **Evidence review** — release provenance, counts, controls, and activation blockers.
2. **Candidate match** — explainable contemporary signals, conflicts, and required confirmation; a DRG alone never assigns the pathway.
3. **Clinician confirmation** — a sandbox-only instance with milestone owners and no order or diagnosis writeback.
4. **Coordinate rounds** — stage, variance, role inputs, patient question, and a patient-free 4D badge payload.
5. **Patient awareness** — separate Hummingbird Staff and Hummingbird Patient projections, generic push doorbells, attributable goals, and urgent-help boundaries.
6. **Supported transition** — resolved barrier, observed teach-back record, answered question, reconciled milestones, and synthetic closure audit event.

The Eddy view demonstrates reference plus patient-instance context in `patient_context_local_only` mode. Its output is citation-backed and draft-only. It cannot diagnose, order, activate a pathway, write global memory, or send patient context to a cloud provider.

## Safety architecture

The demo is intentionally separate from the serving switches:

- `CARE_PATHWAYS_DEMO_ENABLED` controls only the synthetic page and read-only scenario API.
- `CARE_PATHWAYS_CATALOG_ENABLED`, assignment, rounds, staff mobile, patient, Eddy reference, Eddy instance, and writeback flags remain independent and unchanged.
- Scenario state is selected by a bounded `step` query parameter and generated in memory. Advance and reset do not write to PostgreSQL or the session.
- The patient identity, encounter, context reference, care-team actions, questions, and timeline are fictional.
- Catalog metadata is read when available. If the catalog database is unavailable in an isolated frontend environment, the page labels its counts as configured verified-release controls rather than live observations.
- The patient projection is purpose-authored synthetic copy; it is never derived from raw CSV prose at request time.

## Run locally

Set the isolated demo gate without enabling any clinical serving flag:

```dotenv
CARE_PATHWAYS_DEMO_ENABLED=true
CARE_PATHWAYS_GOVERNANCE_ENABLED=false
CARE_PATHWAYS_CATALOG_ENABLED=false
CARE_PATHWAYS_ASSIGNMENT_ENABLED=false
CARE_PATHWAYS_ROUNDS_ENABLED=false
CARE_PATHWAYS_STAFF_MOBILE_ENABLED=false
CARE_PATHWAYS_PATIENT_ENABLED=false
CARE_PATHWAYS_EDDY_REFERENCE_ENABLED=false
CARE_PATHWAYS_EDDY_INSTANCE_ENABLED=false
CARE_PATHWAYS_WRITEBACK_ENABLED=false
```

Then clear configuration cache and start Zephyrus:

```bash
php artisan config:clear
./start-dev.sh
```

Open:

- Staff demo page: `http://localhost:8001/care-pathways/demo`
- Read-only projection contract: `http://localhost:8001/api/care-pathways/v1/demo/scenario?step=0`

The page is visible in the Workspaces navigation only while the demo gate is enabled. Both routes still require an authenticated staff session.

## Demonstration script

Start on **Governance** at step 1 and point out that the real catalog says `inactive` while the overlay says `simulation_only`. Advance to the candidate step and show the matched signals, missing final DRG, local exclusion review, and confirmation requirement. At clinician confirmation, emphasize that no diagnosis or order is created.

Advance to **Coordinate rounds**, then visit Virtual Rounds, Hummingbird Staff, and Eddy. Show the opaque `ptok_` reference, generic notification, role-shaped inputs, one patient question, the local-only Eddy route, exact research reference, and prohibited actions.

Advance to **Patient awareness** and open Hummingbird Patient. Contrast patient-authored and care-team goals, show the plain-language plan, and note that the application never claims the patient understands. Finish at **Supported transition**, where the question is answered in person, transportation is resolved, and the audit timeline closes the fictional instance.

## Production activation remains a separate program

This scenario is useful for product review, workflow rehearsal, patient-advisor feedback, and multidisciplinary hazard analysis. It is not evidence for turning on the real serving flags. A pilot still requires locally approved content, deidentified rule fixtures, required-role approvals, patient-copy approval, release policies, independent safety testing, monitored rollback, and explicit activation by an authorized governance role.
