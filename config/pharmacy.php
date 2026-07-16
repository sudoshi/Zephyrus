<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Controlled Substance Operational View (X-10)
    |--------------------------------------------------------------------------
    |
    | Configuration for the /pharmacy/controlled operational view. Every value
    | here is LOCAL POLICY — configured operational targets and a shift-end
    | reconciliation policy, kept strictly separate from measured event
    | timestamps. Nothing here computes or enables any individual/user/staff
    | dimension: this is a diversion-ADJACENT view that exposes unit and station
    | operational aggregates only. Diversion investigation and individual scoring
    | are out of scope by design and cannot be enabled by configuration.
    |
    */

    'controlled' => [

        /*
        | Shift-end reconciliation policy. A discrepancy is expected to be
        | reconciled by the end of the shift in which it was opened. These are
        | the local shift-end wall-clock times (in the shift timezone) against
        | which an OPEN discrepancy's age is measured — the applicable shift-end
        | is the most recent shift boundary at or before "now". This is a policy
        | reference, NOT an event: it is configuration, never a measured stamp.
        */
        'shift_end' => [
            'timezone' => env('PHARMACY_CONTROLLED_SHIFT_TZ', 'America/New_York'),
            // 24h HH:MM boundaries; a day + night shift by default.
            'times' => ['07:00', '19:00'],
            'label' => 'Shift-end reconciliation policy',
        ],

        /*
        | An open controlled discrepancy that remains unreconciled past this many
        | minutes BEYOND its applicable shift-end is surfaced as breaching the
        | reconciliation policy. This is a configured operational tolerance, not
        | a measured value and not an accusation — it flags a location for
        | operational reconciliation review, never a person.
        */
        'reconciliation_grace_minutes' => (int) env('PHARMACY_CONTROLLED_RECONCILIATION_GRACE_MINUTES', 0),

        /*
        | Local policy target for the controlled override rate (controlled
        | overrides per hundred controlled vends). A policy reference line the
        | measured rate is folded against server-side; configuration, not an
        | observed event.
        */
        'override_target_rate' => (float) env('PHARMACY_CONTROLLED_OVERRIDE_TARGET_RATE', 5.0),

        /*
        | Rolling window (hours) over which station/unit override and discrepancy
        | patterns are aggregated.
        */
        'pattern_window_hours' => (int) env('PHARMACY_CONTROLLED_PATTERN_WINDOW_HOURS', 24),

        /*
        | Aggregate export of the controlled-substance operational view is
        | DEFERRED. Any future export must be separately capability-gated,
        | audited via UserAuditRecorder, and contain zero individual data. It is
        | intentionally not enabled here so no aggregate leaves the governed
        | in-app surface without an explicit, audited authorization decision.
        */
        'export_enabled' => false,
    ],

];
