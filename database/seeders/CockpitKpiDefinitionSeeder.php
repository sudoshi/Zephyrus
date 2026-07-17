<?php

namespace Database\Seeders;

use App\Models\Ops\MetricDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Zephyrus 2.0 P1 — seeds ops.metric_definitions for every Appendix-A KPI key
 * with literature-aligned default band edges (cockpit spec §2, StatusEngine
 * semantics: direction 'down' = value >= edge breaches; 'up' = value <= edge
 * breaches; 'ok' is RATIONED — only definitions with an explicit ok_edge can
 * ever show green, per decision D3).
 *
 * Where the same number already renders on /dashboard, the edges deliberately
 * match the legacy band constants (e.g. ed.door_to_provider warn 20 / crit 30)
 * so one value never wears two colors. Standalone seeder ON PURPOSE: prod can
 * seed/refresh the catalog additively (updateOrCreate by metric_key) without
 * running the full demo seeder.
 */
class CockpitKpiDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $key => $def) {
            $attributes = $def + [
                'domain' => Str::before($key, '.'),
                'facility_key' => 'HOSP1',
                'status' => 'active',
                'is_active' => true,
            ];

            $existing = MetricDefinition::query()->firstWhere('metric_key', $key);

            if ($existing === null) {
                MetricDefinition::query()->create($attributes + [
                    'metric_key' => $key,
                    'metric_definition_uuid' => (string) Str::uuid(),
                ]);
            } else {
                if (str_starts_with($key, 'flow.ancillary_')) {
                    // Ancillary edges are governed local policy after initial
                    // installation. Re-seeding may refresh descriptive catalog
                    // metadata, but must not erase an administrator's tuning.
                    unset(
                        $attributes['target_value'],
                        $attributes['ok_edge'],
                        $attributes['warn_edge'],
                        $attributes['crit_edge'],
                    );
                }

                // Re-seeding reasserts the catalog defaults (including over
                // admin-tuned edges for the legacy catalog) but never churns
                // row identity. Ancillary governed edges are the exception.
                $existing->fill($attributes)->save();
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            // ---------------- OKR scorecard (spec §2.1) — refresh 300s ------
            'okr.sepsis_3hr' => $this->okr('Sepsis 3-hr bundle', 'Sepsis 3-hour bundle compliance (SEP-1).', '%', 'up', 90, 90, 90, 80, 'CMO', 'Safe Care · Zero Harm'),
            'okr.ed_los_admit' => $this->okr('ED LOS (admitted)', 'Median ED length of stay for admitted patients.', 'min', 'down', 300, null, 300, 360, 'VP Ops', 'Timely Access'),
            'okr.dc_before_noon' => $this->okr('Discharge before noon', 'Percent of inpatient discharges completed before 12:00.', '%', 'up', 40, 40, 40, 30, 'CNO', 'Smooth Throughput'),
            'okr.occupancy_midnight' => $this->okr('Midnight occupancy', 'Projected staffed-bed occupancy at midnight.', '%', 'down', 85, null, 85, 92, 'House Sup', 'Right-Sized Capacity'),
            'okr.open_shifts' => $this->okr('Unfilled shifts 24h', 'Unfilled shifts over the next 24 hours.', '', 'down', 30, null, 31, 46, 'CNO', 'Engaged Workforce'),
            'okr.hand_hygiene' => $this->okr('Hand hygiene', 'Hand-hygiene compliance, observed.', '%', 'up', 90, 90, 90, 85, 'Quality', 'Reliable Care'),
            'okr.worked_per_uos' => $this->okr('Worked hrs / UOS', 'Worked-hours-per-unit-of-service index vs target.', 'x', 'down', 1.00, null, 1.00, 1.08, 'CFO', 'Financial Stewardship'),
            'okr.hcahps' => $this->okr('HCAHPS top-box', 'HCAHPS top-box patient-experience score.', '%', 'up', 76, 76, 76, 70, 'CXO', 'Patient Experience'),
            // Legacy dashboard bands readmission warn≥11/crit≥13 — kept so the
            // OKR card and the outcomes band never disagree on the same value.
            'okr.readmit_30d' => $this->okr('30-day readmission', '30-day all-cause readmission rate.', '%', 'down', 11, null, 11, 13, 'CMO', 'Care Reliability'),

            // ---------------- RTDC (spec §2.2) — refresh 60s ----------------
            'rtdc.census' => $this->kpi('House census', 'Occupied staffed beds, house-wide.', 'pts', 'down', null, null, null, 60),
            'rtdc.available' => $this->kpi('Available beds', 'Staffed, clean, unoccupied beds available now.', 'beds', 'up', null, null, null, 60),
            'rtdc.pending_admits' => $this->kpi('Pending admits', 'Admit orders without an assigned bed (ED, direct, transfer).', 'pts', 'down', null, 20, 30, 60),
            'rtdc.pending_dc' => $this->kpi('Pending discharges', 'Discharges expected today (potential + confirmed).', 'pts', 'up', null, null, null, 60),
            'rtdc.boarders' => $this->kpi('ED boarders', 'Admitted patients physically held in the ED.', 'pts', 'down', 0, 1, 6, 60,
                'ED boarding — {value} admitted patients holding in the ED'),
            'rtdc.icu_occupancy' => $this->kpi('ICU occupancy', 'ICU staffed-bed occupancy (low elasticity).', '%', 'down', 85, 85, 90, 60,
                'ICU occupancy {display} — capacity has low elasticity'),
            'rtdc.blocked_beds' => $this->kpi('Blocked beds', 'Beds offline for staffing, environmental, or isolation reasons.', 'beds', 'down', 0, 5, 10, 60),
            'rtdc.occupancy' => $this->kpi('House occupancy', 'Occupied as a percent of staffed capacity.', '%', 'down', 85, 85, 92, 60,
                'House occupancy {display} — above the safe zone'),

            // ---------------- ED (spec §2.3) — refresh 120s -----------------
            'ed.nedocs' => $this->kpi('NEDOCS', 'National ED Overcrowding Score (0–200 composite).', '', 'down', 100, 101, 141, 120,
                'ED OVERCROWDED — NEDOCS {display}', ['scale' => 200]),
            'ed.in_dept' => $this->kpi('In department', 'All patients currently in the ED.', 'pts', 'down', null, null, null, 120),
            'ed.waiting' => $this->kpi('Waiting room', 'Patients arrived, not yet seen by a provider.', 'pts', 'down', null, 15, 25, 120),
            'ed.door_to_provider' => $this->kpi('Door-to-provider', 'Median arrival to first provider contact.', 'min', 'down', 20, 20, 30, 120),
            'ed.lwbs' => $this->kpi('LWBS', 'Left without being seen, of total arrivals (24h).', '%', 'down', 2, 2, 3, 120,
                'LWBS {display} — patients leaving unseen'),
            'ed.boarders' => $this->kpi('Boarders', 'Admitted ED patients awaiting an inpatient bed.', 'pts', 'down', 0, 1, 6, 120),
            'ed.los_admit' => $this->kpi('ED LOS (admitted)', 'Median ED length of stay, admitted patients.', 'min', 'down', 300, 300, 360, 120),
            'ed.los_discharge' => $this->kpi('ED LOS (discharged)', 'Median ED length of stay, discharged patients.', 'min', 'down', 150, 150, 200, 120),
            'ed.ems_inbound' => $this->kpi('EMS inbound', 'Active inbound EMS transports.', 'rigs', 'down', null, null, null, 120),
            'ed.diversion' => $this->kpi('Diversion', 'Ambulance diversion status (any active event).', '', 'down', 0, 1, null, 120,
                'ED ON DIVERSION'),

            // ---------------- Perioperative (spec §2.4) — refresh 300s ------
            // Integer-exact mapping of the legacy bandLowBad/bandHighBad zones.
            'periop.prime_util' => $this->kpi('Prime-time utilization', 'Used prime-time minutes over available prime-time minutes.', '%', 'up', 80, 69, 59, 300, null, null, 80),
            'periop.first_case_ontime' => $this->kpi('First-case on-time', 'First cases starting within the 15-minute grace window.', '%', 'up', 85, 84, 69, 300, null, null, 85),
            'periop.turnover' => $this->kpi('Turnover', 'Median wheels-out to wheels-in, same room.', 'min', 'down', 25, 25, 35, 300),
            'periop.cases' => $this->kpi('Cases today', 'Scheduled cases on today\'s OR schedule.', 'cases', 'up', null, null, null, 300),
            'periop.cancellations' => $this->kpi('Same-day cancellations', 'Day-of-surgery cancellations.', 'cases', 'down', 0, 5, 8, 300),
            'periop.pacu_holds' => $this->kpi('PACU holds', 'Patients held in PACU awaiting an inpatient bed.', 'pts', 'down', 0, 3, 6, 300,
                'PACU holds — {display} recovery bays blocked awaiting inpatient beds'),
            'periop.block_util' => $this->kpi('Block utilization', 'Used block minutes over allocated block minutes.', '%', 'up', 80, 79, 69, 300, null, null, 80),

            // ---------------- Staffing (spec §2.5) — refresh 600s -----------
            'staffing.open_shifts' => $this->kpi('Open shifts 24h', 'Unfilled shifts over the next 24 hours.', '', 'down', 30, 31, 46, 600,
                'Staffing gap — {display} unfilled shifts in the next 24h'),
            'staffing.overtime' => $this->kpi('Overtime', 'Overtime hours as a percent of worked hours.', '%', 'down', 4, 4, 6, 600),
            'staffing.agency' => $this->kpi('Agency RNs', 'Agency and contract RNs active.', '', 'down', null, null, null, 600),
            'staffing.callouts' => $this->kpi('Callouts', 'Callouts today, all roles.', '', 'down', null, 8, 12, 600),
            'staffing.sitters' => $this->kpi('Sitters / 1:1', 'Active sitter and 1:1 observation assignments.', '', 'down', null, null, null, 600),
            'staffing.productivity' => $this->kpi('Productivity', 'Worked-hours productivity index.', '%', 'up', 100, 95, 90, 600, null, null, 100),

            // ---------------- Flow & transport (spec §2.6) — refresh 300s ---
            'flow.dc_before_noon' => $this->kpi('Discharge before noon', 'Percent of today\'s discharges completed before 12:00.', '%', 'up', 40, 40, 30, 300),
            'flow.discharge_lounge' => $this->kpi('Discharge lounge', 'Discharge lounge occupancy.', 'pts', 'up', null, null, null, 300),
            'flow.transport_queue' => $this->kpi('Transport queue', 'Transport requests pending or in progress.', '', 'down', null, 10, 18, 300),
            'flow.transport_wait' => $this->kpi('Transport wait', 'Average request-to-pickup wait.', 'min', 'down', 15, 15, 25, 300,
                'Transport delays — average wait {display}'),
            'flow.bed_turnaround' => $this->kpi('Bed turnaround', 'Average EVS dirty-to-ready turnaround.', 'min', 'down', 45, 45, 60, 300,
                'EVS turnaround {display} — beds returning slowly'),
            'flow.dirty_beds' => $this->kpi('Dirty beds', 'Beds awaiting or in EVS cleaning.', 'beds', 'down', null, 12, 20, 300),
            // P5: the PI crown jewel promoted to A0 — live bottleneck signals
            // from DashboardService::getBottleneckStats() (prod.* only).
            'flow.bottlenecks_active' => $this->kpi('Active bottlenecks', 'Live flow constraint signals ranked by impact: long-stay, OR turnover, blocked beds, at-risk transports, ED boarding.', '', 'down', null, 3, 5, 300,
                'Flow bottlenecks — {display} active constraint signals'),
            'flow.bottleneck_patients' => $this->kpi('Bottleneck patients', 'Patients affected by the active bottleneck signals.', 'pts', 'down', null, 25, 50, 300),
            // Part X (X2): the OPerA synchronization constraint — the worst
            // object-side wait at a shared hand-off, mined object-centrically from
            // the OCEL log and cached in arena.performance_signals. Absent (tile
            // hidden) when the Arena is off, so no regression to the prod cockpit.
            'flow.worst_handoff_wait' => $this->kpi('Worst hand-off wait', 'The longest object-side wait at a shared hand-off — which resource is the flow constraint, discovered object-centrically from the OCEL log (Part X §X.6, OPerA).', 'min', 'down', 30, 90, 240, 900),
            // Ancillary expansion: aggregate-only operational health. The
            // department workspaces own per-item status; Cockpit carries only
            // counts, oldest ages, compliance, and resource availability.
            'flow.ancillary_rad_open_breaches' => $this->kpi('Imaging open breaches', 'Open imaging work items beyond their governed SLA definition.', 'orders', 'down', 0, 1, 5, 60),
            'flow.ancillary_rad_oldest_unread' => $this->kpi('Oldest unread study', 'Age of the oldest acquired imaging study awaiting a final report.', 'min', 'down', 30, 30, 60, 60),
            'flow.ancillary_rad_scanners_down' => $this->kpi('Scanners down', 'Imaging scanners currently unavailable for operational use.', 'scanners', 'down', 0, 1, 2, 60),
            'flow.ancillary_lab_stat_compliance' => $this->kpi('Lab STAT compliance', 'Percent of STAT laboratory orders completed within the active governed SLA.', '%', 'up', 90, 89, 79, 60, null, null, 90),
            'flow.ancillary_lab_oldest_decision_pending' => $this->kpi('Oldest decision-pending result', 'Age of the oldest laboratory order explicitly blocking an ED, discharge, or OR decision.', 'min', 'down', 45, 45, 60, 60),
            'flow.ancillary_lab_critical_callbacks' => $this->kpi('Critical callbacks open', 'Critical laboratory results awaiting documented communication acknowledgment.', 'callbacks', 'down', 0, 1, 3, 60),
            'flow.ancillary_rx_verification_queue' => $this->kpi('Pharmacy verification queue', 'Medication orders currently awaiting pharmacist verification.', 'orders', 'down', null, 10, 20, 60),
            'flow.ancillary_rx_oldest_stat' => $this->kpi('Oldest STAT medication', 'Age of the oldest unverified or undispensed STAT medication order.', 'min', 'down', 10, 10, 15, 60),
            'flow.ancillary_rx_sepsis_at_risk' => $this->kpi('Sepsis antibiotics at risk', 'Aggregate count of sepsis antibiotic orders approaching their governed operational clock.', 'orders', 'down', 0, 1, 3, 60),
            'flow.ancillary_rx_shortage_stockouts' => $this->kpi('Medication stockouts', 'Active unit, station, or central-pharmacy stockouts affecting fulfillment.', 'items', 'down', 0, 1, 5, 300),

            // ---------------- Quality & safety (spec §2.7) — refresh 1800s --
            'quality.sepsis_3hr' => $this->kpi('Sepsis 3-hr bundle', 'SEP-1 3-hour bundle compliance.', '%', 'up', 90, 90, 80, 1800, null, null, 90),
            'quality.sepsis_6hr' => $this->kpi('Sepsis 6-hr bundle', 'SEP-1 6-hour bundle compliance.', '%', 'up', 90, 90, 80, 1800, null, null, 90),
            'quality.hand_hygiene' => $this->kpi('Hand hygiene', 'Observed hand-hygiene compliance.', '%', 'up', 90, 90, 85, 1800, null, null, 90),
            'quality.falls_rate' => $this->kpi('Falls / 1000 pd', 'Falls per 1,000 patient-days.', '', 'down', 3.0, 3.0, 4.0, 1800),
            'quality.rapid_response' => $this->kpi('Rapid responses', 'Rapid response activations today.', '', 'down', null, null, null, 1800),
            'quality.med_rec' => $this->kpi('Med reconciliation', 'Medication reconciliation completion.', '%', 'up', 95, 95, 90, 1800, null, null, 95),
            'quality.clabsi' => $this->kpi('CLABSI MTD', 'Central-line associated bloodstream infections, month to date.', '', 'down', 0, 2, 4, 1800),
            'quality.cauti' => $this->kpi('CAUTI MTD', 'Catheter-associated UTIs, month to date.', '', 'down', 0, 2, 4, 1800),
            'quality.cdiff' => $this->kpi('C. diff MTD', 'Hospital-onset C. difficile, month to date.', '', 'down', 0, 2, 4, 1800),
            'quality.ssi' => $this->kpi('SSI MTD', 'Surgical site infections, month to date.', '', 'down', 0, 2, 4, 1800),
            'quality.mrsa' => $this->kpi('MRSA MTD', 'MRSA bacteremia, month to date.', '', 'down', 0, 2, 4, 1800),
            'quality.vap' => $this->kpi('VAP MTD', 'Ventilator-associated pneumonia, month to date.', '', 'down', 0, 2, 4, 1800),
            'quality.hapi' => $this->kpi('HAPI 3+ MTD', 'Hospital-acquired pressure injuries, stage 3+, month to date.', '', 'down', 0, 1, 2, 1800),
            // Part X (X3): object-centric care-pathway conformance mined from the
            // OCEL log (Arena). An OBSERVED adherence rate, so it earns colour;
            // the alert_template lets a crit deviation ride the AlertEngine ticker
            // into the EddyDock (→ flag_pathway_deviation). Only present when the
            // Arena is enabled + the sidecar has reported.
            'quality.sepsis_conformance' => $this->kpi('Sepsis bundle conformance', 'SEP-3 bundle adherence discovered object-centrically from the OCEL log (Part X §X.7).', '%', 'up', 90, 85, 70, 900,
                'Sepsis bundle conformance {display} — observed pathway deviations', null, 90),
            'quality.surgical_safety_conformance' => $this->kpi('WHO checklist conformance', 'WHO Surgical Safety Checklist adherence discovered from the OCEL log (Part X §X.7).', '%', 'up', 98, 90, 80, 900,
                'Surgical safety checklist conformance {display}', null, 98),

            // ---------------- Service lines (spec §2.8) — refresh 3600s -----
            // oe_los / readmit / avoidable_days match the legacy outcomes band.
            'service.oe_los' => $this->kpi('O:E LOS', 'Observed over expected (GMLOS-based) length of stay.', 'x', 'down', 1.0, 1.0, 1.2, 3600),
            'service.readmit_30d' => $this->kpi('30-day readmission', '30-day all-cause readmission rate.', '%', 'down', 11, 11, 13, 3600),
            'service.avoidable_days' => $this->kpi('Avoidable days', 'Documented bed-days beyond clinical need, month to date.', 'bed-days', 'down', null, 101, 200, 3600),
            'service.cmi' => $this->kpi('Case mix index', 'Case mix index (acuity context).', 'x', 'up', null, null, null, 3600),
            'service.observation_rate' => $this->kpi('Observation rate', 'Observation as a percent of admissions.', '%', 'down', null, 15, 20, 3600),
            'service.discharges_mtd' => $this->kpi('Discharges MTD', 'Discharges month to date vs plan.', '', 'up', null, null, null, 3600),

            // ---------------- Financial stewardship (spec §2.9) — 3600s -----
            'financial.worked_per_uos' => $this->kpi('Worked hrs / UOS', 'Worked-hours-per-unit-of-service index vs target.', 'x', 'down', 1.00, 1.00, 1.08, 3600),
            'financial.premium_pay' => $this->kpi('Premium pay', 'Overtime + agency + incentive dollars today.', '$k', 'down', null, null, null, 3600),
            'financial.productivity' => $this->kpi('Labor productivity', 'Labor productivity index.', '%', 'up', 100, 100, 92, 3600, null, null, 100),
            'financial.cost_per_case' => $this->kpi('Cost / OR case', 'Cost per OR case vs budget.', '$k', 'down', null, null, null, 3600),
            'financial.contract_labor' => $this->kpi('Contract labor', 'Contract labor dollars today.', '$k', 'down', null, null, null, 3600),
            'financial.overtime' => $this->kpi('Overtime', 'Overtime hours as a percent of worked hours.', '%', 'down', 4, 4, 6, 3600),

            // ---------------- Home Hospital (ACUM-PRD-HAH-001 §9) — 120s ----
            // Earned-Red ration: alert_template ONLY on the four alerting rows
            // (unacked criticals, response p90, visit compliance, kits offline).
            // Benchmarks are the national numbers (Levine 2024 / CMS waiver).
            'home.census_occupancy' => $this->kpi('Virtual ward occupancy', 'Occupied program slots as a percent of the virtual ward.', '%', 'up', null, null, null, 120),
            'home.unacked_critical_vitals' => $this->kpi('Unacked critical vitals', 'Open critical patient vitals awaiting clinician acknowledgement.', '', 'down', 0, null, 1, 120,
                'HOME critical vitals unacknowledged — {display} open'),
            'home.escalation_response_p90' => $this->kpi('Escalation response p90', 'p90 escalation response time vs the 30-minute waiver floor (trailing 7d).', 'min', 'down', 30, 25, 31, 120,
                'Home escalation response p90 {display} — 30-min waiver floor at risk'),
            'home.visit_compliance_today' => $this->kpi('Waiver visit compliance', 'Percent of due waiver-required visits completed today (≥2/day floor).', '%', 'up', 100, 95, 80, 120,
                'Waiver visit compliance {display} — below the AHCAH floor'),
            'home.device_offline_pct' => $this->kpi('Kits offline', 'Assigned RPM kits with a transmission gap over 60 minutes.', '%', 'down', 0, 10, null, 120,
                'Home RPM kits offline — {display} of assigned kits dark'),
            'home.rpm_adherence' => $this->kpi('Monitoring adherence', 'Received vs expected readings across active enrollments (6h window).', '%', 'up', 80, 70, null, 120),
            'home.escalation_rate_7d' => $this->kpi('Escalations / 100 episode-days', 'Escalations per 100 home episode-days, trailing 7d (national ≈6.2%).', '', 'down', 6.2, 8, null, 120, null, ['benchmark' => 6.2, 'benchmark_source' => 'Levine 2024']),
            'home.referral_conversion_7d' => $this->kpi('Referral conversion', 'Referrals activated as a percent of referrals received, trailing 7d (real programs ≈22%).', '%', 'up', 22, null, null, 120, null, ['benchmark' => 22, 'benchmark_source' => 'HaH Users Group']),
            'home.avoided_bed_days_mtd' => $this->kpi('Avoided bed-days MTD', 'Active home episode-days this month — occupied bed-days the house did not spend.', 'days', 'up', null, null, null, 120),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    private function kpi(
        string $label,
        string $definition,
        string $unit,
        string $direction,
        float|int|null $target,
        float|int|null $warn,
        float|int|null $crit,
        int $refreshSecs,
        ?string $alertTemplate = null,
        ?array $metadata = null,
        float|int|null $ok = null,
    ): array {
        return [
            'label' => $label,
            'definition' => $definition,
            'unit' => $unit,
            'direction' => $direction,
            'target_value' => $target,
            'ok_edge' => $ok,
            'warn_edge' => $warn,
            'crit_edge' => $crit,
            'refresh_secs' => $refreshSecs,
            'alert_template' => $alertTemplate,
            'cadence' => 'live',
            'metadata' => $metadata ?? [],
        ];
    }

    /**
     * OKR rows carry owner + objective (metadata) for the scorecard cards.
     *
     * @return array<string, mixed>
     */
    private function okr(
        string $label,
        string $definition,
        string $unit,
        string $direction,
        float|int $target,
        float|int|null $ok,
        float|int|null $warn,
        float|int|null $crit,
        string $owner,
        string $objective,
    ): array {
        return $this->kpi($label, $definition, $unit, $direction, $target, $warn, $crit, 300, null, ['objective' => $objective], $ok)
            + ['owner' => $owner];
    }
}
