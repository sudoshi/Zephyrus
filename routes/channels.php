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
| Because these are public channels, Pusher/Reverb never invokes a
| `Broadcast::channel(...)` authorization callback for them. Any such callback
| would be inert dead code, so none is defined here. If a future channel needs to
| carry PHI, switch its event to `PrivateChannel` and add a real auth callback
| below at that time.
|
*/
