<?php

namespace App\Nightingale\Demo;

use App\Models\Patient\PatientPrincipal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Code-owned, non-secret identity and catalog boundary for the synthetic
 * Nightingale investor-demo cohort.
 *
 * This manifest does not provision, activate, release, or authorize anything.
 * It gives authentication and the future provisioner one exact vocabulary.
 */
final class NightingaleDemoCohort
{
    public const OWNER = 'nightingale-demo-cohort-provisioner-v1';

    public const VERSION = 'nightingale-investor-demo-cohort-v1';

    public const PRODUCT = 'nightingale';

    public const ENVIRONMENT_CLASS = 'synthetic_investor_demo';

    public const DEMO_NOTICE = 'DEMO — NOT FOR CLINICAL USE';

    public const CATALOG_RELEASE_UUID = '019f95de-b9a4-726a-91d3-41d6d911d4c4';

    public const CATALOG_DATASET_KEY = 'drg-care-pathways-verification-package-v43.1-20260721';

    public const CATALOG_GROUPER_VERSION = '43.1';

    public const CATALOG_PATHWAY_COUNT = 250;

    public const RELEASE_POLICY_VERSION = 'nightingale-investor-demo-disclosure-v1';

    public const SOURCE_SYSTEM_KEY = 'nightingale-synthetic-demo';

    public const ENCRYPTION_KEY_VERSION = 'nightingale-demo-app-key-v1';

    public const PROJECTION_PRODUCER_VERSION = 'nightingale-investor-demo-projection-v1';

    public const REFERENCE_SAMPLE_OWNER = 'nightingale-reference-patient-provisioner-v1';

    public const REFERENCE_SAMPLE_PATIENT_REF = 'demo-nightingale-reference-inpatient';

    public const REFERENCE_SAMPLE_DISPLAY_NAME = 'Nightingale Reference Patient';

    public const REFERENCE_SAMPLE_MODE = 'operator-authorized-production-sample-clone';

    public const REFERENCE_SOURCE_PRODUCT = 'hummingbird_patient';

    public const REFERENCE_SOURCE_OWNER = 'hummingbird-patient-reference-identity-provisioner-v1';

    /**
     * @var array<string, array{
     *   display_name: string,
     *   patient_ref: string,
     *   pathway_key: string,
     *   ms_drg: string,
     *   drg_title: string,
     *   stage_count: int,
     *   milestone_count: int
     * }>
     */
    public const MEMBERS = [
        'demo1' => [
            'display_name' => 'Nightingale Demo Patient 1',
            'patient_ref' => self::REFERENCE_SAMPLE_PATIENT_REF,
            'pathway_key' => 'drgcp-heart-failure-671d63b4d61b',
            'ms_drg' => '293',
            'drg_title' => 'Heart Failure and Shock without CC/MCC',
            'stage_count' => 5,
            'milestone_count' => 41,
        ],
        'demo2' => [
            'display_name' => 'Nightingale Demo Patient 2',
            'patient_ref' => 'demo-nightingale-investor-02',
            'pathway_key' => 'drgcp-simple-pneumonia-pleurisy-337d0f29a350',
            'ms_drg' => '195',
            'drg_title' => 'Simple Pneumonia and Pleurisy without CC/MCC',
            'stage_count' => 5,
            'milestone_count' => 44,
        ],
        'demo3' => [
            'display_name' => 'Nightingale Demo Patient 3',
            'patient_ref' => 'demo-nightingale-investor-03',
            'pathway_key' => 'drgcp-major-joint-replacement-hipknee-lower-extremity-'.'a7fc97e65adc',
            'ms_drg' => '470',
            'drg_title' => 'Major Hip and Knee Joint Replacement or Reattachment of Lower Extremity without MCC',
            'stage_count' => 5,
            'milestone_count' => 49,
        ],
        'demo4' => [
            'display_name' => 'Nightingale Demo Patient 4',
            'patient_ref' => 'demo-nightingale-investor-04',
            'pathway_key' => 'drgcp-appendectomy-'.'5b2df7e00bf7',
            'ms_drg' => '399',
            'drg_title' => 'Appendix Procedures without CC/MCC',
            'stage_count' => 5,
            'milestone_count' => 36,
        ],
        'demo5' => [
            'display_name' => 'Nightingale Demo Patient 5',
            'patient_ref' => 'demo-nightingale-investor-05',
            'pathway_key' => 'drgcp-vaginal-delivery-'.'2fd506169d41',
            'ms_drg' => '807',
            'drg_title' => 'Vaginal Delivery without Sterilization or D&C without CC/MCC',
            'stage_count' => 5,
            'milestone_count' => 44,
        ],
    ];

