<?php

/*
|--------------------------------------------------------------------------
| Broadcast Channel Authorization
|--------------------------------------------------------------------------
|
| RTDC operational-board channels are INTENTIONALLY PUBLIC.
|
| The broadcast events `CensusUpdated`, `HuddleUpdated`, and `BedMeetingUpdated`
| all return a public `Illuminate\Broadcasting\Channel` (NOT a `PrivateChannel`)
| for `unit.{unitId}` and `hospital.beds`. Their payloads are PHI-free aggregate
| operational counts (occupied/available/blocked beds, weighted discharge counts,
| net bed-need) — there is nothing patient-identifying on the wire.
|
| P6 adds `hospital.cockpit` under the same doctrine: `CockpitSnapshotUpdated`
| is a {facility_key, generated_at} reload-PING only — clients refetch the
| snapshot over their authenticated session; no metric values ride the wire.
|
| Because these are public channels, Pusher/Reverb never invokes a
| `Broadcast::channel(...)` authorization callback for them. Any such callback
| would be inert dead code, so none is defined here. If a future channel needs to
| carry PHI, switch its event to `PrivateChannel` and add a real auth callback
| below at that time.
|
*/
