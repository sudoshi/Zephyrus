# Patient Flow 4D Navigator

Generated: 2026-06-25

This artifact is a prototype 3D/4D patient-flow navigator for the 500-bed
Level I trauma academic medical center CAD model. It supports historical replay
and near-real-time simulated ADT streaming from HL7 v2 messages, with normalized
FHIR-shaped patient movement events.

## Standards Basis

- **HL7 v2 ADT** remains the most common transactional movement feed in
  hospital operations. This prototype supports common patient administration
  triggers including A01 admit, A02 transfer, A03 discharge, A04 registration,
  A08 update, A11/A12/A13 cancellations, and A40 merges.
- **IHE Patient Administration Management (PAM)** frames patient identity,
  encounter information, and movements inside acute-care encounters, including
  pending, advanced, temporary-transfer and historic-movement options.
- **FHIR R4 Encounter** is used as the canonical API concept for admission,
  discharge, encounter class, location history, status and period.
- **FHIR Location** is used for the bed/room/ward/corridor hierarchy and
  physical-location linkage to the 3D model.
- **FHIR Subscriptions / R5 Backport** informs the live-notification pattern for
  systems that can push FHIR-native changes instead of polling.
- **FHIR Bulk Data / Flat FHIR** informs the historical backfill pattern for
  population-scale Encounter, Patient, Location, Observation, Procedure,
  MedicationAdministration and ServiceRequest history.
- **HIPAA Security Rule and NIST CSF 2.0** inform the separation of raw PHI
  payloads, auditability, deidentification, least-privilege viewer APIs,
  encrypted transport and monitored delivery.

## Files

- `flow_engine.py` - HL7 v2 parser, event normalizer, FHIR bundle adapter and
  point-in-time census reconstruction.
- `generate_synthetic_flow.py` - deterministic synthetic HL7 ADT/order/result
  generator using the hospital CAD model location catalog.
- `server.py` - local API/static/SSE server.
- `patient_flow_navigator_schema.sql` - PostgreSQL event-store schema.
- `data/*.ndjson|json` - generated synthetic HL7 messages, normalized events,
  tracks, locations and summary.
- `viewer/` - Three.js 3D/4D navigator.
- `verify_viewer.mjs` - Playwright render and API verifier.

## Run

From the repository root:

```bash
python3 patient-flow-4d-navigator/generate_synthetic_flow.py
python3 patient-flow-4d-navigator/server.py --port 8776
```

Open:

```text
http://127.0.0.1:8776/viewer/
```

## API

- `GET /api/summary`
- `GET /api/locations`
- `GET /api/events?from=&to=&patient=&category=&service_line=&floor=`
- `GET /api/tracks`
- `GET /api/state?asOf=`
- `POST /api/hl7v2` with raw HL7 v2 text or `{ "raw_hl7": "..." }`
- `GET /stream/adt?replay=160&interval=0.8`

## Data Safeguards

The generated demo data is synthetic and deidentified. In production, raw HL7
and FHIR payloads should be treated as ePHI, stored in governed encrypted
storage, indexed by hash/URI, and served to the navigator only through a
minimum-necessary normalized event API.

## Verify

With the server running:

```bash
PLAYWRIGHT_MODULE=file:///path/to/playwright/index.js node patient-flow-4d-navigator/verify_viewer.mjs
```

The verifier writes `verification/results.json` plus desktop and mobile
screenshots.