    public static function isLoginAlias(string $value): bool
    {
        return isset(self::MEMBERS[$value]);
    }

    /** @return list<string> */
    public static function loginAliases(): array
    {
        return array_keys(self::MEMBERS);
    }

    /** @param array<string, mixed> $preferences */
    public static function preferencesAreOwned(array $preferences, ?string $alias = null): bool
    {
        $provisioning = $preferences['provisioning'] ?? null;
        if (! is_array($provisioning)
            || ($provisioning['product'] ?? null) !== self::PRODUCT
            || ($provisioning['environment_class'] ?? null) !== self::ENVIRONMENT_CLASS
            || ($provisioning['owner'] ?? null) !== self::OWNER
            || ($provisioning['cohort_version'] ?? null) !== self::VERSION
            || ($provisioning['clinical_use_permitted'] ?? null) !== false
            || ($provisioning['synthetic'] ?? null) !== true) {
            return false;
        }

        $candidate = $provisioning['demo_username'] ?? null;

        return is_string($candidate)
            && self::isLoginAlias($candidate)
            && ($alias === null || $candidate === $alias)
            && ($candidate !== 'demo1' || self::referenceSampleLineageIsExact($provisioning));
    }

    /** @param array<string, mixed> $provisioning */
    public static function referenceSampleLineageIsExact(array $provisioning): bool
    {
        $adoption = $provisioning['reference_sample_adoption'] ?? null;

        return is_array($adoption)
            && ($adoption['adopted_from_owner'] ?? null) === self::REFERENCE_SAMPLE_OWNER
            && ($adoption['adopted_patient_ref'] ?? null) === self::REFERENCE_SAMPLE_PATIENT_REF
            && ($adoption['source_template_product'] ?? null) === self::REFERENCE_SOURCE_PRODUCT
            && ($adoption['source_template_owner'] ?? null) === self::REFERENCE_SOURCE_OWNER
            && ($adoption['source_mode'] ?? null) === self::REFERENCE_SAMPLE_MODE;
    }

    /** @param array<string, mixed> $preferences */
    public static function disclosurePolicyVersionFor(array $preferences): ?string
    {
        return self::preferencesAreOwned($preferences)
            ? self::RELEASE_POLICY_VERSION
            : null;
    }

    /**
     * Restrict a principal query to one exact, command-owned synthetic alias.
     *
     * @param  Builder<PatientPrincipal>  $query
     * @return Builder<PatientPrincipal>
     */
    public static function constrainPrincipalQuery(Builder $query, string $alias): Builder
    {
        $query
            ->where('principal_type', 'patient')
            ->where('status', 'active')
            ->where('is_active', true)
            ->whereRaw("preferences #>> '{provisioning,product}' = ?", [self::PRODUCT])
            ->whereRaw("preferences #>> '{provisioning,environment_class}' = ?", [self::ENVIRONMENT_CLASS])
            ->whereRaw("preferences #>> '{provisioning,owner}' = ?", [self::OWNER])
            ->whereRaw("preferences #>> '{provisioning,cohort_version}' = ?", [self::VERSION])
            ->whereRaw("preferences #>> '{provisioning,demo_username}' = ?", [$alias])
            ->whereRaw("preferences #>> '{provisioning,clinical_use_permitted}' = ?", ['false'])
            ->whereRaw("preferences #>> '{provisioning,synthetic}' = ?", ['true']);

        if ($alias === 'demo1') {
            $query
                ->whereRaw(
                    "preferences #>> '{provisioning,reference_sample_adoption,adopted_from_owner}' = ?",
                    [self::REFERENCE_SAMPLE_OWNER],
                )
                ->whereRaw(
                    "preferences #>> '{provisioning,reference_sample_adoption,adopted_patient_ref}' = ?",
                    [self::REFERENCE_SAMPLE_PATIENT_REF],
                )
                ->whereRaw(
                    "preferences #>> '{provisioning,reference_sample_adoption,source_template_product}' = ?",
                    [self::REFERENCE_SOURCE_PRODUCT],
                )
                ->whereRaw(
                    "preferences #>> '{provisioning,reference_sample_adoption,source_template_owner}' = ?",
                    [self::REFERENCE_SOURCE_OWNER],
                )
                ->whereRaw(
                    "preferences #>> '{provisioning,reference_sample_adoption,source_mode}' = ?",
                    [self::REFERENCE_SAMPLE_MODE],
                );
        }

        return $query;
    }
}
